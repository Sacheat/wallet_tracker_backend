<?php

namespace App\Http\Controllers;

use App\Http\Resources\PortfolioEntryResource;
use App\Services\NewsService;
use App\Services\PortfolioHistoryService;
use App\Services\PortfolioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PortfolioController extends Controller
{
    public function __construct(private PortfolioService $portfolioService) {}

    public function index(Request $request): JsonResponse
    {
        $summary = $this->portfolioService->getPortfolioSummary($request->user());

        return response()->json([
            'data' => PortfolioEntryResource::collection($summary->entries),
            'total_value_usd' => $summary->totalValueUsd,
            'total_value_eur' => $summary->totalValueEur,
            'change_percent' => $summary->changePercent,
            'total_value_by_currency' => $summary->totalValueByCurrency,
            'performance' => $summary->performance,
            'positions_without_price' => $summary->positionsWithoutPrice,
        ]);
    }

    /**
     * Daily value of the portfolio over a range, rebuilt from transactions
     * and historical closes (see PortfolioHistoryService).
     */
    public function history(Request $request, PortfolioHistoryService $history): JsonResponse
    {
        $validated = $request->validate([
            'range' => ['sometimes', Rule::in(array_keys(PortfolioHistoryService::RANGES))],
        ]);

        try {
            return response()->json($history->forUser($request->user(), $validated['range'] ?? '30d'));
        } catch (\RuntimeException $e) {
            report($e);

            return response()->json(['message' => 'Historique indisponible pour le moment (taux de change), réessayez dans un instant.'], 503);
        }
    }

    public function news(Request $request, NewsService $newsService): JsonResponse
    {
        $summary = $this->portfolioService->getPortfolioSummary($request->user());
        $assets = $summary->entries->pluck('asset');

        return response()->json([
            'data' => $newsService->getNewsForAssets($assets),
        ]);
    }
}
