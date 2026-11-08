<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePortfolioTransactionRequest;
use App\Http\Requests\UpdatePortfolioTransactionRequest;
use App\Http\Resources\PortfolioTransactionResource;
use App\Models\PortfolioEntry;
use App\Models\PortfolioTransaction;
use App\Services\PortfolioTransactionService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * A transaction has no owner of its own: access follows the position it
 * belongs to, through PortfolioEntryPolicy.
 */
class PortfolioTransactionController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private PortfolioTransactionService $transactions) {}

    public function index(PortfolioEntry $portfolioEntry): AnonymousResourceCollection
    {
        $this->authorize('view', $portfolioEntry);

        return PortfolioTransactionResource::collection(
            $portfolioEntry->transactions()->orderByDesc('executed_at')->orderByDesc('id')->get()
        );
    }

    public function store(StorePortfolioTransactionRequest $request, PortfolioEntry $portfolioEntry): PortfolioTransactionResource
    {
        $this->authorize('update', $portfolioEntry);

        return new PortfolioTransactionResource(
            $this->transactions->record($portfolioEntry, $request->validated())
        );
    }

    public function update(UpdatePortfolioTransactionRequest $request, PortfolioTransaction $portfolioTransaction): PortfolioTransactionResource
    {
        $this->authorize('update', $portfolioTransaction->portfolioEntry);

        return new PortfolioTransactionResource(
            $this->transactions->update($portfolioTransaction, $request->validated())
        );
    }

    public function destroy(PortfolioTransaction $portfolioTransaction): Response
    {
        $this->authorize('update', $portfolioTransaction->portfolioEntry);

        $this->transactions->delete($portfolioTransaction);

        return response()->noContent();
    }
}
