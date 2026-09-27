<?php

namespace App\Notifications;

use App\DTO\PortfolioSummary;
use App\Notifications\Channels\DiscordWebhookChannel;
use App\Notifications\Concerns\EscapesMarkdown;
use App\Notifications\Concerns\FormatsNumbersForHumans;
use App\Notifications\Concerns\QueuedWithRetries;
use App\Services\GeminiSummaryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PortfolioDigest extends Notification implements ShouldQueue
{
    use EscapesMarkdown, FormatsNumbersForHumans, QueuedWithRetries;

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
        $label = $this->frequency === 'daily' ? 'quotidien' : 'hebdomadaire';

        $mail = (new MailMessage)
            ->subject("Crypto Portfolio Tracker — Digest {$label}")
            ->line("Valeur totale : {$this->formatNumber($this->summary->totalValueUsd)} \$ / {$this->formatNumber($this->summary->totalValueEur)} €");

        if ($aiSummary = $this->aiSummary($notifiable)) {
            $mail->line($this->escapeMarkdown($aiSummary));
        }

        foreach ($this->summary->entries as $entry) {
            $mail->line("- {$this->escapeMarkdown($entry->asset->name)} sur {$this->escapeMarkdown($entry->platform->name)} : {$this->formatNumber($entry->value_usd)} \$");
        }

        return $mail;
    }

    public function toDiscord(object $notifiable): string
    {
        $label = $this->frequency === 'daily' ? 'quotidien' : 'hebdomadaire';
        $lines = $this->summary->entries
            ->map(fn ($entry) => "- {$this->escapeMarkdown($entry->asset->name)} sur {$this->escapeMarkdown($entry->platform->name)} : {$this->formatNumber($entry->value_usd)} \$")
            ->implode("\n");

        $message = "📊 Digest {$label}\nTotal : {$this->formatNumber($this->summary->totalValueUsd)} \$ / {$this->formatNumber($this->summary->totalValueEur)} €\n{$lines}";

        if ($aiSummary = $this->aiSummary($notifiable)) {
            $message .= "\n\n{$this->escapeMarkdown($aiSummary)}";
        }

        return $message;
    }

    /**
     * Mail and Discord are dispatched as separate queued jobs (see
     * QueuedWithRetries), each with its own deserialized copy of this
     * notification — GeminiSummaryService's own per-user/day cache is what
     * keeps that at one Gemini call instead of one per channel, not this
     * method (no per-instance memoization would survive across jobs).
     */
    private function aiSummary(object $notifiable): ?string
    {
        return app(GeminiSummaryService::class)->summarizeDigest($this->summary, $notifiable, $this->frequency);
    }
}
