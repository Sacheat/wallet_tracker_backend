<?php

namespace Tests\Feature;

use App\Enums\AssetType;
use App\Models\Asset;
use App\Services\PriceService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The five-minute refresh behind alerts:check. Assets are in-memory models
 * (no DB here, see ExchangeRatesTest).
 */
class PriceRefreshTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake([
            'api.coingecko.com/*' => function (Request $request) {
                return Http::response(collect(explode(',', $request['ids']))
                    ->map(fn (string $id) => ['id' => $id, 'current_price' => 100.0])
                    ->all());
            },
            'query1.finance.yahoo.com/v8/finance/spark*' => function (Request $request) {
                return Http::response(collect(explode(',', $request['symbols']))
                    ->mapWithKeys(fn (string $symbol) => [$symbol => ['timestamp' => [now()->timestamp], 'close' => [42.0]]])
                    ->all());
            },
        ]);
    }

    private function asset(string $externalId, AssetType $type): Asset
    {
        return new Asset(['name' => $externalId, 'symbol' => $externalId, 'type' => $type, 'external_id' => $externalId]);
    }

    public function test_one_call_per_provider_and_stocks_etfs_and_gold_share_the_yahoo_batch(): void
    {
        $service = app(PriceService::class);
        $assets = collect([
            $this->asset('bitcoin', AssetType::Crypto),
            $this->asset('ethereum', AssetType::Crypto),
            $this->asset('AAPL', AssetType::Stock),
            $this->asset('CW8.PA', AssetType::Etf),
            $this->asset('GC=F', AssetType::Gold),
        ]);

        $service->refreshPrices($assets);
        $prices = $service->getUsdPrices($assets);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'spark') && $request['symbols'] === 'AAPL,CW8.PA,GC=F');
        $this->assertEquals(100.0, $prices['ethereum']);
        $this->assertEquals(42.0, $prices['GC=F']);
    }
}
