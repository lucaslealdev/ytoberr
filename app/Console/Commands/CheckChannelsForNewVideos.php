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
use Illuminate\Support\Sleep;

#[Signature('app:check-channels {--channel= : Only check the given channel ID instead of every channel}')]
#[Description('Check for new videos in configured channels and queue downloads')]
class CheckChannelsForNewVideos extends Command
{
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

            // 2. Fetch new videos (IDs)
            $output = [];
            $resultCode = 0;
            // Use --ignore-errors and -j to get metadata of the last 10 videos including 'was_live'
            $sleepArgs = $delay > 0 ? "--sleep-requests {$delay} " : '';
            $command = escapeshellarg($ytDlp).' --ignore-errors -j '.$sleepArgs.'--playlist-items :10 '.escapeshellarg($channel->url).' 2>&1';
            exec($command, $output, $resultCode);

            $processedCount = 0;
            foreach ($output as $jsonLine) {
                $metadata = json_decode($jsonLine, true);
                if (! $metadata) {
                    continue; // Skip warnings, errors, or plain text log lines
                }

                $videoId = $metadata['id'] ?? null;
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

                // Check if the video was published before the channel's cut-off date (cutoff_date)
                $publishedAt = isset($metadata['upload_date'])
                    ? Carbon::parse($metadata['upload_date'])
                    : now();

                if ($channel->cutoff_date && $publishedAt->lt(Carbon::parse($channel->cutoff_date))) {
                    $this->info("Skipping video {$videoId}: published before channel cut-off date ({$channel->cutoff_date}).");

                    continue;
                }

                if (! empty($videoId) && ! Video::where('youtube_id', $videoId)->exists()) {
                    $this->info("New video found: {$videoId}. Adding to database download queue.");
                    Video::create([
                        'channel_id' => $channel->id,
                        'youtube_id' => $videoId,
                        'title' => $metadata['title'] ?? 'Unknown Title',
                        'description' => $metadata['description'] ?? null,
                        'published_at' => $publishedAt->toDateTimeString(),
                        'status' => 'pending',
                    ]);
                }
                $processedCount++;
            }

            if ($processedCount === 0 && $resultCode !== 0) {
                $message = "Failed to check channel: {$channel->name}";
                $this->error($message);
                Warning::log('channel_check_failed', $message, implode("\n", $output));
            }
        }
        $this->info('Done.');
    }
}
