<?php

namespace App\Services;

use App\DTO\PortfolioSummary;
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
        $cached = Cache::remember($cacheKey, 3600, function () use ($summary, $frequency, $apiKey) {
            try {
                $response = Http::timeout(20)->post(
                    'https://generativelanguage.googleapis.com/v1beta/models/'.self::MODEL.":generateContent?key={$apiKey}",
                    [
                        'contents' => [
                            ['role' => 'user', 'parts' => [['text' => $this->buildPrompt($summary, $frequency)]]],
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

    private function buildPrompt(PortfolioSummary $summary, string $frequency): string
    {
        $label = $frequency === 'daily' ? 'quotidien' : 'hebdomadaire';

        $composition = $summary->entries
            ->map(fn ($entry) => sprintf(
                '%s : %s $ (%s%% du portefeuille)',
                $entry->asset->name,
                number_format($entry->value_usd, 0),
                $summary->totalValueUsd > 0 ? number_format(($entry->value_usd / $summary->totalValueUsd) * 100, 1) : '0',
            ))
            ->implode("\n");

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

            Valeur totale du portefeuille : {$summary->totalValueUsd} \$ (variation : {$change})

            Composition :
            {$composition}

            {$fearGreedLine}

            Actualités récentes :
            {$newsBlock}

            Rédige un résumé qui relie ces informations entre elles (pas une simple liste de chiffres), et signale si besoin un point de vigilance pertinent (concentration du portefeuille, sentiment de marché extrême, etc.). Texte brut uniquement, sans formatage markdown (pas de **gras** ni de titres).
            PROMPT;
    }
}
