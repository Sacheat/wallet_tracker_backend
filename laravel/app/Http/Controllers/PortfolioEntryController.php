<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePortfolioEntryRequest;
use App\Http\Requests\UpdatePortfolioEntryRequest;
use App\Models\PortfolioEntry;
use App\Services\PortfolioTransactionService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

class PortfolioEntryController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request)
    {
        return PortfolioEntry::with(['asset', 'platform'])
            ->where('user_id', $request->user()->id)
            ->get();
    }

    public function store(StorePortfolioEntryRequest $request, PortfolioTransactionService $transactions)
    {
        return $transactions->openPosition($request->user(), $request->validated());
    }

    public function show(PortfolioEntry $portfolioEntry)
    {
        $this->authorize('view', $portfolioEntry);

        return $portfolioEntry->load(['asset', 'platform']);
    }

    public function update(UpdatePortfolioEntryRequest $request, PortfolioEntry $portfolioEntry)
    {
        $this->authorize('update', $portfolioEntry);

        $portfolioEntry->update($request->validated());

        return $portfolioEntry;
    }

    public function destroy(PortfolioEntry $portfolioEntry)
    {
        $this->authorize('delete', $portfolioEntry);

        $portfolioEntry->delete();

        return response()->noContent();
    }
}
