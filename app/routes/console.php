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

// Daily lifecycle nudge: users who signed up 3+ days ago and haven't
// launched a campaign get one (and only one) reminder about their unused
// $500 credit. Eligibility logic + idempotency live in the command.
Schedule::command('layout:send-campaign-reminders')
    ->dailyAt('09:30')
    ->onOneServer();

// Daily event refresh: REMOVED. No longer pulling external news feeds —
// brand-only ad generation. The four event-source services (Weather /
// Market / TechNews / Holiday) and the news_event_hooks table remain in
// the codebase but are no longer polled or queried at generation time.
