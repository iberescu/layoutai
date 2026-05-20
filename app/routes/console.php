<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Hourly top-up: any active campaign with fewer than 100 variants gets
// 70 more generated, image-prompted, HTML-built, and scored. Idempotent —
// safe to fire multiple times.
Schedule::command('layout:top-up-campaigns --target=100')
    ->hourly()
    ->withoutOverlapping(60)
    ->runInBackground();
