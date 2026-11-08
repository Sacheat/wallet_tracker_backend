<?php

namespace App\Services;

use App\Enums\Currency;
use App\Enums\TransactionType;
use App\Models\PortfolioEntry;
use App\Models\PortfolioTransaction;
use App\Models\User;
use App\Services\PriceProviders\PriceHistoryProviderInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Rebuilds a portfolio's daily value from scratch rather than storing
 * snapshots: the quantity held on day D comes from the transactions dated
 * up to D, priced at that day's close. Nothing to schedule, the chart is
 * complete from the first request, and correcting a past trade corrects
 * the past of the curve too.
 *
 * Next to the value, the "invested" line is what the quantity held on D
 * cost, so the gap between the two is the unrealized profit over time.
 * Both are exact per currency: value at day D's exchange rate, invested at
 * each trade's own (same rules as PerformanceCalculator).
 */
class PortfolioHistoryService
{
    /** @var array<string, int> Range accepted by the endpoint => number of daily points. */
    public const RANGES = [
        '7d' => 7,
        '30d' => 30,
        '90d' => 90,
        '1y' => PriceHistoryProviderInterface::HISTORY_DAYS,
    ];

    public function __construct(
        private PriceService $priceService,
        private PerformanceCalculator $performanceCalculator,
    ) {}

    /**
     * @return array{range: string, from: string, to: string, points: array<int, array{date: string, value: array<string, float>, invested: array<string, float>|null}>, missing_history: array<int, string>}
     *
     * @throws \RuntimeException When the exchange-rate series can't be fetched.
     */
    public function forUser(User $user, string $range): array
    {
        return $this->build(PortfolioEntry::with([
            'asset',
            'transactions' => fn ($query) => $query->orderBy('executed_at')->orderBy('id'),
        ])->where('user_id', $user->id)->get(), $range);
    }

    /**
     * @param  Collection<int, PortfolioEntry>  $entries  With asset and chronologically ordered transactions loaded.
     * @return array{range: string, from: string, to: string, points: array<int, array{date: string, value: array<string, float>, invested: array<string, float>|null}>, missing_history: array<int, string>}
     *
     * @throws \RuntimeException When the exchange-rate series can't be fetched.
     */
    public function build(Collection $entries, string $range): array
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();
        $start = $today->subDays(self::RANGES[$range] - 1);
        $days = collect(range(0, self::RANGES[$range] - 1))->map(fn (int $offset) => $start->addDays($offset)->toDateString());

        // Always the full year, like the price histories: every range is a
        // slice of one cached series instead of a Frankfurter call per range.
        $yearStart = $today->subDays(PriceHistoryProviderInterface::HISTORY_DAYS - 1);
        $rates = $this->forwardFill($this->priceService->getDailyUsdRates($yearStart, $today), $days);

        $totalsUsd = array_fill_keys($days->all(), 0.0);
        $invested = array_fill_keys($days->all(), $this->zeroPerCurrency());
        $missingHistory = [];

        foreach ($entries as $entry) {
            $series = $this->entrySeries($entry, $days);
            if ($series === null) {
                $missingHistory[] = $entry->asset->name;

                continue;
            }

            foreach ($days as $day) {
                $totalsUsd[$day] += $series['value_usd'][$day];
                $invested[$day] = $this->addCost($invested[$day], $series['cost'][$day]);
            }
        }

        return [
            'range' => $range,
            'from' => $days->first(),
            'to' => $days->last(),
            'points' => $days->map(fn (string $day) => [
                'date' => $day,
                'value' => $this->inEveryCurrency($totalsUsd[$day], $rates[$day] ?? null),
                'invested' => $invested[$day] === null ? null : array_map(fn (float $cost) => round($cost, 2), $invested[$day]),
            ])->all(),
            'missing_history' => array_values(array_unique($missingHistory)),
        ];
    }

    /**
     * One position, day by day. Null when it is held during the range but
     * no price history exists for its asset: leaving it out is visible
     * (missing_history), counting it as zero would draw a fake crash.
     *
     * @param  Collection<int, string>  $days
     * @return array{value_usd: array<string, float>, cost: array<string, array<string, float>|null>}|null
     */
    private function entrySeries(PortfolioEntry $entry, Collection $days): ?array
    {
        $transactions = $entry->transactions->values();
        $provider = $this->priceService->providerFor($entry->asset->type);
        $closes = $provider instanceof PriceHistoryProviderInterface && $entry->asset->external_id
            ? $provider->getDailyUsdCloses($entry->asset->external_id)
            : [];

        $prices = $this->forwardFill($closes, $days);
        $applied = 0;
        $held = 0.0;
        $cost = [];
        $valueUsd = [];
        $costByDay = [];

        foreach ($days as $day) {
            $moved = false;
            while ($applied < $transactions->count() && $transactions[$applied]->executed_at->copy()->utc()->toDateString() <= $day) {
                $held += $this->signedQuantity($transactions[$applied]);
                $applied++;
                $moved = true;
            }
            if ($moved) {
                $cost = $this->performanceCalculator->costBasis($transactions->take($applied));
            }

            $held = max(0.0, round($held, 8));
            if ($held > 0 && ! isset($prices[$day])) {
                return null;
            }

            $valueUsd[$day] = $held > 0 ? $held * $prices[$day] : 0.0;
            $costByDay[$day] = $cost;
        }

        return ['value_usd' => $valueUsd, 'cost' => $costByDay];
    }

    /**
     * Day-indexed series → one value per requested day: a day without a
     * point (weekend for a stock, a holiday, today before the provider's
     * update) takes the last known one; days before the first point take
     * the first one, so a position bought a week before an asset's history
     * starts is still priced.
     *
     * @template T
     *
     * @param  array<string, T>  $series  Keyed by Y-m-d.
     * @param  Collection<int, string>  $days
     * @return array<string, T>
     */
    private function forwardFill(array $series, Collection $days): array
    {
        if ($series === []) {
            return [];
        }

        ksort($series);
        $last = null;
        foreach ($series as $date => $value) {
            if ($date > $days->first()) {
                break;
            }
            $last = $value;
        }
        $last ??= reset($series);

        $filled = [];
        foreach ($days as $day) {
            $last = $series[$day] ?? $last;
            $filled[$day] = $last;
        }

        return $filled;
    }

    private function signedQuantity(PortfolioTransaction $transaction): float
    {
        $quantity = (float) $transaction->quantity;

        return $transaction->type === TransactionType::Sell ? -$quantity : $quantity;
    }

    /**
     * @param  array<string, float>|null  $total  Null once any position's cost is unknown.
     * @param  array<string, float>|null  $cost  Null when this position has a trade without price; [] before its first trade.
     * @return array<string, float>|null
     */
    private function addCost(?array $total, ?array $cost): ?array
    {
        if ($total === null || $cost === null) {
            return null;
        }

        foreach ($cost as $currency => $amount) {
            $total[$currency] = ($total[$currency] ?? 0.0) + $amount;
        }

        return $total;
    }

    /**
     * @param  array<string, float>|null  $rates  USD→currency on that day.
     * @return array<string, float>
     */
    private function inEveryCurrency(float $valueUsd, ?array $rates): array
    {
        $values = [];
        foreach (Currency::cases() as $currency) {
            $rate = $currency === Currency::Usd ? 1.0 : ($rates[$currency->value] ?? null);
            if ($rate !== null) {
                $values[$currency->value] = round($valueUsd * $rate, 2);
            }
        }

        return $values;
    }

    /**
     * @return array<string, float>
     */
    private function zeroPerCurrency(): array
    {
        return array_fill_keys(array_map(fn (Currency $currency) => $currency->value, Currency::cases()), 0.0);
    }
}
