<?php

namespace App\Jobs;

use App\Models\Channel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

class CheckChannelForNewVideosJob implements ShouldQueue
{
    use Queueable;

    /**
     * app:check-channels makes a live-status precheck (90s) + a flat-playlist listing
     * (240s), then — since the flat-playlist optimization — one full per-video extraction
     * (240s) for each of up to 10 newly-discovered videos, with the configurable
     * ytdlp_delay_seconds slept (up to 120s) between every one of those calls. Worst case
     * (a channel's first check, discovering all 10 at once, at max delay) is
     * 90 + 120 + 240 + 10*(120+240) = 4050s, so this needs comfortable margin above that
     * instead of the queue worker's default 60s, which killed the job outright and
     * silently dropped the check.
     */
    public int $timeout = 4500;

    /**
     * Create a new job instance.
     */
    public function __construct(public Channel $channel)
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Artisan::call('app:check-channels', ['--channel' => $this->channel->id]);
    }
}
