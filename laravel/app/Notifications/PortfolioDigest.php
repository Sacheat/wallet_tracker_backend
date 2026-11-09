<?php

namespace App\Notifications;

use App\DTO\PortfolioSummary;
use App\Enums\Currency;
use App\Notifications\Channels\DiscordWebhookChannel;
use App\Notifications\Concerns\EscapesMarkdown;
use App\Notifications\Concerns\FormatsNumbersForHumans;
use App\Notifications\Concerns\OffersUnsubscribe;
use App\Notifications\Concerns\QueuedWithRetries;
use App\Services\GeminiSummaryService;
use App\Services\PortfolioDigestService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PortfolioDigest extends Notification implements ShouldQueue
{
    use EscapesMarkdown, FormatsNumbersForHumans, OffersUnsubscribe, QueuedWithRetries;

    /** @var array<string, string> */
    private const ASSET_TYPE_NAMES = ['crypto' => 'Crypto', 'stock' => 'Actions', 'etf' => 'ETF', 'gold' => 'Or'];

    /** @var array<string, string> */
    private const WINDOW_LABELS = ['24h' => '24 h', '7d' => '7 jours'];

    /** How many positions the Discord message lists (it is capped at 2,000 characters). */
    private const DISCORD_POSITIONS = 5;

    /**
     * @param  'daily'|'weekly'  $frequency
     * @param  array<string, mixed>|null  $content  PortfolioDigestService::build(), computed at dispatch; null only for jobs queued before it existed.
     */
    public function __construct(
        public PortfolioSummary $summary,
        public string $frequency,
        public ?array $content = null,
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
        $content = $this->content($notifiable);
        $currency = Currency::from($content['currency']);
        $frontendUrl = config('mail.brand.frontend_url');
        $daily = $this->frequency === 'daily';
        $windowLabel = self::WINDOW_LABELS[$content['window']];
        $change = $content['change'][$content['window']];
        $performance = $content['performance'];
        $aiSummary = $this->aiSummary($notifiable);

        $stats = [[
            'label' => 'Valeur totale',
            'value' => $this->formatMoney($content['total'], $currency),
            'tone' => 'accent',
        ]];
        $stats[] = $change === null
            ? ['label' => "Sur {$windowLabel}", 'value' => '—', 'hint' => 'variation indisponible']
            : [
                'label' => "Sur {$windowLabel}",
                'value' => $this->formatSignedMoney($change['amount'], $currency),
                'tone' => $this->tone($change['amount']),
                'hint' => $this->formatSignedPercent($change['percent']),
            ];
        if ($performance !== null) {
            $stats[] = [
                'label' => 'Plus-value latente',
                'value' => $this->formatSignedMoney($performance['unrealized_pnl'], $currency),
                'tone' => $this->tone($performance['unrealized_pnl']),
                'hint' => ($performance['unrealized_pnl_percent'] !== null ? $this->formatSignedPercent($performance['unrealized_pnl_percent']).' · ' : '')
                    .'réalisée : '.$this->formatSignedMoney($performance['realized_pnl'], $currency),
            ];
        }

        $mover = fn (array $move) => [
            'title' => $move['name'],
            'subtitle' => $move['symbol'],
            'value' => $this->formatSignedPercent($move['percent']),
            'value_tone' => $this->tone($move['percent']),
        ];

        $unsubscribeUrl = $this->unsubscribeUrl($notifiable);

        return $this->withUnsubscribeHeaders((new MailMessage), $unsubscribeUrl)
            ->subject(sprintf(
                'Votre portefeuille : %s%s',
                $this->formatMoney($content['total'], $currency),
                $change === null ? '' : " ({$this->formatSignedPercent($change['percent'])} sur {$windowLabel})",
            ))
            ->markdown('mail.portfolio-digest', [
                'preheader' => $this->preheader($content, $currency),
                'headline' => $daily ? 'Votre résumé du jour' : 'Votre résumé de la semaine',
                'dateline' => ucfirst(now()->locale('fr')->translatedFormat('l j F Y')),
                'stats' => $stats,
                'aiSummary' => $aiSummary !== null ? $this->escapeMarkdown($aiSummary) : null,
                'windowLabel' => $windowLabel,
                'risers' => array_map($mover, $content['risers']),
                'fallers' => array_map($mover, $content['fallers']),
                'hasTargets' => $content['allocation']['has_targets'],
                'allocation' => $this->allocationRows($content['allocation']),
                'positions' => array_map(fn (array $position) => [
                    'title' => $position['name'],
                    'subtitle' => "{$this->formatNumber($position['quantity'], 8)} {$position['symbol']} · {$position['platform']}",
                    'value' => $this->formatMoney($position['value'], $currency),
                    'detail' => $position['pnl_percent'] !== null
                        ? $this->formatSignedPercent($position['pnl_percent']).' depuis l\'achat'
                        : ($position['share'] !== null ? $this->formatNumber($position['share'], 1).' % du portefeuille' : null),
                    'detail_tone' => $position['pnl_percent'] !== null ? $this->tone($position['pnl_percent']) : 'muted',
                ], $content['positions']),
                'morePositions' => $content['positions_count'] - count($content['positions']),
                'positionsWithoutPrice' => $content['positions_without_price'],
                'frequencyLabel' => $daily ? 'chaque jour' : 'chaque semaine',
                'portfolioUrl' => $frontendUrl.'/',
                'allocationUrl' => $frontendUrl.'/allocation',
                'accountUrl' => $frontendUrl.'/account',
                'unsubscribeUrl' => $unsubscribeUrl,
            ]);
    }

    public function toDiscord(object $notifiable): string
    {
        $content = $this->content($notifiable);
        $currency = Currency::from($content['currency']);
        $windowLabel = self::WINDOW_LABELS[$content['window']];
        $change = $content['change'][$content['window']];
        $performance = $content['performance'];
        $move = fn (array $move) => "{$this->escapeMarkdown($move['name'])} {$this->formatSignedPercent($move['percent'])}";

        $lines = [sprintf(
            '📊 %s — %s%s',
            $this->frequency === 'daily' ? 'Résumé du jour' : 'Résumé de la semaine',
            $this->formatMoney($content['total'], $currency),
            $change === null ? '' : " ({$this->formatSignedMoney($change['amount'], $currency)}, {$this->formatSignedPercent($change['percent'])} sur {$windowLabel})",
        )];
        if ($performance !== null) {
            $lines[] = "Plus-value latente : {$this->formatSignedMoney($performance['unrealized_pnl'], $currency)}"
                .($performance['unrealized_pnl_percent'] !== null ? " ({$this->formatSignedPercent($performance['unrealized_pnl_percent'])})" : '')
                ." · réalisée : {$this->formatSignedMoney($performance['realized_pnl'], $currency)}";
        }
        if ($content['risers'] !== []) {
            $lines[] = '📈 '.implode(', ', array_map($move, $content['risers']));
        }
        if ($content['fallers'] !== []) {
            $lines[] = '📉 '.implode(', ', array_map($move, $content['fallers']));
        }
        foreach (array_slice($content['positions'], 0, self::DISCORD_POSITIONS) as $position) {
            $lines[] = "- {$this->escapeMarkdown($position['name'])} sur {$this->escapeMarkdown($position['platform'])} : {$this->formatMoney($position['value'], $currency)}";
        }
        $more = $content['positions_count'] - min(self::DISCORD_POSITIONS, count($content['positions']));
        if ($more > 0) {
            $lines[] = "… et {$more} autre".($more > 1 ? 's' : '');
        }

        $message = implode("\n", $lines);
        if ($aiSummary = $this->aiSummary($notifiable)) {
            $message .= "\n\n{$this->escapeMarkdown($aiSummary)}";
        }

        return $message;
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function preheader(array $content, Currency $currency): string
    {
        $parts = [];
        if ($content['performance'] !== null) {
            $parts[] = 'Plus-value latente : '.$this->formatSignedMoney($content['performance']['unrealized_pnl'], $currency);
        }
        if ($content['risers'] !== []) {
            $parts[] = "En tête : {$content['risers'][0]['name']} {$this->formatSignedPercent($content['risers'][0]['percent'])}";
        }
        if ($content['fallers'] !== []) {
            $parts[] = "Recul : {$content['fallers'][0]['name']} {$this->formatSignedPercent($content['fallers'][0]['percent'])}";
        }

        return $parts !== [] ? implode(' · ', $parts) : 'Vos chiffres et vos positions en un coup d\'œil.';
    }

    /**
     * @param  array{has_targets: bool, max_drift: float|null, rows: array<int, array{type: string, percent: float|null, target_percent: float|null}>}  $allocation
     * @return array<int, array<string, mixed>>
     */
    private function allocationRows(array $allocation): array
    {
        return collect($allocation['rows'])
            ->filter(fn (array $row) => $row['percent'] !== null)
            ->map(function (array $row) {
                $drift = $row['target_percent'] !== null ? $row['percent'] - $row['target_percent'] : null;

                return [
                    'label' => self::ASSET_TYPE_NAMES[$row['type']] ?? $row['type'],
                    'percent' => (float) $row['percent'],
                    'percent_text' => $this->formatNumber($row['percent'], 1).' %',
                    'target_text' => $row['target_percent'] !== null ? $this->formatNumber($row['target_percent'], 1).' %' : null,
                    'drift_text' => $drift !== null ? ($drift > 0 ? '+' : '').$this->formatNumber($drift, 1).' pts' : null,
                    // No alert threshold here: 5 points is the usual rebalancing band.
                    'off' => $drift !== null && abs($drift) >= 5,
                ];
            })->values()->all();
    }

    private function tone(float $value): string
    {
        return $value > 0 ? 'gain' : ($value < 0 ? 'loss' : 'default');
    }

    /**
     * @return array<string, mixed>
     */
    private function content(object $notifiable): array
    {
        return $this->content ??= app(PortfolioDigestService::class)->build($notifiable, $this->summary, $this->frequency)
            ?? throw new \RuntimeException('Digest without any position held.');
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
