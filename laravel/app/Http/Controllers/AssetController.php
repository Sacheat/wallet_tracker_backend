<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAssetRequest;
use App\Http\Requests\UpdateAssetRequest;
use App\Models\Asset;
use App\Services\AssetDetailService;
use App\Services\AssetSearchService;
use App\Services\LogoStorage;
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

    public function details(Request $request, Asset $asset, AssetDetailService $assetDetailService)
    {
        $locale = $request->validate([
            'locale' => 'nullable|string|regex:/^[a-z]{2}(-[a-z]{2})?$/i|max:5',
        ])['locale'] ?? 'fr';

        return $assetDetailService->getDetails($asset, $locale);
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

    public function storeLogo(Request $request, Asset $asset, LogoStorage $logoStorage)
    {
        $request->validate(['logo' => LogoStorage::RULES]);

        return $logoStorage->replace($asset, $request->file('logo'));
    }

    public function destroyLogo(Asset $asset, LogoStorage $logoStorage)
    {
        $logoStorage->remove($asset);

        return response()->noContent();
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
