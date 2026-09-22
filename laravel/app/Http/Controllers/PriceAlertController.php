<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePriceAlertRequest;
use App\Http\Requests\UpdatePriceAlertRequest;
use App\Models\PriceAlert;
use App\Models\User;
use App\Services\PriceService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

class PriceAlertController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private PriceService $priceService) {}

    public function index(Request $request)
    {
        return PriceAlert::with('asset')
            ->where('user_id', $request->user()->id)
            ->get();
    }

    public function store(StorePriceAlertRequest $request)
    {
        $data = $request->validated();

        if (isset($data['threshold'])) {
            $data['threshold'] = $this->convertThresholdToUsd($data['threshold'], $request->user());
        }

        return $request->user()->priceAlerts()->create($data);
    }

    public function show(PriceAlert $priceAlert)
    {
        $this->authorize('view', $priceAlert);

        return $priceAlert->load('asset');
    }

    public function update(UpdatePriceAlertRequest $request, PriceAlert $priceAlert)
    {
        $this->authorize('update', $priceAlert);

        $data = $request->validated();

        if (isset($data['threshold'])) {
            $data['threshold'] = $this->convertThresholdToUsd($data['threshold'], $request->user());
        }

        $priceAlert->update($data);

        return $priceAlert;
    }

    /**
     * threshold is entered by the user in their account currency (price_above/
     * below and portfolio_total_above/below all share this field) but stored
     * and compared in USD, like every other price in this app. USD accounts
     * never hit PriceService's external call. A non-USD account with no live
     * or cached rate gets a clean 503 rather than a raw 500 — this is a form
     * submission, the frontend needs an actionable failure to show.
     */
    private function convertThresholdToUsd(float $threshold, User $user): float
    {
        try {
            return $this->priceService->convertToUsd($threshold, $user->currency);
        } catch (\RuntimeException $e) {
            abort(503, 'Impossible de récupérer le taux de change pour convertir le seuil dans votre devise. Réessayez dans quelques instants.');
        }
    }

    public function destroy(PriceAlert $priceAlert)
    {
        $this->authorize('delete', $priceAlert);

        $priceAlert->delete();

        return response()->noContent();
    }
}
