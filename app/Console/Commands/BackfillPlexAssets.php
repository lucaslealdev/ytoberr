<?php

namespace App\Console\Commands;

use App\Models\Video;
use App\Models\Setting;
use App\Services\PlexAssetService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class BackfillPlexAssets extends Command
{
    protected $signature = 'plex:backfill-assets';
    protected $description = 'Backfill tvshow.nfo, channel art, and per-video .nfo files for videos that were downloaded before Plex asset generation was implemented.';

    public function handle(PlexAssetService $plexAssets)
    {
        $downloadsDir = Setting::getStoragePath();

        $videos = Video::with('channel')
            ->where('status', 'completed')
            ->whereNotNull('file_path')
            ->get();

        if ($videos->isEmpty()) {
            $this->info('No completed videos found to backfill.');
            return 0;
        }

        $syncedChannels = [];
        $videoCount = 0;
        $skippedCount = 0;

        foreach ($videos as $video) {
            if (!$video->channel) {
                $this->warn("Skipping video {$video->youtube_id}: channel no longer exists.");
                $skippedCount++;
                continue;
            }

            $fullVideoPath = $downloadsDir . '/' . $video->file_path;
            if (!file_exists($fullVideoPath)) {
                $this->warn("Skipping {$video->title}: file not found at {$fullVideoPath}");
                $skippedCount++;
                continue;
            }

            // {channelDir}/Season {YYYY}/{filename}.{ext}
            $channelDir = dirname(dirname($fullVideoPath));

            if (!isset($syncedChannels[$video->channel_id])) {
                try {
                    $plexAssets->syncChannelAssets($video->channel, $channelDir);
                    $syncedChannels[$video->channel_id] = true;
                    $this->info("Synced channel assets for: {$video->channel->name}");
                } catch (\Throwable $e) {
                    $this->error("Failed to sync channel assets for {$video->channel->name}: " . $e->getMessage());
                }
            }

            $publishedAt = Carbon::parse($video->published_at);
            $nfoPath = preg_replace('/\.[^.\/]+$/', '.nfo', $fullVideoPath);
            $thumbFilename = $video->thumbnail_path ? basename($video->thumbnail_path) : null;

            try {
                $plexAssets->writeVideoNfo($video, $nfoPath, $publishedAt->year, $publishedAt->format('md'), $thumbFilename);
                $videoCount++;
            } catch (\Throwable $e) {
                $this->error("Failed to write .nfo for {$video->title}: " . $e->getMessage());
                $skippedCount++;
            }
        }

        $this->info("Backfill complete. " . count($syncedChannels) . " channel(s) synced, {$videoCount} video .nfo file(s) written, {$skippedCount} skipped.");

        return 0;
    }
}
