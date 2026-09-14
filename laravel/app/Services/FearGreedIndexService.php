<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class FearGreedIndexService
{
    /**
     * Crypto market sentiment only (Alternative.me tracks Bitcoin/crypto,
     * not stocks or gold) — a portfolio with only stock/gold holdings won't
     * find this figure representative. Updates roughly once a day upstream.
     *
     * @return array{value: int, classification: string, updated_at: Carbon}|null
     */
    public function getCurrent(): ?array
    {
        $backupKey = 'fear_greed_index_backup';

        return Cache::remember('fear_greed_index', 3600, function () use ($backupKey) {
            try {
                $response = Http::get('https://api.alternative.me/fng/', ['limit' => 1]);
                $response->throw();
                $entry = $response->json('data.0');

                $result = [
                    'value' => (int) $entry['value'],
                    'classification' => $entry['value_classification'],
                    'updated_at' => now()->createFromTimestamp((int) $entry['timestamp']),
                ];

                Cache::forever($backupKey, $result);

                return $result;
            } catch (\Exception $e) {
                if (Cache::has($backupKey)) {
                    return Cache::get($backupKey);
                }

                report($e);

                return null;
            }
        });
    }
}
