<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Agenda o Polling de novos vídeos nos canais para rodar a cada 3 horas
Schedule::command('app:check-channels')->everyThreeHours();

// Agenda o processamento resiliente da fila de downloads a cada 2 minutos
Schedule::command('videos:download')->everyTwoMinutes()->withoutOverlapping();


