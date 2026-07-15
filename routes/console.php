<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule polling for new videos across channels to run every 3 hours
Schedule::command('app:check-channels')->everyThreeHours();

// Trigger the download queue processor every 2 minutes. Each invocation now drains the
// pending queue (pacing itself between videos via ytdlp_delay_seconds) for up to
// DownloadNextVideo::MAX_RUNTIME_SECONDS, rather than handling a single video and exiting,
// so this interval mainly acts as a safety-net restart trigger. withoutOverlapping() still
// guards against a long-running invocation being started again before it finishes.
Schedule::command('videos:download')->everyTwoMinutes()->withoutOverlapping();
