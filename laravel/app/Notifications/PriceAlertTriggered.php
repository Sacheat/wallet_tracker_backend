<?php

namespace App\Notifications;

use App\Enums\AlertType;
use App\Enums\Currency;
use App\Models\PriceAlert;
use App\Notifications\Channels\DiscordWebhookChannel;
use App\Notifications\Concerns\EscapesMarkdown;
use App\Notifications\Concerns\FormatsNumbersForHumans;
use App\Notifications\Concerns\QueuedWithRetries;
use App\Services\PriceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use RuntimeException;

class PriceAlertTriggered extends Notification implements ShouldQueue
{
    use EscapesMarkdown, FormatsNumbersForHumans, QueuedWithRetries;

    /** @var array<string, string> Asset type in a sentence ("le plus gros écart : la crypto"). */
    private const ASSET_TYPE_LABELS = ['crypto' => 'la crypto', 'stock' => 'les actions', 'etf' => 'les ETF', 'gold' => "l'or"];

    /** @var array<string, string> Asset type as a heading. */
    private const ASSET_TYPE_NAMES = ['crypto' => 'Crypto', 'stock' => 'Actions', 'etf' => 'ETF', 'gold' => 'Or'];

    /** @var array<string, string> */
    private const WINDOW_LABELS = ['1h' => '1 h', '24h' => '24 h', '7d' => '7 jours'];

    /** @var array<string, string> The cooldown, which is the window itself. */
    private const COOLDOWN_LABELS = ['1h' => "dans l'heure qui vient", '24h' => 'dans les 24 h qui viennent', '7d' => 'dans les 7 jours qui viennent'];

    /**
     * The alert (or its user) may be deleted between dispatch and delivery;
     * drop the job instead of failing it with ModelNotFoundException.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * @param  float  $currentValue  The current price (asset alerts), portfolio total (portfolio alerts) or largest drift in points (allocation alerts), in USD for amounts.
     * @param  float|null  $percentChange  Only set for AlertType::PercentChange.
     * @param  array{type: string, percent: float, target_percent: float, rows?: array<int, array{type: string, percent: float|null, target_percent: float|null}>}|null  $context  Only set for AlertType::AllocationDrift: the asset type furthest from its target, and every type's share and target.
     */
    public function __construct(
        public PriceAlert $alert,
        public float $currentValue,
        public ?float $percentChange = null,
        public ?array $context = null,
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
        $frontendUrl = config('mail.brand.frontend_url');

        return (new MailMessage)
            ->subject($content['subject'])
            ->markdown('mail.price-alert', [
                ...$content,
                'actionUrl' => $frontendUrl.$content['actionPath'],
                'alertsUrl' => $frontendUrl.'/alerts',
            ]);
    }

    public function toDiscord(object $notifiable): string
    {
        return '🔔 '.$this->content($notifiable)['discord'];
    }

    /**
     * Everything both channels show, per alert type. Amounts are converted
     * to the user's currency at today's rate (they are stored in USD), and
     * fall back to USD if no rate can be had: an alert is worth more late
     * in dollars than not at all.
     *
     * @return array{subject: string, preheader: string, headline: string, intro: string, stats: array<int, array{label: string, value: string, tone?: string, hint?: string|null}>, allocation: array<int, array<string, mixed>>, actionText: string, actionPath: string, note: string, discord: string}
     */
    private function content(object $notifiable): array
    {
        $alert = $this->alert;
        [$currency, $rate] = $this->displayCurrency($notifiable);
        $money = fn (float|string $usd) => $this->formatMoney((float) $usd * $rate, $currency);

        // Asset names come from a catalog any user can edit: escaped where
        // the text is parsed as Markdown (headline, intro, note, Discord),
        // raw where it isn't (subject, preheader, cards, button), Blade
        // escaping the HTML either way.
        $name = $alert->asset->name ?? '';
        $ticker = $alert->asset->symbol ?? '';
        $asset = $this->escapeMarkdown($name);
        $symbol = $this->escapeMarkdown($ticker);
        $threshold = (float) $alert->threshold;
        $gap = $threshold > 0 ? ($this->currentValue - $threshold) / $threshold * 100 : 0.0;
        $rearm = 'Pas de nouvel e-mail tant que %s reste %s du seuil : l\'alerte se réarme quand %s repasse de l\'autre côté.';

        return match ($alert->type) {
            AlertType::PriceAbove, AlertType::PriceBelow => (function () use ($alert, $name, $ticker, $asset, $symbol, $money, $threshold, $gap, $rearm) {
                $above = $alert->type === AlertType::PriceAbove;
                $verb = $above ? 'a dépassé' : 'est passé sous';

                return [
                    'subject' => "{$name} {$verb} {$money($threshold)}",
                    'preheader' => "{$ticker} cote maintenant {$money($this->currentValue)}.",
                    'headline' => "{$asset} {$verb} votre seuil",
                    'intro' => "Le prix de {$asset} ({$symbol}) est passé ".($above ? 'au-dessus' : 'en dessous').' du seuil que vous aviez fixé.',
                    'stats' => [
                        ['label' => 'Prix actuel', 'value' => $money($this->currentValue), 'tone' => $above ? 'gain' : 'loss'],
                        ['label' => 'Votre seuil', 'value' => $money($threshold), 'hint' => $above ? 'franchi à la hausse' : 'franchi à la baisse'],
                        ['label' => 'Écart', 'value' => $this->formatSignedPercent($gap), 'hint' => 'par rapport au seuil'],
                    ],
                    'allocation' => [],
                    'actionText' => "Voir {$name}",
                    'actionPath' => "/assets/{$alert->asset_id}",
                    'note' => sprintf($rearm, 'le prix', $above ? 'au-dessus' : 'en dessous', 'il'),
                    'discord' => sprintf(
                        'Le prix de %s (%s) est passé %s de %s (actuellement %s).',
                        $asset, $symbol, $above ? 'au-dessus' : 'en dessous', $money($threshold), $money($this->currentValue)
                    ),
                ];
            })(),

            AlertType::PercentChange => (function () use ($alert, $name, $ticker, $asset, $symbol, $money) {
                $window = self::WINDOW_LABELS[$alert->window->value] ?? $alert->window->value;
                $change = $this->formatSignedPercent((float) $this->percentChange);
                $rising = $this->percentChange >= 0;

                return [
                    'subject' => "{$name} : {$change} en {$window}",
                    'preheader' => "{$ticker} ".($rising ? 'grimpe' : 'recule')." de {$this->formatNumber(abs((float) $this->percentChange))} % en {$window}, à {$money($this->currentValue)}.",
                    'headline' => "{$asset} ".($rising ? 'grimpe' : 'recule')." de {$this->formatNumber(abs((float) $this->percentChange))} %",
                    'intro' => "{$asset} ({$symbol}) a varié de plus de {$this->formatNumber($alert->percent_threshold)} % en {$window}.",
                    'stats' => [
                        ['label' => "Variation {$window}", 'value' => $change, 'tone' => $rising ? 'gain' : 'loss'],
                        ['label' => 'Prix actuel', 'value' => $money($this->currentValue)],
                        ['label' => 'Votre seuil', 'value' => '± '.$this->formatNumber($alert->percent_threshold).' %'],
                    ],
                    'allocation' => [],
                    'actionText' => "Voir {$name}",
                    'actionPath' => "/assets/{$alert->asset_id}",
                    'note' => 'Pour éviter les rafales, cette alerte ne pourra pas se redéclencher '.(self::COOLDOWN_LABELS[$alert->window->value] ?? "avant {$window}").'.',
                    'discord' => sprintf(
                        '%s (%s) a varié de %s en %s (seuil : ±%s %%), à %s.',
                        $asset, $symbol, $change, $window, $this->formatNumber($alert->percent_threshold), $money($this->currentValue)
                    ),
                ];
            })(),

            AlertType::PortfolioTotalAbove, AlertType::PortfolioTotalBelow => (function () use ($alert, $money, $threshold, $gap, $rearm) {
                $above = $alert->type === AlertType::PortfolioTotalAbove;

                return [
                    'subject' => 'Votre portefeuille '.($above ? 'a dépassé ' : 'est passé sous ').$money($threshold),
                    'preheader' => "Il vaut maintenant {$money($this->currentValue)}.",
                    'headline' => 'Votre portefeuille '.($above ? 'a dépassé' : 'est passé sous').' votre seuil',
                    'intro' => 'La valeur totale de votre portefeuille est passée '.($above ? 'au-dessus' : 'en dessous').' du seuil que vous aviez fixé.',
                    'stats' => [
                        ['label' => 'Valeur actuelle', 'value' => $money($this->currentValue), 'tone' => $above ? 'gain' : 'loss'],
                        ['label' => 'Votre seuil', 'value' => $money($threshold), 'hint' => $above ? 'franchi à la hausse' : 'franchi à la baisse'],
                        ['label' => 'Écart', 'value' => $this->formatSignedPercent($gap), 'hint' => 'par rapport au seuil'],
                    ],
                    'allocation' => [],
                    'actionText' => 'Voir mon portefeuille',
                    'actionPath' => '/',
                    'note' => sprintf($rearm, 'la valeur', $above ? 'au-dessus' : 'en dessous', 'elle'),
                    'discord' => sprintf(
                        'La valeur totale de votre portefeuille est passée %s de %s (actuellement %s).',
                        $above ? 'au-dessus' : 'en dessous', $money($threshold), $money($this->currentValue)
                    ),
                ];
            })(),

            AlertType::AllocationDrift => (function () use ($alert) {
                $worstType = $this->context['type'] ?? '';
                $worstLabel = self::ASSET_TYPE_LABELS[$worstType] ?? ($worstType ?: '?');
                $drift = $this->formatNumber($this->currentValue);
                $percentThreshold = (float) $alert->percent_threshold;

                return [
                    'subject' => "Votre répartition s'écarte de vos objectifs ({$drift} points)",
                    'preheader' => "Le plus gros écart : {$worstLabel}, à {$this->formatNumber($this->context['percent'] ?? 0)} % pour un objectif de {$this->formatNumber($this->context['target_percent'] ?? 0)} %.",
                    'headline' => "Votre répartition s'écarte de vos objectifs",
                    'intro' => "Le plus gros écart concerne {$worstLabel} : {$this->formatNumber($this->context['percent'] ?? 0)} % du portefeuille pour un objectif de {$this->formatNumber($this->context['target_percent'] ?? 0)} %. Un rééquilibrage, ou un prochain apport bien réparti, vous ramènera vers la cible.",
                    'stats' => [
                        ['label' => 'Écart maximal', 'value' => "{$drift} pts", 'tone' => 'loss'],
                        ['label' => 'Votre seuil', 'value' => $this->formatNumber($percentThreshold).' pts'],
                    ],
                    'allocation' => collect($this->context['rows'] ?? [])
                        ->filter(fn (array $row) => $row['percent'] !== null && $row['target_percent'] !== null)
                        ->map(function (array $row) use ($percentThreshold) {
                            $rowDrift = $row['percent'] - $row['target_percent'];

                            return [
                                'label' => self::ASSET_TYPE_NAMES[$row['type']] ?? $row['type'],
                                'percent' => (float) $row['percent'],
                                'target' => (float) $row['target_percent'],
                                'percent_text' => $this->formatNumber($row['percent'], 1).' %',
                                'target_text' => $this->formatNumber($row['target_percent'], 1).' %',
                                'drift_text' => ($rowDrift > 0 ? '+' : '').$this->formatNumber($rowDrift, 1).' pts',
                                'off' => abs($rowDrift) >= $percentThreshold,
                            ];
                        })->values()->all(),
                    'actionText' => 'Voir ma répartition',
                    'actionPath' => '/allocation',
                    'note' => "Pas de nouvel e-mail tant que l'écart reste au-dessus de {$this->formatNumber($percentThreshold)} points : l'alerte se réarme quand votre répartition revient dans les clous.",
                    'discord' => sprintf(
                        'La répartition de votre portefeuille s\'écarte de vos objectifs de %s points (seuil : %s points). Le plus gros écart : %s, à %s %% pour un objectif de %s %%.',
                        $drift, $this->formatNumber($percentThreshold), $worstLabel,
                        $this->formatNumber($this->context['percent'] ?? 0), $this->formatNumber($this->context['target_percent'] ?? 0)
                    ),
                ];
            })(),
        };
    }

    /**
     * @return array{0: Currency, 1: float} The currency to show amounts in, and its USD→currency rate.
     */
    private function displayCurrency(object $notifiable): array
    {
        $currency = $notifiable->currency instanceof Currency ? $notifiable->currency : Currency::Usd;
        if ($currency === Currency::Usd) {
            return [Currency::Usd, 1.0];
        }

        try {
            $rate = app(PriceService::class)->getUsdToCurrencyRates([$currency->value])[$currency->value];
        } catch (RuntimeException) {
            $rate = null;
        }

        return $rate === null ? [Currency::Usd, 1.0] : [$currency, (float) $rate];
    }
}
