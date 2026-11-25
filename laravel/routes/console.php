<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Every five minutes: one batched call per provider (CoinGecko keyless,
// Yahoo by 20 tickers) refreshes every held or watched asset, then every
// alert is evaluated from that cache — near-live alerts at a cost that
// doesn't grow with users or alerts. withoutOverlapping: a slow provider
// never gets a second run started beside it.
Schedule::command('alerts:check')->everyFiveMinutes()->withoutOverlapping();

// The 7-day price history of the asset detail chart; hourly is enough for
// it. Declared after alerts:check so that on the hour, when both are due,
// it runs second and reads the prices alerts:check has just refreshed.
Schedule::command('snapshots:record')->hourly();

Schedule::command('digest:send daily')->dailyAt('08:00');
Schedule::command('digest:send weekly')->weeklyOn(1, '08:00');

// Queued mail/Discord notifications are processed by the permanent
// `queue:work` systemd service (deploy/systemd/wallet-tracker-queue.service).

// Failed notification jobs keep their serialized payload (recipient,
// alert, portfolio figures): 7 days is enough to investigate a delivery
// problem, and it's the retention the privacy policy states.
Schedule::command('queue:prune-failed --hours=168')->daily();
