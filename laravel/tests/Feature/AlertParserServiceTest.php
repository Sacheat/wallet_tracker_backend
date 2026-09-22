<?php

namespace Tests\Feature;

use App\Services\AlertParserService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * No DB here (see ExchangeRatesTest) — only cases that don't need
 * findAsset()'s real Asset::whereRaw() lookup are covered (portfolio-total
 * alerts, and the failure paths that return before ever reaching it).
 * price_above/price_below/percent_change's asset resolution is verified
 * live instead.
 */
class AlertParserServiceTest extends TestCase
{
    private const GEMINI_URL = 'generativelanguage.googleapis.com/*';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function fakeExtraction(array $fields): void
    {
        Http::fake([
            self::GEMINI_URL => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode($fields)]]]]],
            ]),
        ]);
    }

    public function test_without_an_api_key_it_reports_unavailable_without_any_request(): void
    {
        config(['services.gemini.key' => null]);
        $service = app(AlertParserService::class);

        $result = $service->parse('préviens-moi si mon portefeuille passe sous 5000');

        $this->assertFalse($result['understood']);
        $this->assertSame('unavailable', $result['reason']);
    }

    public function test_a_clear_portfolio_alert_is_understood(): void
    {
        config(['services.gemini.key' => 'fake-key']);
        $this->fakeExtraction([
            'type' => 'portfolio_total_below',
            'confidence' => 'high',
            'asset_name' => null,
            'threshold' => 5000,
            'percent_threshold' => null,
            'window' => null,
        ]);
        $service = app(AlertParserService::class);

        $result = $service->parse('préviens-moi si mon portefeuille passe sous 5000');

        $this->assertTrue($result['understood']);
        $this->assertSame('portfolio_total_below', $result['type']);
        $this->assertSame(5000.0, $result['threshold']);
        $this->assertNull($result['asset']);
    }

    public function test_low_confidence_is_reported_as_ambiguous(): void
    {
        config(['services.gemini.key' => 'fake-key']);
        $this->fakeExtraction([
            'type' => 'price_above',
            'confidence' => 'low',
            'asset_name' => null,
            'threshold' => null,
            'percent_threshold' => null,
            'window' => null,
        ]);
        $service = app(AlertParserService::class);

        $result = $service->parse('je veux savoir des trucs sur la bourse');

        $this->assertFalse($result['understood']);
        $this->assertSame('ambiguous', $result['reason']);
    }

    public function test_percent_change_missing_the_window_is_ambiguous(): void
    {
        config(['services.gemini.key' => 'fake-key']);
        $this->fakeExtraction([
            'type' => 'percent_change',
            'confidence' => 'high',
            'asset_name' => 'ethereum',
            'threshold' => null,
            'percent_threshold' => 10,
            'window' => null,
        ]);
        $service = app(AlertParserService::class);

        $result = $service->parse('alerte-moi si ethereum bouge de 10%');

        $this->assertFalse($result['understood']);
        $this->assertSame('ambiguous', $result['reason']);
    }

    public function test_a_portfolio_alert_missing_its_threshold_is_ambiguous(): void
    {
        config(['services.gemini.key' => 'fake-key']);
        $this->fakeExtraction([
            'type' => 'portfolio_total_above',
            'confidence' => 'high',
            'asset_name' => null,
            'threshold' => null,
            'percent_threshold' => null,
            'window' => null,
        ]);
        $service = app(AlertParserService::class);

        $result = $service->parse('préviens-moi si mon portefeuille monte beaucoup');

        $this->assertFalse($result['understood']);
        $this->assertSame('ambiguous', $result['reason']);
    }

    public function test_a_failed_first_attempt_is_retried_once(): void
    {
        config(['services.gemini.key' => 'fake-key']);
        Http::fake([
            self::GEMINI_URL => Http::sequence()
                ->push('high demand', 503)
                ->push([
                    'candidates' => [['content' => ['parts' => [['text' => json_encode([
                        'type' => 'portfolio_total_below',
                        'confidence' => 'high',
                        'asset_name' => null,
                        'threshold' => 5000,
                        'percent_threshold' => null,
                        'window' => null,
                    ])]]]]],
                ]),
        ]);
        $service = app(AlertParserService::class);

        $result = $service->parse('préviens-moi si mon portefeuille passe sous 5000');

        $this->assertTrue($result['understood']);
        Http::assertSentCount(2);
    }

    public function test_two_failed_attempts_report_unavailable(): void
    {
        config(['services.gemini.key' => 'fake-key']);
        Http::fake([self::GEMINI_URL => Http::response('high demand', 503)]);
        $service = app(AlertParserService::class);

        $result = $service->parse('préviens-moi si mon portefeuille passe sous 5000');

        $this->assertFalse($result['understood']);
        $this->assertSame('unavailable', $result['reason']);
        Http::assertSentCount(2);
    }
}
