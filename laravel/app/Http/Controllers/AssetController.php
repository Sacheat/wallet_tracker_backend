<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAssetRequest;
use App\Http\Requests\UpdateAssetRequest;
use App\Models\Asset;
use App\Services\AssetDetailService;
use App\Services\AssetSearchService;
use App\Services\NewsService;
use Illuminate\Http\Request;

class AssetController extends Controller
{
    public function index()
    {
        return Asset::all();
    }

    public function search(Request $request, AssetSearchService $assetSearchService)
    {
        $validated = $request->validate(['q' => 'required|string|min:2|max:100']);

        return ['data' => $assetSearchService->search($validated['q'])];
    }

    public function details(Asset $asset, AssetDetailService $assetDetailService)
    {
        return $assetDetailService->getDetails($asset);
    }

    public function news(Asset $asset, NewsService $newsService)
    {
        return $newsService->getNewsForAsset($asset);
    }

    public function store(StoreAssetRequest $request)
    {
        return Asset::create($request->validated());
    }

    public function show(Asset $asset)
    {
        return $asset;
    }

    public function update(UpdateAssetRequest $request, Asset $asset)
    {
        $asset->update($request->validated());

        return $asset;
    }

    public function destroy(Asset $asset)
    {
        if ($asset->portfolioEntries()->exists() || $asset->priceAlerts()->exists()) {
            return response()->json([
                'message' => 'Cet actif est utilisé dans au moins un portefeuille ou une alerte (potentiellement d\'un autre utilisateur) et ne peut pas être supprimé.',
            ], 409);
        }

        $asset->delete();

        return response()->noContent();
    }
}
