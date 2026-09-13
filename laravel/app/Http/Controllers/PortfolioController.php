<?php

namespace App\Http\Controllers;

use App\Models\PortfolioEntry;
use App\Services\PriceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortfolioController extends Controller
{
    public function __construct(private PriceService $priceService)
    {

    }

    public function index(): JsonResponse
    {
        $entries = PortfolioEntry::with(['crypto', 'platform'])->get();
        $coingeckoIds = $entries->pluck('crypto.coingecko_id')->unique()->values()->toArray();
        $prices = $this->priceService->getCryptoUsdPrice($coingeckoIds);
        $eurRate = $this->priceService->getUsdToEurRate();

        $enrichedEntries = $entries->map(function (PortfolioEntry $entry) use ($prices, $eurRate) {
            $priceUsd = $prices[$entry->crypto->coingecko_id]['usd'] ?? 0;
            $valueUsd = $entry->quantity * $priceUsd;
            $valueEur = $valueUsd * $eurRate;

            return [
                'crypto' => $entry->crypto->name,
                'platform' => $entry->platform->name,
                'quantity' => $entry->quantity,
                'value_usd' => round($valueUsd, 2),
                'value_eur' => round($valueEur, 2),
            ];
        });

        $sortedEntries = $enrichedEntries->sortByDesc('value_usd')->values();
        $totalValueUsd = $sortedEntries->sum('value_usd');
        $totalValueEur = $sortedEntries->sum('value_eur');

        return response()->json([
            'data' => $sortedEntries,
            'total_value_usd' => round($totalValueUsd, 2),
            'total_value_eur' => round($totalValueEur, 2),
        ]);
    }
}
