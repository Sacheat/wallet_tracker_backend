<?php

namespace App\Notifications;

use App\Enums\AlertType;
use App\Models\PriceAlert;
use App\Notifications\Channels\DiscordWebhookChannel;
use App\Notifications\Concerns\FormatsNumbersForHumans;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PriceAlertTriggered extends Notification
{
    use FormatsNumbersForHumans;

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
                $alert->asset->name, $alert->asset->symbol, $this->formatNumber($alert->threshold, 8), $this->formatNumber($this->currentValue, 8)
            ),
            AlertType::PriceBelow => sprintf(
                'Le prix de %s (%s) est passé en dessous de %s $ (actuellement %s $).',
                $alert->asset->name, $alert->asset->symbol, $this->formatNumber($alert->threshold, 8), $this->formatNumber($this->currentValue, 8)
            ),
            AlertType::PercentChange => sprintf(
                '%s (%s) a varié de %s%% sur %s (seuil : ±%s%%).',
                $alert->asset->name, $alert->asset->symbol,
                $this->formatNumber($this->percentChange), $alert->window->value, $this->formatNumber($alert->percent_threshold)
            ),
            AlertType::PortfolioTotalAbove => sprintf(
                'La valeur totale de votre portefeuille est passée au-dessus de %s $ (actuellement %s $).',
                $this->formatNumber($alert->threshold), $this->formatNumber($this->currentValue)
            ),
            AlertType::PortfolioTotalBelow => sprintf(
                'La valeur totale de votre portefeuille est passée en dessous de %s $ (actuellement %s $).',
                $this->formatNumber($alert->threshold), $this->formatNumber($this->currentValue)
            ),
        };
    }
}
