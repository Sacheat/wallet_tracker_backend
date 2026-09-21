<?php

namespace App\Http\Controllers;

use App\Http\Resources\PortfolioEntryResource;
use App\Services\NewsService;
use App\Services\PortfolioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
        ]);
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
