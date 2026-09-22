<?php

namespace App\Notifications\Concerns;

use Illuminate\Bus\Queueable;

/**
 * Shared queue behaviour for every notification that implements
 * ShouldQueue. Laravel dispatches one job per channel, so a Discord failure
 * retries on its own without re-sending the email.
 */
trait QueuedWithRetries
{
    use Queueable;

    public int $tries = 3;

    /**
     * Seconds to wait before the 2nd and 3rd attempt.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }
}
