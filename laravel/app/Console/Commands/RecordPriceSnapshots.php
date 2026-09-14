<?php

namespace App\Console\Commands;

use App\Services\AlertService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('snapshots:record')]
#[Description('Record a USD price snapshot for every held/watched asset, for percent-change alerts')]
class RecordPriceSnapshots extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(AlertService $alertService): void
    {
        $alertService->recordSnapshots();

        $this->info('Price snapshots recorded.');
    }
}
