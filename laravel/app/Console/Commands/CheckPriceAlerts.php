<?php

namespace App\Console\Commands;

use App\Services\AlertService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('alerts:check')]
#[Description('Refresh the prices of every held or watched asset in batched calls, then evaluate every active alert')]
class CheckPriceAlerts extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AlertService $alertService): void
    {
        $alertService->refreshPrices();
        $alertService->checkAlerts();

        $this->info('Alerts checked.');
    }
}
