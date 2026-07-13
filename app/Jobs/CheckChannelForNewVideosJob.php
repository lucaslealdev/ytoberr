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
     * app:check-channels makes two separate yt-dlp calls with the configurable
     * ytdlp_delay_seconds sleep between them (up to 120s), plus real network time for each
     * yt-dlp call itself. That routinely exceeds the queue worker's default 60s timeout,
     * which kills the job outright and silently drops the check — this must be generous
     * enough to cover the worst case instead.
     */
    public int $timeout = 300;

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
