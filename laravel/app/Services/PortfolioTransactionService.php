<?php

namespace App\Services;

use App\Enums\Currency;
use App\Enums\TransactionType;
use App\Models\PortfolioEntry;
use App\Models\PortfolioTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Single write path for the buy/sell journal. Every change replays the
 * position's transactions in chronological order so a sell can never take
 * the holding below zero at any point in time (selling on March 1st what
 * was only bought on March 5th is rejected), then stores the resulting
 * total in portfolio_entries.quantity, the cached value every reader uses.
 */
class PortfolioTransactionService
{
    /**
     * Below this, a running quantity is float noise (0.1 + 0.2 - 0.3), not
     * a real oversell: quantities are stored with 8 decimals.
     */
    private const QUANTITY_EPSILON = 1e-9;

    public function __construct(private PriceService $priceService) {}

    /**
     * Opens a position with its first buy. Price is optional here so the
     * existing "add a position" form keeps working unchanged; profit then
     * shows as unknown until a price is filled in on that transaction.
     *
     * @param  array{asset_id: int, platform_id: int, quantity: numeric-string|float, unit_price?: numeric-string|float|null, fee?: numeric-string|float|null, currency?: string|null, executed_at?: string|null}  $data
     */
    public function openPosition(User $user, array $data): PortfolioEntry
    {
        $transaction = $this->prepare([
            'type' => TransactionType::Buy->value,
            'quantity' => $data['quantity'],
            'unit_price' => $data['unit_price'] ?? null,
            'fee' => $data['fee'] ?? 0,
            'currency' => $data['currency'] ?? $user->currency?->value ?? Currency::Usd->value,
            'executed_at' => $data['executed_at'] ?? now(),
        ]);

        return DB::transaction(function () use ($user, $data, $transaction) {
            $entry = $user->portfolioEntries()->create([
                'asset_id' => $data['asset_id'],
                'platform_id' => $data['platform_id'],
                'quantity' => $data['quantity'],
            ]);
            $transaction->portfolioEntry()->associate($entry)->save();

            return $entry;
        });
    }

    /**
     * @param  array{type: string, quantity: numeric-string|float, unit_price: numeric-string|float, fee?: numeric-string|float|null, currency?: string|null, executed_at: string}  $data
     */
    public function record(PortfolioEntry $entry, array $data): PortfolioTransaction
    {
        $transaction = $this->prepare([
            ...$data,
            'fee' => $data['fee'] ?? 0,
            'currency' => $data['currency'] ?? $entry->user->currency?->value ?? Currency::Usd->value,
        ]);

        return $this->withLockedEntry($entry, function (PortfolioEntry $entry) use ($transaction) {
            $transaction->portfolioEntry()->associate($entry)->save();

            return $transaction;
        });
    }

    /**
     * @param  array<string, mixed>  $data  Validated subset of the transaction's fields.
     */
    public function update(PortfolioTransaction $transaction, array $data): PortfolioTransaction
    {
        $transaction->fill($data);
        $this->refreshFxRates($transaction);

        return $this->withLockedEntry($transaction->portfolioEntry, function () use ($transaction) {
            $transaction->save();

            return $transaction;
        });
    }

    public function delete(PortfolioTransaction $transaction): void
    {
        $this->withLockedEntry($transaction->portfolioEntry, fn () => $transaction->delete());
    }

    /**
     * Net quantity after replaying trades oldest first.
     *
     * @param  iterable<array{type: TransactionType, quantity: float|string}>  $transactions  Already in chronological order.
     *
     * @throws \DomainException With the offending index when a sell exceeds what was held at that point.
     */
    public static function replay(iterable $transactions): float
    {
        $held = 0.0;
        foreach ($transactions as $index => $transaction) {
            $quantity = (float) $transaction['quantity'];
            $held += $transaction['type'] === TransactionType::Sell ? -$quantity : $quantity;
            if ($held < -self::QUANTITY_EPSILON) {
                throw new \DomainException((string) $index);
            }
        }

        return max(0.0, round($held, 8));
    }

    /**
     * Builds an unsaved transaction with its exchange rates resolved. Kept
     * outside the DB transaction: an HTTP call must not hold a row lock.
     *
     * @param  array<string, mixed>  $data
     */
    private function prepare(array $data): PortfolioTransaction
    {
        $transaction = new PortfolioTransaction($data);
        $this->refreshFxRates($transaction);

        return $transaction;
    }

    /**
     * Rates follow the trade date and are only needed once a price exists.
     * Refetched when the date changes; a currency change alone needs none,
     * since all three non-USD rates are always stored together.
     */
    private function refreshFxRates(PortfolioTransaction $transaction): void
    {
        if ($transaction->unit_price === null) {
            $transaction->fx_rates = null;

            return;
        }

        if ($transaction->fx_rates !== null && ! $transaction->isDirty('executed_at')) {
            return;
        }

        try {
            $transaction->fx_rates = $this->priceService->getHistoricalUsdRates(
                CarbonImmutable::parse($transaction->executed_at)
            );
        } catch (\RuntimeException $e) {
            report($e);
            throw new ServiceUnavailableHttpException(null, 'Taux de change historiques indisponibles, réessayez dans un instant.', $e);
        }
    }

    /**
     * Applies $write under a row lock on the position (two concurrent sells
     * can't both pass the check), then replays the journal and rolls the
     * whole write back if any point in time ends up below zero.
     *
     * @template T
     *
     * @param  callable(PortfolioEntry): T  $write
     * @return T
     */
    private function withLockedEntry(PortfolioEntry $entry, callable $write): mixed
    {
        return DB::transaction(function () use ($entry, $write) {
            $entry = PortfolioEntry::whereKey($entry->getKey())->lockForUpdate()->firstOrFail();
            $result = $write($entry);

            $transactions = $entry->transactions()->orderBy('executed_at')->orderBy('id')->get();
            try {
                $quantity = self::replay($transactions->map(fn (PortfolioTransaction $t) => [
                    'type' => $t->type,
                    'quantity' => $t->quantity,
                ]));
            } catch (\DomainException $e) {
                $date = $transactions[(int) $e->getMessage()]->executed_at->toDateString();
                throw ValidationException::withMessages([
                    'quantity' => "Vente impossible : au {$date}, la quantité détenue sur cette position est insuffisante.",
                ]);
            }

            $entry->update(['quantity' => $quantity]);

            return $result;
        });
    }
}
