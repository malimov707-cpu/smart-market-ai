<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('market:sync')->everyFiveMinutes()->withoutOverlapping(5);

Schedule::command('market:sync-construction')->everyFifteenMinutes()->withoutOverlapping(10);
