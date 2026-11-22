<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

\Illuminate\Support\Facades\Schedule::command('tasks:notify-overdue')->dailyAt('08:00');
\Illuminate\Support\Facades\Schedule::command('tasks:purge-trash')->dailyAt('03:00');
