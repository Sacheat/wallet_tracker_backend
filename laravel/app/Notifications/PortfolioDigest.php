<?php

namespace App\Notifications;

use App\DTO\PortfolioSummary;
use App\Notifications\Channels\DiscordWebhookChannel;
use App\Notifications\Concerns\FormatsNumbersForHumans;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PortfolioDigest extends Notification
{
    use FormatsNumbersForHumans;

    /**
     * @param  'daily'|'weekly'  $frequency
     */
    public function __construct(
        public PortfolioSummary $summary,
        public string $frequency,
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
        $label = $this->frequency === 'daily' ? 'quotidien' : 'hebdomadaire';

        $mail = (new MailMessage)
            ->subject("Crypto Portfolio Tracker — Digest {$label}")
            ->line("Valeur totale : {$this->formatNumber($this->summary->totalValueUsd)} \$ / {$this->formatNumber($this->summary->totalValueEur)} €");

        foreach ($this->summary->entries as $entry) {
            $mail->line("- {$entry->asset->name} sur {$entry->platform->name} : {$this->formatNumber($entry->value_usd)} \$");
        }

        return $mail;
    }

    public function toDiscord(object $notifiable): string
    {
        $label = $this->frequency === 'daily' ? 'quotidien' : 'hebdomadaire';
        $lines = $this->summary->entries
            ->map(fn ($entry) => "- {$entry->asset->name} sur {$entry->platform->name} : {$this->formatNumber($entry->value_usd)} \$")
            ->implode("\n");

        return "📊 Digest {$label}\nTotal : {$this->formatNumber($this->summary->totalValueUsd)} \$ / {$this->formatNumber($this->summary->totalValueEur)} €\n{$lines}";
    }
}
