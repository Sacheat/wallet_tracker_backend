<?php

namespace Tests\Feature;

use App\DTO\PortfolioSummary;
use App\Models\Asset;
use App\Models\Platform;
use App\Models\PortfolioEntry;
use App\Models\User;
use App\Services\GeminiSummaryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * No DB here (see ExchangeRatesTest) — entries are in-memory PortfolioEntry
 * models, never saved, with value_usd set the same way PortfolioService does.
 */
class GeminiSummaryServiceTest extends TestCase
{
    private const GEMINI_URL = 'generativelanguage.googleapis.com/*';

    private const FEAR_GREED_URL = 'api.alternative.me/fng/*';

    private const NEWS_URL = 'min-api.cryptocompare.com/*';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        // No CryptoCompare key in this environment's .env means NewsService
        // returns [] before ever making a request — nothing to fake for it
        // unless a test explicitly wants news in the prompt.
        config(['services.cryptocompare.key' => null]);
    }

    private function summary(float $totalValueUsd = 12450.0): PortfolioSummary
    {
        $asset = new Asset(['name' => 'Bitcoin', 'symbol' => 'BTC']);
        $asset->id = 1;
        $platform = new Platform(['name' => 'Binance']);
        $platform->id = 1;

        $entry = new PortfolioEntry(['quantity' => 1]);
        $entry->id = 1;
        $entry->setRelation('asset', $asset);
        $entry->setRelation('platform', $platform);
        $entry->value_usd = $totalValueUsd;
        $entry->value_eur = $totalValueUsd * 0.9;

        return new PortfolioSummary(
            entries: new Collection([$entry]),
            totalValueUsd: $totalValueUsd,
            totalValueEur: $totalValueUsd * 0.9,
            changePercent: ['1h' => 0.5, '24h' => 3.2, '7d' => null],
            totalValueByCurrency: ['USD' => $totalValueUsd],
        );
    }

    private function fakeFearGreed(): void
    {
        Http::fake([
            self::FEAR_GREED_URL => Http::response([
                'data' => [['value' => '78', 'value_classification' => 'Greed', 'timestamp' => now()->timestamp]],
            ]),
        ]);
    }

    public function test_without_an_api_key_it_returns_null_without_any_request(): void
    {
        config(['services.gemini.key' => null]);
        $this->fakeFearGreed();
        $service = app(GeminiSummaryService::class);

        $result = $service->summarizeDigest($this->summary(), new User, 'daily');

        $this->assertNull($result);
    }

    public function test_an_empty_portfolio_returns_null_without_any_request(): void
    {
        config(['services.gemini.key' => 'fake-key']);
        $service = app(GeminiSummaryService::class);
        $empty = new PortfolioSummary(new Collection, 0, 0, [], []);

        $result = $service->summarizeDigest($empty, new User, 'daily');

        $this->assertNull($result);
    }

    public function test_a_response_split_across_multiple_parts_is_concatenated(): void
    {
        config(['services.gemini.key' => 'fake-key']);
        $this->fakeFearGreed();
        Http::fake([
            self::GEMINI_URL => Http::response([
                'candidates' => [[
                    'content' => [
                        'parts' => [
                            ['text' => 'Votre portefeuille progresse'],
                            ['text' => ' de 3,2% aujourd\'hui.'],
                        ],
                    ],
                ]],
            ]),
        ]);
        $service = app(GeminiSummaryService::class);

        $result = $service->summarizeDigest($this->summary(), new User, 'daily');

        $this->assertSame("Votre portefeuille progresse de 3,2% aujourd'hui.", $result);
    }

    public function test_result_is_cached_per_user_and_frequency_so_a_second_channel_makes_no_extra_call(): void
    {
        config(['services.gemini.key' => 'fake-key']);
        $this->fakeFearGreed();
        Http::fake([
            self::GEMINI_URL => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'Résumé.']]]]],
            ]),
        ]);
        $service = app(GeminiSummaryService::class);
        $user = new User;
        $user->id = 42;

        $service->summarizeDigest($this->summary(), $user, 'daily');
        $service->summarizeDigest($this->summary(), $user, 'daily');

        // 2 total: the fear-greed lookup + the Gemini call, both only on the
        // first, cache-missed run — the second summarizeDigest() call makes
        // neither, which is what proves the per-user/day cache works.
        Http::assertSentCount(2);
    }

    public function test_a_failed_request_degrades_to_null_instead_of_throwing(): void
    {
        config(['services.gemini.key' => 'fake-key']);
        $this->fakeFearGreed();
        Http::fake([self::GEMINI_URL => Http::response('rate limited', 429)]);
        $service = app(GeminiSummaryService::class);

        $result = $service->summarizeDigest($this->summary(), new User, 'daily');

        $this->assertNull($result);
    }

    /**
     * Regression: Cache::remember() can't distinguish a cached null from a
     * cache miss (is_null() is true either way), so a naive "return null on
     * failure" never actually caches the failure — confirmed live, a second
     * call after a failed first one took just as long, not instant. The
     * fix stores '' instead and translates it back to null on the way out.
     */
    public function test_a_failure_is_cached_too_so_a_second_channel_does_not_retry(): void
    {
        config(['services.gemini.key' => 'fake-key']);
        $this->fakeFearGreed();
        Http::fake([self::GEMINI_URL => Http::response('rate limited', 429)]);
        $service = app(GeminiSummaryService::class);
        $user = new User;
        $user->id = 7;

        $first = $service->summarizeDigest($this->summary(), $user, 'daily');
        $second = $service->summarizeDigest($this->summary(), $user, 'daily');

        $this->assertNull($first);
        $this->assertNull($second);
        // 2 total: fear-greed + Gemini, both only on the first call.
        Http::assertSentCount(2);
    }
}
