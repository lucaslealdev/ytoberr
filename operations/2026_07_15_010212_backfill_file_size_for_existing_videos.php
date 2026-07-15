<?php

use App\Models\Setting;
use App\Models\Video;
use Illuminate\Support\Facades\Log;
use TimoKoerber\LaravelOneTimeOperations\OneTimeOperation;

return new class extends OneTimeOperation
{
    /**
     * Determine if the operation is being processed asynchronously.
     */
    protected bool $async = true;

    /**
     * The queue that the job will be dispatched to.
     */
    protected string $queue = 'default';

    /**
     * A tag name, that this operation can be filtered by.
     */
    protected ?string $tag = 'videos';

    /**
     * Process the operation.
     *
     * Videos downloaded before the `file_size` column existed have it stored as null, which
     * would otherwise force Video::fileSize()/Channel::totalDownloadedBytes() to fall back to a
     * live filesystem stat forever. This backfills the column once from the file already on
     * disk, so those code paths can rely on the cached value going forward the same way
     * anything downloaded after the column was introduced already does.
     */
    public function process(): void
    {
        $downloadsDir = Setting::getStoragePath();

        Video::whereNotNull('file_path')
            ->whereNull('file_size')
            ->orderBy('id')
            ->chunkById(50, function ($videos) use ($downloadsDir): void {
                foreach ($videos as $video) {
                    $fullPath = $downloadsDir.'/'.$video->file_path;

                    if (! file_exists($fullPath)) {
                        Log::warning("Backfill: file not found on disk for video {$video->youtube_id} (id {$video->id}); file_size left unchanged.");

                        continue;
                    }

                    $video->update(['file_size' => filesize($fullPath) ?: null]);
                }
            });
    }
};
