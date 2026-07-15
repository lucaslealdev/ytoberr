<?php

namespace App\Jobs;

use App\Models\Channel;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;

class CheckChannelForNewVideosJob implements ShouldBeUnique, ShouldQueue
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
     * Safety net matching $timeout above: if the job somehow never reaches completion or
     * failure (e.g. the worker process is killed outright) so the unique lock never gets
     * released normally, it still won't outlive the job it was guarding by more than a
     * negligible margin.
     */
    public int $uniqueFor = 4500;

    /**
     * Create a new job instance.
     */
    public function __construct(public Channel $channel)
    {
        //
    }

    /**
     * Unique per channel: Laravel's queue layer won't dispatch a second instance of this
     * job for the same channel while one is already queued/running, so repeat clicks of
     * "Check for New Videos" for the same channel can't both end up processing it — and
     * potentially duplicate-inserting its videos — at once.
     *
     * This only protects the queued-job path: it has no visibility into the scheduled
     * app:check-channels sweep, which runs synchronously across every channel rather than
     * through this job. That race is instead closed by the per-channel cache lock inside
     * CheckChannelsForNewVideos::handle() itself.
     */
    public function uniqueId(): string
    {
        return (string) $this->channel->id;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Artisan::call('app:check-channels', ['--channel' => $this->channel->id]);
    }
}
