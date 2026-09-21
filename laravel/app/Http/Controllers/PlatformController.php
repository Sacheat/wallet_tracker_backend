<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePlatformRequest;
use App\Http\Requests\UpdatePlatformRequest;
use App\Models\Platform;

class PlatformController extends Controller
{
    public function index()
    {
        return Platform::all();
    }

    public function store(StorePlatformRequest $request)
    {
        return Platform::create($request->validated());
    }

    public function show(Platform $platform)
    {
        return $platform;
    }

    public function update(UpdatePlatformRequest $request, Platform $platform)
    {
        $platform->update($request->validated());

        return $platform;
    }

    public function destroy(Platform $platform)
    {
        if ($platform->portfolioEntries()->exists()) {
            return response()->json([
                'message' => 'Cette plateforme est utilisée dans au moins un portefeuille (potentiellement d\'un autre utilisateur) et ne peut pas être supprimée.',
            ], 409);
        }

        $platform->delete();

        return response()->noContent();
    }
}
