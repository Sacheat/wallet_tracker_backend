<?php

namespace App\Notifications;

use App\Enums\AlertType;
use App\Models\PriceAlert;
use App\Notifications\Channels\DiscordWebhookChannel;
use App\Notifications\Concerns\EscapesMarkdown;
use App\Notifications\Concerns\FormatsNumbersForHumans;
use App\Notifications\Concerns\QueuedWithRetries;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PriceAlertTriggered extends Notification implements ShouldQueue
{
    use EscapesMarkdown, FormatsNumbersForHumans, QueuedWithRetries;

    /**
     * The alert (or its user) may be deleted between dispatch and delivery;
     * drop the job instead of failing it with ModelNotFoundException.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * @param  float  $currentValue  The current price (asset alerts), portfolio total (portfolio alerts) or largest drift in points (allocation alerts).
     * @param  float|null  $percentChange  Only set for AlertType::PercentChange.
     * @param  array{type: string, percent: float, target_percent: float}|null  $context  Only set for AlertType::AllocationDrift: the asset type furthest from its target.
     */
    public function __construct(
        public PriceAlert $alert,
        public float $currentValue,
        public ?float $percentChange = null,
        public ?array $context = null,
    ) {}

    private const ASSET_TYPE_LABELS = ['crypto' => 'la crypto', 'stock' => 'les actions', 'etf' => 'les ETF', 'gold' => "l'or"];

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = [];

        // Only to a verified address: an email change un-verifies the
        // account but its alerts and digests kept running, so anyone could
        // point them at a stranger's inbox (and burn our sender reputation).
        if ($notifiable->email_notifications_enabled && $notifiable->hasVerifiedEmail()) {
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
            ->line($this->message())
            ->markdown('notifications::email', ['manageNotifications' => true]);
    }

    public function toDiscord(object $notifiable): string
    {
        return '🔔 '.$this->message();
    }

    private function message(): string
    {
        $alert = $this->alert;
        $assetName = $this->escapeMarkdown($alert->asset->name ?? '');
        $assetSymbol = $this->escapeMarkdown($alert->asset->symbol ?? '');

        return match ($alert->type) {
            AlertType::PriceAbove => sprintf(
                'Le prix de %s (%s) est passé au-dessus de %s $ (actuellement %s $).',
                $assetName, $assetSymbol, $this->formatNumber($alert->threshold, 8), $this->formatNumber($this->currentValue, 8)
            ),
            AlertType::PriceBelow => sprintf(
                'Le prix de %s (%s) est passé en dessous de %s $ (actuellement %s $).',
                $assetName, $assetSymbol, $this->formatNumber($alert->threshold, 8), $this->formatNumber($this->currentValue, 8)
            ),
            AlertType::PercentChange => sprintf(
                '%s (%s) a varié de %s%% sur %s (seuil : ±%s%%).',
                $assetName, $assetSymbol,
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
            AlertType::AllocationDrift => sprintf(
                'La répartition de votre portefeuille s\'écarte de vos objectifs de %s points (seuil : %s points). Le plus gros écart : %s, à %s %% pour un objectif de %s %%.',
                $this->formatNumber($this->currentValue), $this->formatNumber($alert->percent_threshold),
                self::ASSET_TYPE_LABELS[$this->context['type'] ?? ''] ?? ($this->context['type'] ?? '?'),
                $this->formatNumber($this->context['percent'] ?? 0), $this->formatNumber($this->context['target_percent'] ?? 0)
            ),
        };
    }
}
