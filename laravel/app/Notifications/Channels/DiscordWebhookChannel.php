<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;

class DiscordWebhookChannel
{
    /**
     * Failures are deliberately not swallowed: every notification using this
     * channel is queued, so an exception here fails only this channel's job,
     * which the queue retries with backoff and finally records in
     * failed_jobs — instead of being logged once and lost.
     */
    public function send(object $notifiable, Notification $notification): void
    {
        $url = $notifiable->routeNotificationFor('discord', $notification);

        if (empty($url)) {
            return;
        }

        if (! method_exists($notification, 'toDiscord')) {
            return;
        }

        Http::post($url, ['content' => $notification->toDiscord($notifiable)])->throw();
    }
}
