<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Setting;
use App\Models\Video;
use App\Models\Warning;
use App\Services\YtDlpWrapper;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

#[Signature('app:check-channels {--channel= : Only check the given channel ID instead of every channel}')]
#[Description('Check for new videos in configured channels and queue downloads')]
class CheckChannelsForNewVideos extends Command
{
    /**
     * Upper bound for how long a channel's check-and-insert lock (see the per-channel
     * Cache::lock below) can be held before it auto-expires. Sized the same as
     * CheckChannelForNewVideosJob's timeout: a 90s live-status precheck + a 240s
     * flat-playlist listing, then one 240s full extraction for each of up to 10
     * newly-discovered videos with the configurable ytdlp_delay_seconds (up to 120s) slept
     * between every call — 90 + 120 + 240 + 10*(120+240) = 4050s worst case. This is only a
     * safety net for an ungraceful crash; the normal path releases the lock explicitly well
     * before this via the try/finally below.
     */
    private const CHANNEL_LOCK_TIMEOUT_SECONDS = 4500;

    public function handle()
    {
        $this->info('Checking for new videos...');
        $ytDlp = config('services.ytdlp_path', base_path('bin/yt-dlp'));
        $wrapper = app(YtDlpWrapper::class);

        $channels = $this->option('channel')
            ? Channel::where('id', $this->option('channel'))->get()
            : Channel::all();

        $delay = Setting::ytdlpDelaySeconds();
        $isFirstChannel = true;

        foreach ($channels as $channel) {
            // --sleep-requests only throttles requests *within* a single yt-dlp process; with
            // many channels, this loop fires a fresh yt-dlp process per channel back-to-back,
            // so the actual gap between channels has to be enforced here instead.
            if (! $isFirstChannel && $delay > 0) {
                Sleep::for($delay)->seconds();
            }
            $isFirstChannel = false;

            $this->info("Checking channel: {$channel->name}");

            // A manually-queued CheckChannelForNewVideosJob for this exact channel could be
            // running concurrently with this scheduled sweep — ShouldBeUnique on that job only
            // stops two *queued* instances of it from overlapping, and has no visibility into
            // this command's own synchronous run (whether that run got here via the scheduler
            // or via the job's own Artisan::call). Without this lock, both processes could pass
            // the "does this video already exist?" check below for the same new video before
            // either one's insert commits, and the second Video::create() would throw on the
            // unique constraint. Skip the channel this run rather than block on/race with
            // whichever other process already holds the lock.
            $lock = Cache::lock("check-channel-videos:{$channel->id}", self::CHANNEL_LOCK_TIMEOUT_SECONDS);

            if (! $lock->get()) {
                $this->warn("Skipping channel {$channel->name}: a check for this channel is already in progress.");

                continue;
            }

            try {
                // 1. Live_status precheck (based on Pinchflat) using the Wrapper
                $metadata = $wrapper->getMetadata($channel->url, ['live_status'], ['--playlist-items 1']);

                if ($metadata) {
                    $liveStatus = $metadata['live_status'] ?? null;

                    if (in_array($liveStatus, ['is_live', 'is_upcoming', 'post_live'])) {
                        $this->warn("Skipping channel {$channel->name} due to live_status: {$liveStatus}");

                        continue;
                    }
                }

                // Same reasoning as the between-channels sleep above: this is a second, separate
                // yt-dlp process for the same channel, so it needs its own gap from the first.
                if ($delay > 0) {
                    Sleep::for($delay)->seconds();
                }

                // 2. List the last 10 video IDs via a cheap flat-playlist listing. Unlike a full
                // dump, this doesn't visit each video's watch page (no JS signature solving, no
                // format list), so it's a single lightweight request regardless of how many of
                // those videos are already known.
                //
                // Channel URLs are stored as the bare handle (e.g. .../@channel), which yt-dlp
                // expands into *every* tab (Videos, Shorts, Live) as separate sub-playlists, each
                // independently capped by --playlist-items :10 — so the "last 10" easily balloons
                // past 10 and mixes in Shorts/streams that aren't part of the actual upload order.
                // Pointing at the /videos tab explicitly keeps this to a single, correctly-ordered
                // list of the channel's last 10 uploads.
                $videosTabUrl = rtrim($channel->url, '/');
                if (! Str::endsWith($videosTabUrl, '/videos')) {
                    $videosTabUrl .= '/videos';
                }
                $command = escapeshellarg($ytDlp).' --ignore-errors --flat-playlist -j --playlist-items :10 '.escapeshellarg($videosTabUrl).' 2>&1';
                [$output, $resultCode] = $wrapper->runCommand($command, 240);

                $candidateIds = [];
                foreach ($output as $jsonLine) {
                    $entry = json_decode($jsonLine, true);
                    if (! $entry || empty($entry['id'])) {
                        continue; // Skip warnings, errors, or plain text log lines
                    }
                    $candidateIds[] = $entry['id'];
                }

                if (empty($candidateIds) && $resultCode !== 0) {
                    $message = "Failed to check channel: {$channel->name}";
                    $this->error($message);
                    Warning::log('channel_check_failed', $message, implode("\n", $output));

                    continue;
                }

                $newVideoIds = array_filter(
                    $candidateIds,
                    fn (string $videoId) => ! Video::where('youtube_id', $videoId)->exists()
                );

                // 3. Only genuinely new videos need the expensive full extraction (was_live,
                // media_type, exact publish timestamp) — already-known videos are skipped before
                // ever paying that cost.
                $sleepArgs = $delay > 0 ? "--sleep-requests {$delay} " : '';
                foreach ($newVideoIds as $videoId) {
                    // Same reasoning as the other inter-request sleeps: each of these is its own
                    // yt-dlp process, separate from the flat-playlist listing call above and from
                    // each other.
                    if ($delay > 0) {
                        Sleep::for($delay)->seconds();
                    }

                    $videoUrl = "https://www.youtube.com/watch?v={$videoId}";
                    $command = escapeshellarg($ytDlp).' --ignore-errors -j '.$sleepArgs.escapeshellarg($videoUrl).' 2>&1';
                    [$output, $resultCode] = $wrapper->runCommand($command, 240);

                    $metadata = null;
                    foreach ($output as $jsonLine) {
                        $decoded = json_decode($jsonLine, true);
                        if ($decoded) {
                            $metadata = $decoded;

                            break;
                        }
                    }

                    if (! $metadata) {
                        $errorOutput = implode("\n", $output);
                        $this->error("Failed to fetch metadata for video: {$videoId}");

                        // Without persisting a row here, a permanently unavailable video (deleted,
                        // private, etc.) would never leave the "not yet known" candidate list, so
                        // every future run (every 3 hours, forever) would re-fetch and fail the
                        // same way — see the video_check_failed warning this used to spam.
                        $reason = Video::detectUnavailableReason($errorOutput);

                        if ($reason !== null) {
                            $video = Video::create([
                                'channel_id' => $channel->id,
                                'youtube_id' => $videoId,
                                'title' => "Unavailable video ({$videoId})",
                                'published_at' => now(),
                                'status' => 'failed',
                                'prevent_download' => true,
                                'unavailable_reason' => $reason,
                                'last_error' => 'Permanently unavailable: '.$reason,
                            ]);
                            $this->warn("Video {$videoId} marked as permanently unavailable: {$reason}");
                            Warning::log('video_check_failed', "Failed to fetch metadata for video: {$videoId}", $errorOutput, $video->id);
                        } else {
                            Warning::log('video_check_failed', "Failed to fetch metadata for video: {$videoId}", $errorOutput);
                        }

                        continue;
                    }

                    $wasLive = $metadata['was_live'] ?? false;
                    $mediaType = $metadata['media_type'] ?? null;

                    if ($mediaType === 'short' && ! $channel->download_shorts) {
                        $this->info("Skipping video {$videoId}: YouTube Short.");

                        continue;
                    }

                    if ($wasLive) {
                        $this->info("Skipping video {$videoId}: Originated from a live stream.");

                        continue;
                    }

                    // Prefer the Unix epoch 'timestamp' field, which carries the actual publish
                    // time; 'upload_date' is day-only (YYYYMMDD) and would otherwise collapse
                    // every video into a fake midnight, losing the real order of same-day uploads.
                    $publishedAt = isset($metadata['timestamp'])
                        ? Carbon::createFromTimestamp($metadata['timestamp'])
                        : (isset($metadata['upload_date'])
                            ? Carbon::parse($metadata['upload_date'])
                            : now());

                    // Check if the video was published before the channel's cut-off date (cutoff_date)

                    if ($channel->cutoff_date && $publishedAt->lt(Carbon::parse($channel->cutoff_date))) {
                        $this->info("Skipping video {$videoId}: published before channel cut-off date ({$channel->cutoff_date}).");

                        continue;
                    }

                    $this->info("New video found: {$videoId}. Adding to database download queue.");

                    try {
                        Video::create([
                            'channel_id' => $channel->id,
                            'youtube_id' => $videoId,
                            'title' => $metadata['title'] ?? 'Unknown Title',
                            'description' => $metadata['description'] ?? null,
                            'published_at' => $publishedAt->toDateTimeString(),
                            'duration' => $metadata['duration'] ?? null,
                            'status' => 'pending',
                        ]);
                    } catch (UniqueConstraintViolationException $e) {
                        // Defense in depth: the per-channel lock above closes the race between
                        // this command and a manually-queued job for the *same* channel, but it
                        // can't stop a video appearing twice within this very batch (e.g. a
                        // flat-playlist listing quirk), nor any other genuinely unforeseen race.
                        // Either way, one already-known duplicate should never crash the rest of
                        // this channel's — or the whole run's — processing.
                        $message = "Video {$videoId} was already inserted (likely a duplicate check-in-progress elsewhere); skipping.";
                        $this->warn($message);
                        Warning::log('video_duplicate_insert_skipped', $message, $e->getMessage());
                    }
                }
            } finally {
                $lock->release();
            }
        }
        $this->info('Done.');
    }
}
