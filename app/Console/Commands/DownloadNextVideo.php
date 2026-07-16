<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\Video;
use App\Models\Warning;
use App\Services\PlexAssetService;
use App\Services\YtDlpWrapper;
use App\Support\PlexNaming;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

class DownloadNextVideo extends Command
{
    protected $signature = 'videos:download';

    protected $description = 'Pulls the next pending video from the database queue and downloads it using yt-dlp, storing it alongside a companion thumbnail using Plex-friendly naming.';

    /**
     * Upper bound (in seconds) on how long a single invocation keeps draining the pending
     * queue. The schedule (routes/console.php) fires this command every 2 minutes; this
     * budget stays comfortably under that so a busy invocation reliably wraps up between
     * videos and hands control back to the scheduler, rather than one large backlog turning
     * into one never-ending process. It's only checked *between* videos — an in-progress
     * download is never interrupted mid-flight, it's bounded solely by its own 30-minute
     * command timeout further down. If a run does end up exceeding this because the last
     * video happened to take a while, that's fine: withoutOverlapping() (routes/console.php)
     * already guarantees the next scheduled tick simply skips itself instead of overlapping.
     */
    private const MAX_RUNTIME_SECONDS = 100;

    /**
     * Unique prefix for the progress lines emitted via --progress-template below, so they can
     * be picked out of yt-dlp's much noisier general output (format selection, merger/ffmpeg
     * lines, etc.) with a simple substring check before the percentage regex even runs.
     */
    private const PROGRESS_MARKER = 'YTOBERR_PROGRESS';

    public function handle(PlexAssetService $plexAssets, YtDlpWrapper $ytDlpWrapper)
    {
        $this->info('Starting download queue processor...');

        $startedAt = now();
        $processedCount = 0;

        while (true) {
            // 1. Fetch next pending video
            $video = Video::with('channel')
                ->where('status', 'pending')
                ->where('prevent_download', false)
                ->orderBy('created_at', 'asc')
                ->first();

            if (! $video) {
                $this->info($processedCount > 0 ? 'No more pending videos in the queue.' : 'No pending videos in the queue.');

                break;
            }

            if ($processedCount > 0) {
                if ($startedAt->diffInSeconds(now()) >= self::MAX_RUNTIME_SECONDS) {
                    $this->info('Reached this invocation\'s time budget; leaving the rest of the queue for the next scheduled run.');

                    break;
                }

                // Sleep between videos, same reasoning as the between-channels/requests sleeps
                // in CheckChannelsForNewVideos: --sleep-requests only throttles requests
                // *within* a single yt-dlp process, so pacing across separate downloads has to
                // be an explicit sleep here in the loop.
                $delay = Setting::ytdlpDelaySeconds();
                if ($delay > 0) {
                    Sleep::for($delay)->seconds();
                }
            }

            $this->processVideo($video, $plexAssets, $ytDlpWrapper);
            $processedCount++;
        }

        if ($processedCount > 0) {
            $this->info("Done. Processed {$processedCount} video(s) this run.");
        }

        return 0;
    }

    /**
     * Download and file away a single video: the entire lifecycle from "downloading" through
     * either "completed" or a handled failure. Broken out from handle() so the queue-draining
     * loop there can call this once per pending video without any single video's outcome
     * (success or failure) terminating the whole command.
     */
    private function processVideo(Video $video, PlexAssetService $plexAssets, YtDlpWrapper $ytDlpWrapper): void
    {
        $this->info("Processing video: {$video->title} (ID: {$video->youtube_id})");

        // 2. Mark as downloading
        $video->update(['status' => 'downloading', 'progress_percent' => 0]);

        // 3. Create temp directory
        $tempDir = storage_path('app/temp/'.Str::random(16));
        mkdir($tempDir, 0755, true);

        $ytDlp = config('services.ytdlp_path', base_path('bin/yt-dlp'));

        // Resolve download quality format
        $quality = $video->channel->download_quality ?? '720p';
        $heightLimit = 720;
        if ($quality === '1080p') {
            $heightLimit = 1080;
        }
        if ($quality === '480p') {
            $heightLimit = 480;
        }

        $formatString = "-f \"bv*[height<={$heightLimit}]+ba/b[height<={$heightLimit}]\"";

        // Template and temporary paths
        $outputTemplate = $tempDir.'/video.%(ext)s';
        $infoJsonPath = $tempDir.'/video.info.json';

        // Base yt-dlp arguments
        $arguments = [
            $formatString,
            '--write-thumbnail',
            '--convert-thumbnails jpg',
            '--write-info-json',
            '--output '.escapeshellarg($outputTemplate),
            // --newline forces one progress update per line (instead of \r-overwriting a single
            // line, which assumes an interactive terminal); the custom template gives us a
            // marker prefix plus just the percentage, so it's cheap to pick out below without
            // parsing yt-dlp's much busier default progress line.
            '--newline',
            '--progress-template '.escapeshellarg('download:'.self::PROGRESS_MARKER.' %(progress._percent_str)s'),
        ];

        // Sleep between requests/downloads to avoid triggering YouTube's IP rate-limiting.
        $delay = Setting::ytdlpDelaySeconds();
        if ($delay > 0) {
            $arguments[] = '--sleep-requests '.$delay;
            $arguments[] = '--sleep-interval '.$delay;
        }

        // Cookies support if present
        $cookiePath = storage_path('app/cookies.txt');
        if (file_exists($cookiePath)) {
            $arguments[] = '--cookies '.escapeshellarg($cookiePath);
        }

        $argumentsString = implode(' ', $arguments);
        $url = 'https://www.youtube.com/watch?v='.$video->youtube_id;
        $command = "{$ytDlp} {$argumentsString} ".escapeshellarg($url).' 2>&1';

        $this->info('Running yt-dlp download...');
        Log::info('DownloadNextVideo executing: '.$command);

        // 30 minutes: generous enough for a large video over a slow connection, while still
        // guaranteeing a hung download gets killed outright instead of blocking this command
        // (and the every-2-minutes schedule behind it, via withoutOverlapping()) forever.
        [$output, $resultCode] = $ytDlpWrapper->runCommand($command, 1800, $this->progressWriter($video));

        $rawOutput = implode("\n", $output);

        if ($resultCode === 0) {
            // Success! Move the downloaded files into place as-is (no transcoding/remuxing).
            $this->info('Download succeeded! Resolving files...');

            // Find downloaded video file in tempDir (e.g. video.mp4, video.mkv, video.webm)
            $videoFiles = glob($tempDir.'/video.*');
            $videoFile = null;
            $thumbFile = $tempDir.'/video.jpg'; // Converted to jpg

            foreach ($videoFiles as $file) {
                $ext = pathinfo($file, PATHINFO_EXTENSION);
                if (in_array($ext, ['mp4', 'webm', 'mkv', 'flv', 'avi', 'ts', '3gp'])) {
                    $videoFile = $file;
                    break;
                }
            }

            if (! $videoFile || ! file_exists($videoFile)) {
                $this->error('Downloaded video file not found in temp directory.');
                $video->update([
                    'status' => 'failed',
                    'last_error' => 'Video file not found after download.',
                ]);
                $this->handleFailure($video, 'Video file not found after download.');
                $this->cleanup($tempDir);

                return;
            }

            // Build target file path according to Plex conventions:
            // {downloads_dir}/{channel}/Season {YYYY}/{filename}.{ext}
            $downloadsDir = Setting::getStoragePath();
            $safeChannelName = PlexNaming::sanitize($video->channel->name);
            [$year, $episode] = PlexNaming::seasonAndEpisode($video);

            // Plex target filename: {channel_name} - s{year}e{episode} - {title} [{id}].{ext}
            // (episode = upload month+day plus the video's upload_date_index, which keeps
            // same-day siblings from the same channel from colliding into one Plex episode)
            $filename = PlexNaming::filenameFor($video->channel, $video);

            $targetDir = $downloadsDir.'/'.$safeChannelName.'/Season '.$year;
            if (! file_exists($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            $ext = pathinfo($videoFile, PATHINFO_EXTENSION);
            $targetFile = $targetDir.'/'.$filename.'.'.$ext;

            // @-suppressed: copy() emits an E_WARNING on failure that Laravel's error handler
            // turns into an uncaught ErrorException, which would crash the command before our
            // own (deliberate, more informative) handling below ever runs.
            if (! @copy($videoFile, $targetFile)) {
                // Without this check, a failed copy (disk full, permissions, ...) still left
                // the video marked "completed" pointing at a missing/corrupt file, silently.
                $message = "Failed to copy the downloaded file into place for \"{$video->title}\" ({$video->youtube_id}) — check disk space and permissions.";
                $this->error($message);
                Log::error($message);
                Warning::log('download_copy_failed', $message, "Source: {$videoFile}\nDestination: {$targetFile}", $video->id);
                $this->handleFailure($video, 'Failed to copy the downloaded file into the destination directory.');
                $this->cleanup($tempDir);

                return;
            }

            // Build relative file path for database storage
            $relativePath = str_replace($downloadsDir.'/', '', $targetFile);

            // Copy thumbnail to destination folder as {filename}.jpg: Plex's "Local Media
            // Assets" agent only recognizes an episode thumbnail that exactly matches the video's
            // own filename (just with an image extension instead), not a "-thumb" suffixed name.
            // Best-effort: a missing thumbnail shouldn't fail an otherwise-successful download.
            $hasThumb = file_exists($thumbFile);
            if ($hasThumb) {
                if (@copy($thumbFile, $targetDir.'/'.$filename.'.jpg')) {
                    $video->update(['thumbnail_path' => str_replace($downloadsDir.'/', '', $targetDir.'/'.$filename.'.jpg')]);
                } else {
                    $message = "Failed to copy the thumbnail into place for \"{$video->title}\" ({$video->youtube_id}).";
                    Log::warning($message);
                    Warning::log('download_thumbnail_copy_failed', $message);
                }
            }

            // Write Plex local assets (tvshow.nfo + channel art, per-video .nfo).
            // Best-effort: a metadata write hiccup shouldn't turn a successful download into a failure.
            try {
                $channelDir = $downloadsDir.'/'.$safeChannelName;
                $plexAssets->syncChannelAssets($video->channel, $channelDir);
                $plexAssets->writeVideoNfo($video, $targetDir.'/'.$filename.'.nfo', $year, $episode);
            } catch (\Throwable $e) {
                Log::warning("Failed to write Plex metadata assets for video {$video->youtube_id}: ".$e->getMessage());
            }

            // 5. Update Database Record
            $video->update([
                'status' => 'completed',
                'progress_percent' => 100,
                'file_path' => $relativePath,
                'file_size' => filesize($targetFile) ?: null,
                'downloaded_at' => now(),
                'retries' => 0,
                'last_error' => null,
            ]);

            // Reset consecutive failures settings on success
            Setting::set('consecutive_failures', '0');

            $this->info("Successfully downloaded video: {$video->title}");
            $this->cleanup($tempDir);
        } else {
            // Download Failed! Handle resilient error catching
            $this->error("Download failed for video {$video->youtube_id}");
            $this->handleFailure($video, $rawOutput);
            $this->cleanup($tempDir);
        }
    }

    /**
     * Handle download failure (private/removed, cookies, retries, general queue suspensions)
     */
    private function handleFailure(Video $video, string $errorOutput): void
    {
        // 1. Check for permanently unavailable videos (Private, Removed, Unavailable)
        $reason = Video::detectUnavailableReason($errorOutput);

        if ($reason !== null) {
            $video->update([
                'status' => 'failed',
                'prevent_download' => true,
                'unavailable_reason' => $reason,
                'last_error' => 'Permanently unavailable: '.$reason,
            ]);

            Log::warning("Video {$video->youtube_id} marked as permanently unavailable: {$reason}");

            return;
        }

        $errorOutputLower = strtolower($errorOutput);

        // 2. Check for Cookie/Authentication issues
        $needsCookies = Str::contains($errorOutputLower, [
            'sign in to confirm',
            'confirm your age',
            'available to this channel\'s members',
        ]);

        // Increment retry count
        $retries = $video->retries + 1;

        if ($needsCookies) {
            Log::warning("Authentication issue detected for {$video->youtube_id}. Moving video to end of queue to retry later.");

            if ($retries >= 3) {
                $video->update([
                    'status' => 'failed',
                    'retries' => $retries,
                    'last_error' => 'Requires cookies/authentication (exceeded 3 retries).',
                ]);
            } else {
                // Move video to the end of the queue (update created_at and set back to pending)
                $video->update([
                    'status' => 'pending',
                    'retries' => $retries,
                    'last_error' => 'Requires authentication/cookies. Moved to back of queue.',
                    'created_at' => now(),
                ]);
            }
            $this->incrementConsecutiveFailures($errorOutput);

            return;
        }

        // 3. Handle Standard Retries
        if ($retries >= 3) {
            $video->update([
                'status' => 'failed',
                'retries' => $retries,
                'last_error' => 'Exceeded max download retries (3 times).',
            ]);
            $message = "Video {$video->youtube_id} permanently failed after 3 attempts.";
            Log::error($message);
            Warning::log('download_failed_permanently', $message, "Video: {$video->title}\n\n{$errorOutput}", $video->id);
        } else {
            $video->update([
                'status' => 'pending',
                'retries' => $retries,
                'last_error' => 'Temporary download error. Retrying later.',
            ]);
            Log::warning("Video {$video->youtube_id} failed. Attempt {$retries}/3. Returned to queue.");
        }

        $this->incrementConsecutiveFailures($errorOutput);
    }

    /**
     * Increment consecutive failure settings and suspend queue if count >= 3
     */
    private function incrementConsecutiveFailures(?string $context = null): void
    {
        $failures = (int) Setting::get('consecutive_failures', '0') + 1;
        Setting::set('consecutive_failures', (string) $failures);

        if ($failures >= 3) {
            $message = '3 consecutive downloads failed! Suspending all pending downloads in queue due to suspected generalized failure.';
            Log::critical($message);
            Warning::log('queue_suspended', $message, $context);

            // Mark all remaining pending videos in the queue as failed
            Video::where('status', 'pending')
                ->where('prevent_download', false)
                ->update([
                    'status' => 'failed',
                    'last_error' => 'Queue suspended: 3 consecutive downloads failed. Suspected generalized outage or IP ban.',
                ]);
        }
    }

    /**
     * Clean up temporary directory and files
     */
    private function cleanup(string $dir): void
    {
        if (file_exists($dir)) {
            exec('rm -rf '.escapeshellarg($dir));
        }
    }

    /**
     * Build a YtDlpWrapper output callback that keeps $video->progress_percent roughly in sync
     * with yt-dlp's real download progress, for display in the Processes page's "Live Activity".
     *
     * Deliberately throttled to a write only every 5 percentage points (or on hitting 100%)
     * instead of on every progress line — yt-dlp emits several updates per second, and a single
     * "downloading" video already holds this command's undivided attention, so there's no value
     * (and real SQLite write overhead) in persisting every single one of them.
     *
     * A format like "bv*+ba" downloads video and audio as two separate sequential streams, each
     * independently reported 0-100% by yt-dlp — so this can legitimately jump back down once
     * partway through (video finishes at 100%, audio then restarts from 0%). That's treated as
     * a big-enough move to write immediately, same as any other >=5-point change.
     */
    private function progressWriter(Video $video): callable
    {
        $lastSavedPercent = -1;

        return function (string $type, string $buffer) use ($video, &$lastSavedPercent) {
            foreach (preg_split('/\r\n|\r|\n/', $buffer) as $line) {
                if (! str_contains($line, self::PROGRESS_MARKER)) {
                    continue;
                }

                if (! preg_match('/([\d.]+)\s*%/', $line, $matches)) {
                    continue;
                }

                $percent = max(0, min(100, (int) round((float) $matches[1])));

                if (abs($percent - $lastSavedPercent) < 5 && $percent !== 100) {
                    continue;
                }

                $lastSavedPercent = $percent;
                $video->update(['progress_percent' => $percent]);
            }
        };
    }
}
