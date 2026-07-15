<?php

use App\Models\Channel;
use Illuminate\Support\Facades\Storage;
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
    protected ?string $tag = 'channels';

    /**
     * Process the operation.
     *
     * Channels created before the banner_path/fanart_path columns existed may still have
     * banner.jpg/fanart.jpg files sitting under storage/app/public/channels/{id}/ from
     * ChannelService's original download, with no record of them on the channel row. The
     * views used to discover these with a live Storage::exists() check on every render;
     * this backfills the columns for pre-existing channels by checking disk once so that
     * check no longer needs to happen on every page load.
     */
    public function process(): void
    {
        Channel::orderBy('id')->chunkById(50, function ($channels): void {
            foreach ($channels as $channel) {
                $channelDir = 'channels/'.$channel->id;
                $bannerPath = $channelDir.'/banner.jpg';
                $fanartPath = $channelDir.'/fanart.jpg';

                $channel->update([
                    'banner_path' => Storage::disk('public')->exists($bannerPath) ? $bannerPath : null,
                    'fanart_path' => Storage::disk('public')->exists($fanartPath) ? $fanartPath : null,
                ]);
            }
        });
    }
};
