<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePlatformRequest;
use App\Http\Requests\UpdatePlatformRequest;
use App\Models\Platform;
use App\Services\LogoStorage;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

class PlatformController extends Controller
{
    use AuthorizesRequests;

    public function index()
    {
        return Platform::all();
    }

    public function store(StorePlatformRequest $request)
    {
        $platform = new Platform($request->validated());
        // Server-side only (not fillable): what CatalogPolicy checks to let
        // the creator keep editing until another user relies on the entry.
        $platform->created_by = $request->user()->id;
        $platform->save();

        return $platform;
    }

    public function show(Platform $platform)
    {
        return $platform;
    }

    public function update(UpdatePlatformRequest $request, Platform $platform)
    {
        $this->authorize('update', $platform);

        $platform->update($request->validated());

        return $platform;
    }

    public function storeLogo(Request $request, Platform $platform, LogoStorage $logoStorage)
    {
        $this->authorize('update', $platform);
        $request->validate(['logo' => LogoStorage::RULES]);

        return $logoStorage->replace($platform, $request->file('logo'));
    }

    public function destroyLogo(Platform $platform, LogoStorage $logoStorage)
    {
        $this->authorize('update', $platform);

        $logoStorage->remove($platform);

        return response()->noContent();
    }

    public function destroy(Platform $platform)
    {
        // Policy first (who may delete), then the 409 below (whether it
        // can be deleted at all): even an admin can't pull an entry out
        // from under a portfolio.
        $this->authorize('delete', $platform);

        if ($platform->portfolioEntries()->exists()) {
            return response()->json([
                'message' => 'Cette plateforme est utilisée dans au moins un portefeuille (potentiellement d\'un autre utilisateur) et ne peut pas être supprimée.',
            ], 409);
        }

        $platform->delete();

        return response()->noContent();
    }
}
