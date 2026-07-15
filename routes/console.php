<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Ticks hourly and, per channel, only actually checks it once its own check_interval_hours
// (Channel::DEFAULT_CHECK_INTERVAL_HOURS = 3 if unset) has elapsed since last_checked_at —
// see Channel::isDueForCheck() and CheckChannelsForNewVideos::handle(). Hourly granularity
// lets per-channel intervals below the old fixed 3-hour cadence actually take effect.
Schedule::command('app:check-channels')->hourly();

// Trigger the download queue processor every 2 minutes. Each invocation now drains the
// pending queue (pacing itself between videos via ytdlp_delay_seconds) for up to
// DownloadNextVideo::MAX_RUNTIME_SECONDS, rather than handling a single video and exiting,
// so this interval mainly acts as a safety-net restart trigger. withoutOverlapping() still
// guards against a long-running invocation being started again before it finishes.
Schedule::command('videos:download')->everyTwoMinutes()->withoutOverlapping();
