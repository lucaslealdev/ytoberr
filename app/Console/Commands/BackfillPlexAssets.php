<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\Video;
use App\Services\PlexAssetService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class BackfillPlexAssets extends Command
{
    protected $signature = 'plex:backfill-assets';

    protected $description = 'Backfill tvshow.nfo, channel art, and per-video .nfo files for videos that were downloaded before Plex asset generation was implemented, and rename any thumbnail still using the old "-thumb.jpg" suffix that Plex does not recognize.';

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
        $renamedThumbCount = 0;
        $skippedCount = 0;

        foreach ($videos as $video) {
            if (! $video->channel) {
                $this->warn("Skipping video {$video->youtube_id}: channel no longer exists.");
                $skippedCount++;

                continue;
            }

            $fullVideoPath = $downloadsDir.'/'.$video->file_path;
            if (! file_exists($fullVideoPath)) {
                $this->warn("Skipping {$video->title}: file not found at {$fullVideoPath}");
                $skippedCount++;

                continue;
            }

            // {channelDir}/Season {YYYY}/{filename}.{ext}
            $channelDir = dirname(dirname($fullVideoPath));

            if (! isset($syncedChannels[$video->channel_id])) {
                try {
                    $plexAssets->syncChannelAssets($video->channel, $channelDir);
                    $syncedChannels[$video->channel_id] = true;
                    $this->info("Synced channel assets for: {$video->channel->name}");
                } catch (\Throwable $e) {
                    $this->error("Failed to sync channel assets for {$video->channel->name}: ".$e->getMessage());
                }
            }

            // Plex's "Local Media Assets" agent only recognizes an episode thumbnail that exactly
            // matches the video's own filename, so rename any thumbnail still using the old
            // "-thumb.jpg" suffix.
            if ($video->thumbnail_path && preg_match('/-thumb\.jpg$/', $video->thumbnail_path)) {
                $oldThumbPath = $downloadsDir.'/'.$video->thumbnail_path;
                $newThumbnailPath = preg_replace('/-thumb\.jpg$/', '.jpg', $video->thumbnail_path);

                if (file_exists($oldThumbPath)) {
                    rename($oldThumbPath, $downloadsDir.'/'.$newThumbnailPath);
                    $renamedThumbCount++;
                }

                $video->update(['thumbnail_path' => $newThumbnailPath]);
            }

            $publishedAt = Carbon::parse($video->published_at);
            $nfoPath = preg_replace('/\.[^.\/]+$/', '.nfo', $fullVideoPath);

            try {
                $plexAssets->writeVideoNfo($video, $nfoPath, $publishedAt->year, $publishedAt->format('md'));
                $videoCount++;
            } catch (\Throwable $e) {
                $this->error("Failed to write .nfo for {$video->title}: ".$e->getMessage());
                $skippedCount++;
            }
        }

        $this->info('Backfill complete. '.count($syncedChannels)." channel(s) synced, {$videoCount} video .nfo file(s) written, {$renamedThumbCount} thumbnail(s) renamed, {$skippedCount} skipped.");

        return 0;
    }
}
