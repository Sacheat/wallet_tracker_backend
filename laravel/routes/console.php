<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Snapshots must run before the alert check so percent-change alerts see
// fresh data; hourly also matches the 1h alert window and keeps Alpha
// Vantage's stock lookups (25 req/day free tier) within budget.
Schedule::command('snapshots:record')->hourly();
Schedule::command('alerts:check')->hourly();

Schedule::command('digest:send daily')->dailyAt('08:00');
Schedule::command('digest:send weekly')->weeklyOn(1, '08:00');
