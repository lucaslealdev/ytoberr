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
     * app:check-channels makes two separate yt-dlp calls (each individually capped by
     * YtDlpWrapper/CheckChannelsForNewVideos at 90s + 240s) with the configurable
     * ytdlp_delay_seconds sleep between them (up to 120s). Worst case that's 450s, so this
     * needs comfortable margin above that instead of the queue worker's default 60s, which
     * killed the job outright and silently dropped the check.
     */
    public int $timeout = 600;

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
