<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\PortfolioDigest;
use App\Services\PortfolioDigestService;
use App\Services\PortfolioService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('digest:send {frequency : daily or weekly}')]
#[Description('Send the portfolio digest notification to every user who has it enabled')]
class SendPortfolioDigest extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(PortfolioService $portfolioService, PortfolioDigestService $digestService): void
    {
        $frequency = $this->argument('frequency');

        if (! in_array($frequency, ['daily', 'weekly'])) {
            $this->error('frequency must be "daily" or "weekly".');

            return;
        }

        $column = $frequency === 'daily' ? 'daily_digest_enabled' : 'weekly_digest_enabled';

        $users = User::where($column, true)->get();
        $sent = 0;

        foreach ($users as $user) {
            $summary = $portfolioService->getPortfolioSummary($user);
            $content = $digestService->build($user, $summary, $frequency);

            // Nothing held (no position, or everything sold): nothing to report.
            if ($content === null) {
                continue;
            }

            $user->notify(new PortfolioDigest($summary, $frequency, $content));
            $sent++;
        }

        $this->info("Digest ({$frequency}) sent to {$sent} user(s).");
    }
}
