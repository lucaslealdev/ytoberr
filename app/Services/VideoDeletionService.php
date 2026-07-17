<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Video;

class VideoDeletionService
{
    /**
     * Manually delete a video's database row and, optionally, its downloaded files. Backs
     * both the single-video "Delete Video" modal (VideoController::destroy) and the
     * multi-video Cleaning page, so the two independent, opt-in choices behave identically
     * everywhere they're offered:
     *
     * - $deleteFiles: also remove the downloaded video, thumbnail and .nfo from disk.
     * - $preventRedownload: keep the row as an invisible blacklist marker instead of removing
     *   it outright, so CheckChannelsForNewVideos's "already known" check keeps skipping it
     *   forever. Without this, the row is deleted entirely and a still-recent upload could be
     *   discovered and downloaded again by a future channel check.
     */
    public function delete(Video $video, bool $deleteFiles, bool $preventRedownload): void
    {
        if ($deleteFiles) {
            $this->deleteFilesFromDisk($video);
        }

        if ($preventRedownload) {
            // status=deleted keeps this row out of every listing/show query (all scoped to
            // status=completed) and out of the download queue (videos:download only pulls
            // status=pending), while the row's continued existence is exactly what stops
            // CheckChannelsForNewVideos from ever treating it as a new candidate again.
            $video->update([
                'status' => 'deleted',
                'file_path' => null,
                'file_size' => null,
                'thumbnail_path' => null,
                'downloaded_at' => null,
                'prevent_download' => true,
                'unavailable_reason' => 'Manually deleted',
            ]);
        } else {
            $video->delete();
        }
    }

    /**
     * Best-effort, opt-in removal of a single video's downloaded file, thumbnail and .nfo
     * metadata from disk. Same realpath()-containment technique as
     * ChannelController::deleteChannelFilesFromDisk(): anything that doesn't resolve to a real
     * path inside the configured downloads directory is silently skipped rather than deleted.
     */
    private function deleteFilesFromDisk(Video $video): void
    {
        $downloadsDir = realpath(Setting::getStoragePath());
        if (! $downloadsDir) {
            return;
        }

        $relativePaths = [$video->file_path, $video->thumbnail_path];
        if ($video->file_path) {
            // Plex "Local Media Assets" per-video .nfo shares the video's own filename, just
            // with a .nfo extension instead (see DownloadNextVideo/PlexAssetService::writeVideoNfo).
            $relativePaths[] = preg_replace('/\.[^.\/]+$/', '.nfo', $video->file_path);
        }

        foreach (array_filter($relativePaths) as $relativePath) {
            $fullPath = realpath($downloadsDir.'/'.$relativePath);

            if (! $fullPath || ! str_starts_with($fullPath, $downloadsDir.DIRECTORY_SEPARATOR)) {
                continue;
            }

            if (is_file($fullPath)) {
                unlink($fullPath);
            }
        }
    }
}
