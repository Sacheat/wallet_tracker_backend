<?php

namespace App\Http\Controllers;

use App\Enums\Currency;
use App\Http\Requests\UpdateAllocationTargetsRequest;
use App\Services\AllocationService;
use App\Services\PriceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AllocationController extends Controller
{
    public function __construct(private AllocationService $allocation) {}

    /**
     * Current split, targets, drift and rebalancing. ?contribution= is new
     * money in the account's currency, converted to USD here like an
     * alert threshold.
     */
    public function show(Request $request, PriceService $priceService): JsonResponse
    {
        $validated = $request->validate([
            'contribution' => ['sometimes', 'numeric', 'gt:0', 'max:100000000000'],
        ]);
        $user = $request->user();

        $contributionUsd = null;
        if (isset($validated['contribution'])) {
            if (empty($user->allocation_targets)) {
                return response()->json(['message' => 'Définissez d\'abord vos objectifs de répartition.', 'errors' => ['contribution' => ['Aucun objectif de répartition défini.']]], 422);
            }

            try {
                $contributionUsd = $priceService->convertToUsd((float) $validated['contribution'], $user->currency ?? Currency::Usd);
            } catch (\RuntimeException $e) {
                report($e);

                return response()->json(['message' => 'Taux de change indisponible, réessayez dans un instant.'], 503);
            }
        }

        return response()->json($this->allocation->forUser($user, $contributionUsd));
    }

    public function targets(Request $request): JsonResponse
    {
        return response()->json(['targets' => (object) ($request->user()->allocation_targets ?? [])]);
    }

    /**
     * Replaces all targets at once (they only make sense together, adding
     * up to 100). Zero targets are dropped: "no target" and "0 %" behave
     * the same, an unlisted type is aimed at 0 %.
     */
    public function updateTargets(UpdateAllocationTargetsRequest $request): JsonResponse
    {
        $targets = collect($request->validated('targets'))
            ->map(fn ($percent) => round((float) $percent, 2))
            ->filter(fn (float $percent) => $percent > 0)
            ->all();

        $request->user()->forceFill(['allocation_targets' => $targets ?: null])->save();

        return response()->json(['targets' => (object) $targets]);
    }
}
