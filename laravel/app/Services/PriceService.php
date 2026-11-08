<?php

namespace App\Services;

use App\Enums\AssetType;
use App\Enums\Currency;
use App\Models\Asset;
use App\Services\PriceProviders\CoinGeckoPriceProvider;
use App\Services\PriceProviders\PriceProviderInterface;
use App\Services\PriceProviders\YahooFinancePriceProvider;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PriceService
{
    /** @var array<string, PriceProviderInterface> */
    private array $providers;

    public function __construct(
        CoinGeckoPriceProvider $coinGeckoPriceProvider,
        YahooFinancePriceProvider $yahooFinancePriceProvider,
    ) {
        $this->providers = [
            AssetType::Crypto->value => $coinGeckoPriceProvider,
            AssetType::Stock->value => $yahooFinancePriceProvider,
            AssetType::Etf->value => $yahooFinancePriceProvider,
            AssetType::Gold->value => $yahooFinancePriceProvider,
        ];
    }

    public function getUsdToEurRate(): float
    {
        return $this->getUsdToCurrencyRates(['EUR'])['EUR'];
    }

    /**
     * Frankfurter's /v2/rates has no filter param (a "symbols" query string
     * returns a 422 — confirmed live, not assumed): it always returns every
     * currency, so this fetches once and picks out whichever are asked for.
     *
     * @param  array<int, string>  $currencies  ISO codes, e.g. ['EUR', 'GBP', 'CHF'].
     * @return array<string, float|null> Keyed by currency; null if that currency wasn't in the response.
     */
    public function getUsdToCurrencyRates(array $currencies): array
    {
        $rates = $this->fetchUsdRates()['rates'];

        return collect($currencies)
            ->mapWithKeys(fn (string $currency) => [$currency => $rates[$currency] ?? null])
            ->all();
    }

    /**
     * Same source and cache as getUsdToCurrencyRates(), plus the moment those
     * rates were fetched — for consumers that need to show/compare freshness
     * (e.g. the exchange-rates endpoint), not just the values.
     *
     * @param  array<int, string>  $currencies
     * @return array{rates: array<string, float|null>, updated_at: string}
     */
    public function getUsdToCurrencyRatesWithTimestamp(array $currencies): array
    {
        $snapshot = $this->fetchUsdRates();

        return [
            'rates' => collect($currencies)
                ->mapWithKeys(fn (string $currency) => [$currency => $snapshot['rates'][$currency] ?? null])
                ->all(),
            'updated_at' => $snapshot['updated_at'],
        ];
    }

    /**
     * USD short-circuits with zero cache/HTTP lookup — most accounts default
     * to it, and it should never depend on Frankfurter being up. Throws
     * RuntimeException (from fetchUsdRates()) if a live rate genuinely can't
     * be found for a non-USD amount; callers decide how to surface that.
     */
    public function convertToUsd(float $amount, Currency $currency): float
    {
        if ($currency === Currency::Usd) {
            return $amount;
        }

        $rate = $this->getUsdToCurrencyRates([$currency->value])[$currency->value];

        return round($amount / $rate, 8);
    }

    /**
     * USD→EUR/GBP/CHF on a given past day, for pricing a trade at the rate
     * of its own date. A past day's rates never change, so they are cached
     * for good; today's are refreshed hourly. Frankfurter's v2 `quotes`
     * filter does work (checked live 2026-11-08), unlike `symbols`; its
     * EUR history starts on 1999-01-04, which callers enforce.
     *
     * @return array<string, float> Keyed by currency code, USD excluded.
     *
     * @throws \RuntimeException When Frankfurter can't be reached or lacks a rate.
     */
    public function getHistoricalUsdRates(CarbonInterface $date): array
    {
        $day = $date->copy()->utc()->toDateString();
        $quotes = collect(Currency::cases())
            ->reject(fn (Currency $currency) => $currency === Currency::Usd)
            ->map(fn (Currency $currency) => $currency->value)
            ->values();

        $fetch = function () use ($day, $quotes) {
            try {
                $response = Http::get('https://api.frankfurter.dev/v2/rates', [
                    'base' => 'USD',
                    'date' => $day,
                    'quotes' => $quotes->implode(','),
                ])->throw();
            } catch (\Exception $e) {
                throw new \RuntimeException("Taux de change du {$day} indisponibles", 0, $e);
            }

            $rates = collect($response->json())->pluck('rate', 'quote')->map(fn ($rate) => (float) $rate);
            if ($quotes->diff($rates->keys())->isNotEmpty()) {
                throw new \RuntimeException("Taux de change du {$day} incomplets");
            }

            return $rates->only($quotes)->all();
        };

        $key = "usd_exchange_rates_on_{$day}";

        return $day === now()->utc()->toDateString()
            ? Cache::remember($key, 3600, $fetch)
            : Cache::rememberForever($key, $fetch);
    }

    /**
     * USD→EUR/GBP/CHF for every day of a range, in one Frankfurter call
     * (weekends included, checked live 2026-11-08). Cached an hour: the
     * range ends today, whose rate still moves.
     *
     * @return array<string, array<string, float>> Keyed by date (Y-m-d), then currency code, USD excluded.
     *
     * @throws \RuntimeException When Frankfurter can't be reached.
     */
    public function getDailyUsdRates(CarbonInterface $from, CarbonInterface $to): array
    {
        $from = $from->copy()->utc()->toDateString();
        $to = $to->copy()->utc()->toDateString();
        $quotes = collect(Currency::cases())
            ->reject(fn (Currency $currency) => $currency === Currency::Usd)
            ->map(fn (Currency $currency) => $currency->value)
            ->implode(',');

        return Cache::remember("usd_exchange_rates_{$from}_{$to}", 3600, function () use ($from, $to, $quotes) {
            try {
                $response = Http::get('https://api.frankfurter.dev/v2/rates', [
                    'base' => 'USD',
                    'from' => $from,
                    'to' => $to,
                    'quotes' => $quotes,
                ])->throw();
            } catch (\Exception $e) {
                throw new \RuntimeException("Taux de change du {$from} au {$to} indisponibles", 0, $e);
            }

            $series = [];
            foreach ($response->json() as $row) {
                if (isset($row['date'], $row['quote'], $row['rate'])) {
                    $series[$row['date']][$row['quote']] = (float) $row['rate'];
                }
            }
            ksort($series);

            return $series;
        });
    }

    /**
     * @return array{rates: array<string, float>, updated_at: string}
     */
    private function fetchUsdRates(): array
    {
        return Cache::remember('usd_exchange_rates', 45, function () {
            try {
                $response = Http::get('https://api.frankfurter.dev/v2/rates', [
                    'base' => 'USD',
                ]);

                $response->throw();
                $rates = collect($response->json())->pluck('rate', 'quote')->toArray();
                $snapshot = ['rates' => $rates, 'updated_at' => now()->toIso8601String()];
                Cache::forever('usd_exchange_rates_backup', $snapshot);

                return $snapshot;

            } catch (\Exception $e) {
                if (Cache::has('usd_exchange_rates_backup')) {
                    return Cache::get('usd_exchange_rates_backup');
                }
                throw new \RuntimeException('Impossible de récupérer les taux de change USD', 0, $e);
            }
        });
    }

    /**
     * Resolve the current USD price for each asset, dispatching to the
     * provider registered for its type and merging the results.
     *
     * @param  Collection<int, Asset>  $assets
     * @return array<string, float|null> Keyed by the asset's external_id.
     */
    public function getUsdPrices(Collection $assets): array
    {
        $prices = [];

        foreach ($assets->groupBy(fn ($asset) => $asset->type->value) as $type => $assetsOfType) {
            $provider = $this->providers[$type] ?? null;
            $externalIds = $assetsOfType->pluck('external_id')->filter()->unique()->values()->toArray();

            if (! $provider || empty($externalIds)) {
                continue;
            }

            $prices += $provider->getUsdPrices($externalIds);
        }

        return $prices;
    }

    public function providerFor(AssetType $type): PriceProviderInterface
    {
        return $this->providers[$type->value];
    }
}
