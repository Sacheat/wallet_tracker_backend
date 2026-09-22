<?php

namespace App\Notifications;

use App\Notifications\Concerns\QueuedWithRetries;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Laravel's stock reset-link email, sent through the queue. Also shrinks the
 * timing gap /forgot-password had between a known and an unknown address
 * (only the former used to wait on the mail provider); a smaller one remains
 * because the broker still hashes a token for a known account.
 * ResetPassword::createUrlUsing() in AppServiceProvider is a static on the
 * parent class, so the SPA reset link keeps working unchanged.
 */
class QueuedResetPassword extends ResetPassword implements ShouldQueue
{
    use QueuedWithRetries;
}
