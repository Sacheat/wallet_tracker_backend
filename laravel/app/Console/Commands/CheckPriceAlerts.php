<?php

namespace App\Console\Commands;

use App\Services\AlertService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('alerts:check')]
#[Description('Evaluate every active price alert and notify users whose conditions are met')]
class CheckPriceAlerts extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AlertService $alertService): void
    {
        $alertService->checkAlerts();

        $this->info('Alerts checked.');
    }
}
