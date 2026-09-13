<?php

namespace App\Http\Controllers;

use App\Http\Resources\PortfolioEntryResource;
use App\Services\PortfolioService;
use Illuminate\Http\JsonResponse;

class PortfolioController extends Controller
{
    public function __construct(private PortfolioService $portfolioService)
    {

    }

    public function index(): JsonResponse
    {
        $summary = $this->portfolioService->getPortfolioSummary();

        return response()->json([
            'data' => PortfolioEntryResource::collection($summary->entries),
            'total_value_usd' => $summary->totalValueUsd,
            'total_value_eur' => $summary->totalValueEur,
        ]);
    }
}
