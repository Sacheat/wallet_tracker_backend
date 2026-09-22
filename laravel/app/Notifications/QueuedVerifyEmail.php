<?php

namespace App\Notifications;

use App\Notifications\Concerns\QueuedWithRetries;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Laravel's stock verification email, sent through the queue so
 * register/resend don't wait on the mail provider. Inherits toMail() and the
 * static URL/mail callbacks unchanged.
 */
class QueuedVerifyEmail extends VerifyEmail implements ShouldQueue
{
    use QueuedWithRetries;
}
