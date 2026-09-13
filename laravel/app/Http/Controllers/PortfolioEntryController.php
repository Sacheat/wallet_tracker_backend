<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePortfolioEntryRequest;
use App\Http\Requests\UpdatePortfolioEntryRequest;
use App\Models\PortfolioEntry;

class PortfolioEntryController extends Controller
{
    public function index()
    {
        return PortfolioEntry::with(['crypto', 'platform'])->get();
    }

    public function store(StorePortfolioEntryRequest $request)
    {
        return PortfolioEntry::create($request->validated());
    }

    public function show(PortfolioEntry $portfolioEntry)
    {
        return $portfolioEntry->load(['crypto', 'platform']);
    }

    public function update(UpdatePortfolioEntryRequest $request, PortfolioEntry $portfolioEntry)
    {
        $portfolioEntry->update($request->validated());

        return $portfolioEntry;
    }

    public function destroy(PortfolioEntry $portfolioEntry)
    {
        $portfolioEntry->delete();

        return response()->noContent();
    }
}