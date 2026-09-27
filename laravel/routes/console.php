<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Percent-change alerts and asset-detail change figures now come live from
// each provider (PercentChangeProviderInterface), not from these snapshots.
// This job only builds the 7-day price history used by the asset detail
// chart; hourly is a reasonable resolution for that chart.
Schedule::command('snapshots:record')->hourly();
Schedule::command('alerts:check')->hourly();

Schedule::command('digest:send daily')->dailyAt('08:00');
Schedule::command('digest:send weekly')->weeklyOn(1, '08:00');

// Failed notification jobs keep their serialized payload (recipient,
// alert, portfolio figures): 7 days is enough to investigate a delivery
// problem, and it's the retention the privacy policy states.
Schedule::command('queue:prune-failed --hours=168')->daily();
