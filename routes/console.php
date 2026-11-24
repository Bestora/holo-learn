<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Incremental runs stop at the first known page; the weekly full run catches gaps (aborted runs, songs added to old videos)
// and covers Sunday, so the two never run side by side (each has its own overlap lock).
Schedule::command('holodex:sync')->dailyAt('03:30')->days('1-6')->withoutOverlapping();
Schedule::command('holodex:sync --full')->weeklyOn(0, '04:30')->withoutOverlapping();
