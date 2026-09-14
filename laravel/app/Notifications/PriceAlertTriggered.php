<?php

namespace App\Notifications;

use App\Enums\AlertType;
use App\Models\PriceAlert;
use App\Notifications\Channels\DiscordWebhookChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PriceAlertTriggered extends Notification
{
    /**
     * @param  float  $currentValue  The current price (asset alerts) or portfolio total (portfolio alerts).
     * @param  float|null  $percentChange  Only set for AlertType::PercentChange.
     */
    public function __construct(
        public PriceAlert $alert,
        public float $currentValue,
        public ?float $percentChange = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = [];

        if ($notifiable->email_notifications_enabled) {
            $channels[] = 'mail';
        }

        if (! empty($notifiable->discord_webhook_url)) {
            $channels[] = DiscordWebhookChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Crypto Portfolio Tracker — Alerte déclenchée')
            ->line($this->message());
    }

    public function toDiscord(object $notifiable): string
    {
        return '🔔 '.$this->message();
    }

    private function message(): string
    {
        $alert = $this->alert;

        return match ($alert->type) {
            AlertType::PriceAbove => sprintf(
                'Le prix de %s (%s) est passé au-dessus de %s $ (actuellement %s $).',
                $alert->asset->name, $alert->asset->symbol, $alert->threshold, number_format($this->currentValue, 2)
            ),
            AlertType::PriceBelow => sprintf(
                'Le prix de %s (%s) est passé en dessous de %s $ (actuellement %s $).',
                $alert->asset->name, $alert->asset->symbol, $alert->threshold, number_format($this->currentValue, 2)
            ),
            AlertType::PercentChange => sprintf(
                '%s (%s) a varié de %s%% sur %s (seuil : ±%s%%).',
                $alert->asset->name, $alert->asset->symbol,
                number_format($this->percentChange, 2), $alert->window->value, $alert->percent_threshold
            ),
            AlertType::PortfolioTotalAbove => sprintf(
                'La valeur totale de votre portefeuille est passée au-dessus de %s $ (actuellement %s $).',
                $alert->threshold, number_format($this->currentValue, 2)
            ),
            AlertType::PortfolioTotalBelow => sprintf(
                'La valeur totale de votre portefeuille est passée en dessous de %s $ (actuellement %s $).',
                $alert->threshold, number_format($this->currentValue, 2)
            ),
        };
    }
}
