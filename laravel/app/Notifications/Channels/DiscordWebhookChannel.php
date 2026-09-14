<?php

namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DiscordWebhookChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        $url = $notifiable->routeNotificationFor('discord', $notification);

        if (empty($url)) {
            return;
        }

        if (! method_exists($notification, 'toDiscord')) {
            return;
        }

        try {
            Http::post($url, ['content' => $notification->toDiscord($notifiable)])->throw();
        } catch (\Exception $e) {
            report($e);
            Log::warning('Discord webhook delivery failed.', ['exception' => $e->getMessage()]);
        }
    }
}
