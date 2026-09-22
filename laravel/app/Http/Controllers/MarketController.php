<?php

namespace App\Http\Controllers;

use App\Services\FearGreedIndexService;
use App\Services\PriceService;
use Illuminate\Http\JsonResponse;

class MarketController extends Controller
{
    /**
     * Currencies the frontend can let the user pick as a display currency,
     * beyond the implicit USD base. Kept in sync with PortfolioService's
     * EXTRA_CURRENCIES + EUR so /api/portfolio's total_value_by_currency and
     * this endpoint always expose the same set, from the same cached fetch.
     */
    private const CURRENCIES = ['EUR', 'GBP', 'CHF'];

    public function fearGreedIndex(FearGreedIndexService $fearGreedIndexService)
    {
        return $fearGreedIndexService->getCurrent() ?? response()->json(['message' => 'Indice indisponible pour le moment.'], 503);
    }

    public function exchangeRates(PriceService $priceService): JsonResponse
    {
        $snapshot = $priceService->getUsdToCurrencyRatesWithTimestamp(self::CURRENCIES);

        return response()->json([
            'base' => 'USD',
            'rates' => $snapshot['rates'],
            'updated_at' => $snapshot['updated_at'],
        ]);
    }
}
