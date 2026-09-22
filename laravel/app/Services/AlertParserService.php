<?php

namespace App\Services;

use App\Enums\AlertType;
use App\Models\Asset;
use Illuminate\Support\Facades\Http;

class AlertParserService
{
    // Separate free-tier quota bucket from GeminiSummaryService's
    // gemini-3.6-flash — this is a user-triggered, occasional call, not a
    // once-per-user-per-day batch, but keeping it off the digest's model
    // means neither feature can starve the other's daily limit.
    private const MODEL = 'gemini-3.1-flash-lite';

    private const SCHEMA = [
        'type' => 'OBJECT',
        'properties' => [
            'type' => [
                'type' => 'STRING',
                'enum' => ['price_above', 'price_below', 'percent_change', 'portfolio_total_above', 'portfolio_total_below'],
            ],
            'asset_name' => [
                'type' => 'STRING',
                'nullable' => true,
                'description' => "Nom ou symbole brut de l'actif mentionné, tel que dit par l'utilisateur, ou null si l'alerte porte sur le portefeuille total.",
            ],
            'threshold' => ['type' => 'NUMBER', 'nullable' => true],
            'percent_threshold' => ['type' => 'NUMBER', 'nullable' => true],
            'window' => ['type' => 'STRING', 'enum' => ['1h', '24h', '7d'], 'nullable' => true],
            'confidence' => [
                'type' => 'STRING',
                'enum' => ['high', 'low'],
                'description' => "low si la demande n'est pas une demande d'alerte de prix claire, ou s'il manque une information essentielle (seuil, actif, fenêtre...).",
            ],
        ],
        'required' => ['type', 'confidence'],
    ];

    /**
     * @return array{understood: true, type: string, asset: ?array{id: int, name: string, symbol: string}, threshold: ?float, percent_threshold: ?float, window: ?string}|array{understood: false, reason: 'ambiguous'|'asset_not_found'|'unavailable', message: string}
     */
    public function parse(string $text): array
    {
        $apiKey = config('services.gemini.key');

        if (empty($apiKey)) {
            return $this->unavailable();
        }

        $extracted = $this->extract($text, $apiKey);

        if ($extracted === null) {
            return $this->unavailable();
        }

        if (($extracted['confidence'] ?? 'low') !== 'high') {
            return [
                'understood' => false,
                'reason' => 'ambiguous',
                'message' => "Je n'ai pas compris cette demande d'alerte. Reformule avec l'actif, le seuil et la condition (ex. \"préviens-moi si le bitcoin dépasse 50000\").",
            ];
        }

        $type = AlertType::from($extracted['type']);

        // Completeness checks first, asset lookup (the only DB query in this
        // service) last — an incomplete extraction fails without ever
        // touching the database.
        if ($type === AlertType::PercentChange && ($extracted['percent_threshold'] === null || empty($extracted['window']))) {
            return [
                'understood' => false,
                'reason' => 'ambiguous',
                'message' => 'Il manque le pourcentage ou la fenêtre (1h/24h/7d) pour cette alerte de variation.',
            ];
        }

        if ($type !== AlertType::PercentChange && ($type->isAssetAlert() || $type->isPortfolioAlert()) && $extracted['threshold'] === null) {
            return [
                'understood' => false,
                'reason' => 'ambiguous',
                'message' => 'Il manque le seuil pour cette alerte.',
            ];
        }

        $needsAsset = $type->isAssetAlert();
        $asset = $needsAsset ? $this->findAsset($extracted['asset_name'] ?? null) : null;

        if ($needsAsset && ! $asset) {
            return [
                'understood' => false,
                'reason' => 'asset_not_found',
                'message' => 'Actif introuvable dans le catalogue'.(($extracted['asset_name'] ?? null) ? " : \"{$extracted['asset_name']}\"" : '').". Ajoute-le d'abord via la recherche d'actifs.",
            ];
        }

        return [
            'understood' => true,
            'type' => $type->value,
            'asset' => $asset ? ['id' => $asset->id, 'name' => $asset->name, 'symbol' => $asset->symbol] : null,
            'threshold' => $extracted['threshold'] !== null ? (float) $extracted['threshold'] : null,
            'percent_threshold' => $extracted['percent_threshold'] !== null ? (float) $extracted['percent_threshold'] : null,
            'window' => $extracted['window'] ?? null,
        ];
    }

    /**
     * @return array{understood: false, reason: 'unavailable', message: string}
     */
    private function unavailable(): array
    {
        return [
            'understood' => false,
            'reason' => 'unavailable',
            'message' => "Impossible d'analyser la demande pour le moment. Réessaie dans quelques instants, ou crée l'alerte manuellement.",
        ];
    }

    /**
     * A single automatic retry: the free tier returns a fast 503 ("high
     * demand", confirmed live, ~1-2s) often enough that one retry recovers
     * most failures, unlike the digest's timeouts which a retry wouldn't fix.
     *
     * @return array<string, mixed>|null
     */
    private function extract(string $text, string $apiKey): ?array
    {
        $prompt = "Analyse cette demande de création d'alerte de prix et extrait les informations dans le format demandé.\n\n".
            "Demande de l'utilisateur : \"{$text}\"";

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $response = Http::timeout(15)->post(
                    'https://generativelanguage.googleapis.com/v1beta/models/'.self::MODEL.":generateContent?key={$apiKey}",
                    [
                        'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                        'generationConfig' => [
                            'responseMimeType' => 'application/json',
                            'responseSchema' => self::SCHEMA,
                        ],
                    ]
                );
                $response->throw();

                $raw = collect($response->json('candidates.0.content.parts', []))
                    ->pluck('text')
                    ->implode('');

                return json_decode($raw, true);
            } catch (\Exception $e) {
                if ($attempt === 0) {
                    continue;
                }
                report($e);

                return null;
            }
        }

        return null;
    }

    /**
     * Local catalog match only — no external lookup, no auto-creation. An
     * asset the AI names but that isn't already in the shared catalog
     * surfaces as a clear "add it first" error instead.
     */
    private function findAsset(?string $assetName): ?Asset
    {
        if (empty($assetName)) {
            return null;
        }

        $needle = trim($assetName);

        return Asset::whereRaw('LOWER(symbol) = ?', [strtolower($needle)])->first()
            ?? Asset::whereRaw('LOWER(name) = ?', [strtolower($needle)])->first()
            ?? Asset::whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($needle).'%'])->first();
    }
}
