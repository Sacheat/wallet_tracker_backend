<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePriceAlertRequest;
use App\Http\Requests\UpdatePriceAlertRequest;
use App\Models\PriceAlert;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

class PriceAlertController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request)
    {
        return PriceAlert::with('asset')
            ->where('user_id', $request->user()->id)
            ->get();
    }

    public function store(StorePriceAlertRequest $request)
    {
        return PriceAlert::create([
            ...$request->validated(),
            'user_id' => $request->user()->id,
        ]);
    }

    public function show(PriceAlert $priceAlert)
    {
        $this->authorize('view', $priceAlert);

        return $priceAlert->load('asset');
    }

    public function update(UpdatePriceAlertRequest $request, PriceAlert $priceAlert)
    {
        $this->authorize('update', $priceAlert);

        $priceAlert->update($request->validated());

        return $priceAlert;
    }

    public function destroy(PriceAlert $priceAlert)
    {
        $this->authorize('delete', $priceAlert);

        $priceAlert->delete();

        return response()->noContent();
    }
}
