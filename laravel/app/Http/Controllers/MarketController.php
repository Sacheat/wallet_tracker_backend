<?php

namespace App\Http\Controllers;

use App\Services\FearGreedIndexService;

class MarketController extends Controller
{
    public function fearGreedIndex(FearGreedIndexService $fearGreedIndexService)
    {
        return $fearGreedIndexService->getCurrent() ?? response()->json(['message' => 'Indice indisponible pour le moment.'], 503);
    }
}
