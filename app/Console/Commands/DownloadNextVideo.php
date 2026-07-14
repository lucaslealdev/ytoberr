<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\Video;
use App\Services\PlexAssetService;
use App\Support\PlexNaming;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DownloadNextVideo extends Command
{
    protected $signature = 'videos:download';

    protected $description = 'Pulls the next pending video from the database queue and downloads it using yt-dlp, storing it alongside a companion thumbnail using Plex-friendly naming.';

    public function handle(PlexAssetService $plexAssets)
    {
        $this->info('Starting download queue processor...');

        // 1. Fetch next pending video
        $video = Video::with('channel')
            ->where('status', 'pending')
            ->where('prevent_download', false)
            ->orderBy('created_at', 'asc')
            ->first();

        if (! $video) {
            $this->info('No pending videos in the queue.');

            return 0;
        }

        $this->info("Processing video: {$video->title} (ID: {$video->youtube_id})");

        // 2. Mark as downloading
        $video->update(['status' => 'downloading']);

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
        ];

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

        $output = [];
        $resultCode = 0;
        exec($command, $output, $resultCode);

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

                return 1;
            }

            // Build target file path according to Plex conventions:
            // {downloads_dir}/{canal}/Season {YYYY}/{nome-do-arquivo}.{ext}
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
            copy($videoFile, $targetFile);

            // Build relative file path for database storage
            $relativePath = str_replace($downloadsDir.'/', '', $targetFile);

            // Copy thumbnail to destination folder as {nome-do-arquivo}.jpg: Plex's "Local Media
            // Assets" agent only recognizes an episode thumbnail that exactly matches the video's
            // own filename (just with an image extension instead), not a "-thumb" suffixed name.
            $hasThumb = file_exists($thumbFile);
            if ($hasThumb) {
                copy($thumbFile, $targetDir.'/'.$filename.'.jpg');
                $video->update(['thumbnail_path' => str_replace($downloadsDir.'/', '', $targetDir.'/'.$filename.'.jpg')]);
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
                'file_path' => $relativePath,
                'downloaded_at' => now(),
                'retries' => 0,
                'last_error' => null,
            ]);

            // Reset consecutive failures settings on success
            Setting::set('consecutive_failures', '0');

            $this->info("Successfully downloaded video: {$video->title}");
            $this->cleanup($tempDir);

            return 0;
        } else {
            // Download Failed! Handle resilient error catching
            $this->error("Download failed for video {$video->youtube_id}");
            $this->handleFailure($video, $rawOutput);
            $this->cleanup($tempDir);

            return 1;
        }
    }

    /**
     * Handle download failure (private/removed, cookies, retries, general queue suspensions)
     */
    private function handleFailure(Video $video, string $errorOutput): void
    {
        $errorOutputLower = strtolower($errorOutput);

        // 1. Check for permanently unavailable videos (Private, Removed, Unavailable)
        $isUnavailable = Str::contains($errorOutputLower, [
            'private video',
            'this video has been removed',
            'video unavailable',
            'this video is no longer available',
            'this video is unavailable',
            'members-only content',
        ]);

        if ($isUnavailable) {
            $reason = 'Video is private, removed or unavailable';
            if (Str::contains($errorOutputLower, 'private video')) {
                $reason = 'Private video';
            }
            if (Str::contains($errorOutputLower, 'removed')) {
                $reason = 'Video removed';
            }
            if (Str::contains($errorOutputLower, 'members-only')) {
                $reason = 'Members-only content';
            }

            $video->update([
                'status' => 'failed',
                'prevent_download' => true,
                'unavailable_reason' => $reason,
                'last_error' => 'Permanently unavailable: '.$reason,
            ]);

            Log::warning("Video {$video->youtube_id} marked as permanently unavailable: {$reason}");

            return;
        }

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
            $this->incrementConsecutiveFailures();

            return;
        }

        // 3. Handle Standard Retries
        if ($retries >= 3) {
            $video->update([
                'status' => 'failed',
                'retries' => $retries,
                'last_error' => 'Exceeded max download retries (3 times).',
            ]);
            Log::error("Video {$video->youtube_id} permanently failed after 3 attempts.");
        } else {
            $video->update([
                'status' => 'pending',
                'retries' => $retries,
                'last_error' => 'Temporary download error. Retrying later.',
            ]);
            Log::warning("Video {$video->youtube_id} failed. Attempt {$retries}/3. Returned to queue.");
        }

        $this->incrementConsecutiveFailures();
    }

    /**
     * Increment consecutive failure settings and suspend queue if count >= 3
     */
    private function incrementConsecutiveFailures(): void
    {
        $failures = (int) Setting::get('consecutive_failures', '0') + 1;
        Setting::set('consecutive_failures', (string) $failures);

        if ($failures >= 3) {
            Log::critical('3 consecutive downloads failed! Suspending all pending downloads in queue due to suspected generalized failure.');

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
}
