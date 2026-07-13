<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\Video;
use App\Services\PlexAssetService;
use App\Support\PlexNaming;
use Illuminate\Console\Command;

class BackfillPlexAssets extends Command
{
    protected $signature = 'plex:backfill-assets';

    protected $description = 'Backfill tvshow.nfo, channel art, and per-video .nfo files for videos that were downloaded before Plex asset generation (or the current naming/numbering convention) existed, renaming video/thumbnail files as needed.';

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
        $renamedVideoCount = 0;
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

            // Rename the video/thumbnail to the current naming convention if they still use an
            // older one (missing upload_date_index, or the old "-thumb.jpg" suffix) — otherwise
            // videos from the same channel uploaded the same day collide into one Plex episode.
            $videoDir = dirname($fullVideoPath);
            $correctFilename = PlexNaming::filenameFor($video->channel, $video);
            $currentFilename = pathinfo($fullVideoPath, PATHINFO_FILENAME);

            if ($currentFilename !== $correctFilename) {
                $newVideoPath = $videoDir.'/'.$correctFilename.'.'.pathinfo($fullVideoPath, PATHINFO_EXTENSION);
                rename($fullVideoPath, $newVideoPath);

                $oldNfoPath = $videoDir.'/'.$currentFilename.'.nfo';
                if (file_exists($oldNfoPath)) {
                    unlink($oldNfoPath);
                }

                $video->file_path = str_replace($downloadsDir.'/', '', $newVideoPath);
                $fullVideoPath = $newVideoPath;
                $renamedVideoCount++;
            }

            if ($video->thumbnail_path && pathinfo($video->thumbnail_path, PATHINFO_FILENAME) !== $correctFilename) {
                $oldThumbPath = $downloadsDir.'/'.$video->thumbnail_path;
                if (file_exists($oldThumbPath)) {
                    $newThumbPath = $videoDir.'/'.$correctFilename.'.jpg';
                    rename($oldThumbPath, $newThumbPath);
                    $video->thumbnail_path = str_replace($downloadsDir.'/', '', $newThumbPath);
                    $renamedThumbCount++;
                }
            }

            if ($video->isDirty()) {
                $video->save();
            }

            [$year, $episode] = PlexNaming::seasonAndEpisode($video);
            $nfoPath = preg_replace('/\.[^.\/]+$/', '.nfo', $fullVideoPath);

            try {
                $plexAssets->writeVideoNfo($video, $nfoPath, $year, $episode);
                $videoCount++;
            } catch (\Throwable $e) {
                $this->error("Failed to write .nfo for {$video->title}: ".$e->getMessage());
                $skippedCount++;
            }
        }

        $this->info(
            'Backfill complete. '.count($syncedChannels)." channel(s) synced, {$videoCount} video .nfo file(s) written, "
            ."{$renamedVideoCount} video file(s) renamed, {$renamedThumbCount} thumbnail(s) renamed, {$skippedCount} skipped."
        );

        return 0;
    }
}
