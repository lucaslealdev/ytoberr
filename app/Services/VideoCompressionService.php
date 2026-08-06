<?php

namespace App\Services;

use App\Jobs\CompressVideoJob;
use App\Models\Video;

class VideoCompressionService
{
    /**
     * Queue a downloaded video for background HEVC compression, either automatically right
     * after a successful download (when Setting::compressionEnabled() is on) or from the
     * manual "Optimize" button (available regardless of that setting). Silently no-ops for
     * anything not eligible (not yet downloaded, already HEVC, or a compression run already in
     * flight) so both callers can invoke this without duplicating the eligibility checks.
     */
    public function queue(Video $video): bool
    {
        if ($video->status !== 'completed' || ! $video->file_path) {
            return false;
        }

        if ($video->isHevc() || $video->isCompressing()) {
            return false;
        }

        $video->update(['compression_status' => 'queued', 'compression_progress_percent' => null, 'compression_error' => null]);

        CompressVideoJob::dispatch($video);

        return true;
    }
}
