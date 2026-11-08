<?php

namespace Tests\Feature;

use App\DTO\PortfolioSummary;
use App\Enums\AssetType;
use App\Http\Requests\UpdateAllocationTargetsRequest;
use App\Models\Asset;
use App\Models\Platform;
use App\Models\PortfolioEntry;
use App\Services\AllocationService;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Allocation is pure arithmetic over the portfolio summary, so positions
 * are built in memory (no DB here, see ExchangeRatesTest).
 */
class AllocationServiceTest extends TestCase
{
    private function position(string $asset, AssetType $type, string $platform, float $valueUsd): PortfolioEntry
    {
        $entry = new PortfolioEntry;
        $entry->setRelation('asset', new Asset(['name' => $asset, 'symbol' => $asset, 'type' => $type]));
        $entry->setRelation('platform', new Platform(['name' => $platform]));
        $entry->value_usd = $valueUsd;

        return $entry;
    }

    /**
     * 10 000 USD: crypto 6 000 (Binance), ETF 3 000 + stock 1 000 (Trade Republic).
     */
    private function summary(): PortfolioSummary
    {
        return new PortfolioSummary(
            entries: collect([
                $this->position('Bitcoin', AssetType::Crypto, 'Binance', 4500),
                $this->position('Ethereum', AssetType::Crypto, 'Binance', 1500),
                $this->position('MSCI World', AssetType::Etf, 'Trade Republic', 3000),
                $this->position('Apple', AssetType::Stock, 'Trade Republic', 1000),
            ]),
            totalValueUsd: 10000,
            totalValueEur: 9000,
            changePercent: [],
            totalValueByCurrency: [],
        );
    }

    public function test_current_split_by_type_platform_and_asset(): void
    {
        $allocation = app(AllocationService::class)->build($this->summary(), [], null);

        $this->assertFalse($allocation['has_targets']);
        $this->assertNull($allocation['max_drift']);
        $this->assertSame(['crypto', 'etf', 'stock'], array_column($allocation['by_type'], 'type'));
        $this->assertSame([60.0, 30.0, 10.0], array_column($allocation['by_type'], 'percent'));
        $this->assertNull($allocation['by_type'][0]['target_percent']);
        $this->assertSame([['platform' => 'Binance', 'value_usd' => 6000.0, 'percent' => 60.0], ['platform' => 'Trade Republic', 'value_usd' => 4000.0, 'percent' => 40.0]], $allocation['by_platform']);
        $this->assertSame('Bitcoin', $allocation['by_asset'][0]['asset']);
        $this->assertSame(45.0, $allocation['by_asset'][0]['percent']);
    }

    public function test_drift_and_full_rebalance_against_targets(): void
    {
        // Gold is targeted but not held: it shows up, at 0 %.
        $allocation = app(AllocationService::class)->build($this->summary(), ['crypto' => 40, 'etf' => 50, 'stock' => 5, 'gold' => 5], null);
        $byType = collect($allocation['by_type'])->keyBy('type');

        $this->assertSame(20.0, $byType['crypto']['drift']);
        $this->assertSame(-20.0, $byType['etf']['drift']);
        $this->assertSame(20.0, $allocation['max_drift']);
        $this->assertSame(-2000.0, $byType['crypto']['rebalance_usd']);
        $this->assertSame(2000.0, $byType['etf']['rebalance_usd']);
        $this->assertSame(-500.0, $byType['stock']['rebalance_usd']);
        $this->assertSame(500.0, $byType['gold']['rebalance_usd']);
        $this->assertNull($byType['gold']['contribution_usd']);
    }

    public function test_new_money_only_goes_to_underweight_types(): void
    {
        // 2 000 more → 12 000 total. Gaps to target: etf 6 000 − 3 000 = 3 000,
        // gold 600 − 0 = 600; crypto and stock are already above. The 2 000
        // are split 3 000 : 600, i.e. 1 666.67 / 333.33.
        $allocation = app(AllocationService::class)->build($this->summary(), ['crypto' => 40, 'etf' => 50, 'stock' => 5, 'gold' => 5], 2000);
        $byType = collect($allocation['by_type'])->keyBy('type');

        $this->assertSame(1666.67, $byType['etf']['contribution_usd']);
        $this->assertSame(333.33, $byType['gold']['contribution_usd']);
        $this->assertSame(0.0, $byType['crypto']['contribution_usd']);
        $this->assertSame(0.0, $byType['stock']['contribution_usd']);
    }

    public function test_a_held_type_without_target_is_aimed_at_zero(): void
    {
        $allocation = app(AllocationService::class)->build($this->summary(), ['crypto' => 50, 'etf' => 50], null);
        $stock = collect($allocation['by_type'])->firstWhere('type', 'stock');

        $this->assertSame(0.0, $stock['target_percent']);
        $this->assertSame(-1000.0, $stock['rebalance_usd']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validate(array $data): \Illuminate\Contracts\Validation\Validator
    {
        $request = UpdateAllocationTargetsRequest::create('/api/allocation-targets', 'PUT', $data);
        $validator = Validator::make($data, $request->rules(), $request->messages());

        return $validator->after($request->after());
    }

    public function test_targets_must_add_up_to_one_hundred(): void
    {
        $this->assertFalse($this->validate(['targets' => ['crypto' => 33.33, 'etf' => 33.33, 'stock' => 33.34]])->fails());
        $this->assertFalse($this->validate(['targets' => []])->fails());

        $tooLow = $this->validate(['targets' => ['crypto' => 50, 'etf' => 40]]);
        $this->assertTrue($tooLow->fails());
        $this->assertStringContainsString('100 %', $tooLow->errors()->first('targets'));
    }

    public function test_targets_only_accept_known_asset_types_and_sane_percentages(): void
    {
        $this->assertTrue($this->validate(['targets' => ['crypto' => 50, 'bonds' => 50]])->fails());
        $this->assertTrue($this->validate(['targets' => ['crypto' => 150, 'etf' => -50]])->fails());
        $this->assertTrue($this->validate([])->fails());
    }
}
