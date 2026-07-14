<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule polling for new videos across channels to run every 3 hours
Schedule::command('app:check-channels')->everyThreeHours();

// Schedule resilient processing of the download queue every 2 minutes
Schedule::command('videos:download')->everyTwoMinutes()->withoutOverlapping();
