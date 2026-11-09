<?php

namespace App\Services;

use App\DTO\PortfolioSummary;
use App\Enums\Currency;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class GeminiSummaryService
{
    // gemini-3.5-flash-lite has a much higher free-tier quota (RPD 500 vs
    // 20) but was confirmed live to be unreliable (repeated timeouts, even
    // on a trivial prompt) while this model responded normally at the same
    // moment. thinkingBudget: 0 disables its thinking (on by default —
    // ~450 hidden tokens on a trivial prompt otherwise), which also brings
    // its token cost back down to roughly what Flash Lite would have cost.
    private const MODEL = 'gemini-3.6-flash';

    public function __construct(
        private FearGreedIndexService $fearGreedIndexService,
        private NewsService $newsService,
    ) {}

    /**
     * Nice-to-have on top of the digest, not a required part of it — any
     * failure (missing key, rate limit, network) degrades to null so the
     * digest still sends with its plain figures, never blocked by this.
     * Cached per user/frequency/day: PortfolioDigest fans out to one queued
     * job per channel (mail, Discord), each calling this independently —
     * the cache is what keeps that at one Gemini call, not one per channel.
     *
     * @param  'daily'|'weekly'  $frequency
     */
    public function summarizeDigest(PortfolioSummary $summary, User $user, string $frequency): ?string
    {
        $apiKey = config('services.gemini.key');

        if (empty($apiKey) || $summary->entries->isEmpty()) {
            return null;
        }

        $cacheKey = "ai_digest_summary_{$user->id}_{$frequency}_".now()->toDateString();

        // Cache::remember() treats a cached null exactly like a cache miss
        // (is_null() can't tell "stored null" from "never stored") — a
        // failure returning null here would silently retry the Gemini call
        // on every subsequent read, never actually caching the failure.
        // Confirmed live: a failed first call made the "cached" second call
        // take just as long as the first. '' is the on-failure sentinel
        // instead, translated back to null below.
        $cached = Cache::remember($cacheKey, 3600, function () use ($summary, $frequency, $apiKey, $user) {
            try {
                // Header rather than ?key=: a connection error logs the URL.
                $response = Http::timeout(20)->withHeaders(['x-goog-api-key' => $apiKey])->post(
                    'https://generativelanguage.googleapis.com/v1beta/models/'.self::MODEL.':generateContent',
                    [
                        'contents' => [
                            ['role' => 'user', 'parts' => [['text' => $this->buildPrompt($summary, $frequency, $user)]]],
                        ],
                        'generationConfig' => ['thinkingConfig' => ['thinkingBudget' => 0]],
                    ]
                );
                $response->throw();

                // The response can split its output across multiple `parts`
                // (confirmed live) — reading only parts.0 silently truncates
                // mid-sentence.
                $text = collect($response->json('candidates.0.content.parts', []))
                    ->pluck('text')
                    ->implode('');

                return trim($text);
            } catch (\Exception $e) {
                report($e);

                return '';
            }
        });

        return $cached !== '' ? $cached : null;
    }

    /**
     * In the user's own currency, like the digest email the summary sits
     * in: a figure in dollars under a total in euros would read as a
     * contradiction. Fully sold positions are left out (not holdings).
     */
    private function buildPrompt(PortfolioSummary $summary, string $frequency, User $user): string
    {
        $label = $frequency === 'daily' ? 'quotidien' : 'hebdomadaire';
        $currency = $user->currency instanceof Currency ? $user->currency : Currency::Usd;
        $total = $summary->totalValueByCurrency[$currency->value] ?? null;
        if ($total === null) {
            $currency = Currency::Usd;
            $total = $summary->totalValueUsd;
        }
        $toCurrency = $summary->totalValueUsd > 0 ? $total / $summary->totalValueUsd : 1.0;
        $unit = $currency->value;
        $totalText = number_format($total, 0, ',', ' ');

        $composition = $summary->entries
            ->filter(fn ($entry) => (float) $entry->quantity > 0)
            ->map(fn ($entry) => sprintf(
                '%s : %s %s (%s%% du portefeuille)',
                $entry->asset->name,
                number_format($entry->value_usd * $toCurrency, 0, ',', ' '),
                $unit,
                $summary->totalValueUsd > 0 ? number_format(($entry->value_usd / $summary->totalValueUsd) * 100, 1) : '0',
            ))
            ->implode("\n");

        $performance = $summary->performance[$currency->value] ?? null;
        $performanceLine = $performance !== null
            ? sprintf(
                'Plus-value latente : %s %s (%s%%), plus-value déjà réalisée : %s %s',
                number_format($performance['unrealized_pnl'], 0, ',', ' '), $unit,
                $performance['unrealized_pnl_percent'] !== null ? number_format($performance['unrealized_pnl_percent'], 1) : '?',
                number_format($performance['realized_pnl'], 0, ',', ' '), $unit,
            )
            : 'Plus-value : inconnue (prix d\'achat non renseignés)';

        $change = collect($summary->changePercent)
            ->filter(fn ($value) => $value !== null)
            ->map(fn ($value, $window) => "{$window}: ".number_format($value, 1).'%')
            ->implode(', ');

        $fearGreed = $this->fearGreedIndexService->getCurrent();
        $fearGreedLine = $fearGreed
            ? "Indice Fear & Greed (sentiment marché crypto) : {$fearGreed['value']}/100 ({$fearGreed['classification']})"
            : 'Indice Fear & Greed : indisponible';

        $assets = $summary->entries->pluck('asset')->unique('id');
        $news = collect($this->newsService->getNewsForAssets($assets))
            ->take(3)
            ->map(fn (array $item) => "- {$item['title']}")
            ->implode("\n");
        $newsBlock = $news !== '' ? $news : 'Aucune actualité récente disponible.';

        return <<<PROMPT
            Tu es un assistant financier qui rédige un résumé {$label} de portefeuille crypto/actions en français, clair et concis (4 à 5 phrases maximum), pour un utilisateur non-expert.

            Valeur totale du portefeuille : {$totalText} {$unit} (variation : {$change})
            {$performanceLine}

            Composition :
            {$composition}

            {$fearGreedLine}

            Actualités récentes :
            {$newsBlock}

            Rédige un résumé qui relie ces informations entre elles (pas une simple liste de chiffres), exprime les montants en {$unit}, et signale si besoin un point de vigilance pertinent (concentration du portefeuille, sentiment de marché extrême, etc.). Texte brut uniquement, sans formatage markdown (pas de **gras** ni de titres).
            PROMPT;
    }
}
