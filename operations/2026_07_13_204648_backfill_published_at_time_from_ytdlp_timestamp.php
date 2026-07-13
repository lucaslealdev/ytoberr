<?php

use App\Models\Setting;
use App\Models\Video;
use App\Services\YtDlpWrapper;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
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
     * Videos were previously saved with published_at from yt-dlp's 'upload_date' field,
     * which is day-only (YYYYMMDD), collapsing every video into a fake midnight. yt-dlp
     * also reports a 'timestamp' field (Unix epoch) with the real publish time, which
     * CheckChannelsForNewVideos now uses for newly discovered videos. This backfills the
     * same field onto videos saved before that fix.
     */
    public function process(): void
    {
        $wrapper = app(YtDlpWrapper::class);
        $delay = Setting::ytdlpDelaySeconds();
        $isFirst = true;

        Video::whereRaw("time(published_at) = '00:00:00'")
            ->orderBy('id')
            ->chunkById(50, function ($videos) use ($wrapper, $delay, &$isFirst): void {
                foreach ($videos as $video) {
                    if (! $isFirst && $delay > 0) {
                        Sleep::for($delay)->seconds();
                    }
                    $isFirst = false;

                    $metadata = $wrapper->getMetadata(
                        'https://www.youtube.com/watch?v='.$video->youtube_id,
                        ['timestamp']
                    );

                    if (empty($metadata['timestamp'])) {
                        Log::warning("Backfill: could not fetch a yt-dlp timestamp for video {$video->youtube_id} (id {$video->id}); published_at left unchanged.");

                        continue;
                    }

                    $video->update([
                        'published_at' => Carbon::createFromTimestamp($metadata['timestamp'])->toDateTimeString(),
                    ]);
                }
            });
    }
};
