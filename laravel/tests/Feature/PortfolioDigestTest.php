<?php

namespace Tests\Feature;

use App\DTO\PortfolioSummary;
use App\Enums\AssetType;
use App\Enums\Currency;
use App\Models\Asset;
use App\Models\Platform;
use App\Models\PortfolioEntry;
use App\Models\User;
use App\Notifications\PortfolioDigest;
use App\Services\PortfolioDigestService;
use App\Services\PriceChangeCalculator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Digest content and rendering, from in-memory positions (no DB here, see
 * ExchangeRatesTest). Per-asset moves are stubbed: their sources are
 * covered by the price provider tests.
 */
class PortfolioDigestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.gemini.key' => null]);
        Cache::put('usd_exchange_rates', ['rates' => ['EUR' => 0.5, 'GBP' => 0.4, 'CHF' => 0.45], 'updated_at' => now()->toIso8601String()]);

        $moves = ['bitcoin' => 10.0, 'ethereum' => -4.0, 'solana' => 50.0];
        $calculator = Mockery::mock(PriceChangeCalculator::class);
        $calculator->shouldReceive('percentChange')->andReturnUsing(fn (Asset $asset) => $moves[$asset->external_id] ?? null);
        $this->app->instance(PriceChangeCalculator::class, $calculator);
    }

    private function user(Currency $currency = Currency::Eur): User
    {
        $user = new User(['name' => 'Sacha', 'email' => 'user@example.com']);
        $user->id = 1;
        $user->currency = $currency;
        $user->allocation_targets = ['crypto' => 100];

        return $user;
    }

    private function entry(int $assetId, string $name, string $externalId, float $quantity, float $valueUsd, ?float $pnlPercent): PortfolioEntry
    {
        $asset = new Asset(['name' => $name, 'symbol' => strtoupper(substr($externalId, 0, 3)), 'external_id' => $externalId, 'type' => AssetType::Crypto]);
        $asset->id = $assetId;
        $entry = new PortfolioEntry(['quantity' => $quantity]);
        $entry->asset_id = $assetId;
        $entry->setRelation('asset', $asset);
        $entry->setRelation('platform', new Platform(['name' => 'Binance']));
        $entry->value_usd = $valueUsd;
        $entry->performance = $pnlPercent === null ? null : ['EUR' => ['unrealized_pnl_percent' => $pnlPercent]];

        return $entry;
    }

    private function summary(): PortfolioSummary
    {
        $entries = collect([
            $this->entry(1, 'Bitcoin', 'bitcoin', 0.1, 600.0, 20.0),
            $this->entry(2, 'Ethereum', 'ethereum', 1, 400.0, null),
            // Fully sold: history, not a holding.
            $this->entry(3, 'Solana', 'solana', 0, 0.0, null),
        ]);

        return new PortfolioSummary(
            entries: $entries,
            totalValueUsd: 1000.0,
            totalValueEur: 500.0,
            changePercent: ['1h' => 0.1, '24h' => 25.0, '7d' => null],
            totalValueByCurrency: ['USD' => 1000.0, 'EUR' => 500.0],
            performance: ['EUR' => ['cost_basis' => 450.0, 'unrealized_pnl' => 50.0, 'unrealized_pnl_percent' => 11.11, 'realized_pnl' => 30.0]],
            positionsWithoutPrice: 1,
        );
    }

    public function test_the_content_is_in_the_users_currency_without_sold_positions(): void
    {
        $content = app(PortfolioDigestService::class)->build($this->user(), $this->summary(), 'daily');

        $this->assertSame('EUR', $content['currency']);
        $this->assertSame(500.0, $content['total']);
        // +25 % means it was 400 a day ago: +100.
        $this->assertSame(['percent' => 25.0, 'amount' => 100.0], $content['change']['24h']);
        $this->assertNull($content['change']['7d']);
        $this->assertSame(2, $content['positions_count']);
        $this->assertSame(['Bitcoin', 'Ethereum'], array_column($content['positions'], 'name'));
        $this->assertSame(300.0, $content['positions'][0]['value']);
        $this->assertSame([['name' => 'Bitcoin', 'symbol' => 'BIT', 'percent' => 10.0]], $content['risers']);
        $this->assertSame('Ethereum', $content['fallers'][0]['name']);
        $this->assertSame(['type' => 'crypto', 'percent' => 100.0, 'target_percent' => 100.0], $content['allocation']['rows'][0]);
    }

    public function test_nothing_is_built_when_everything_was_sold(): void
    {
        $summary = new PortfolioSummary(
            entries: collect([$this->entry(3, 'Solana', 'solana', 0, 0.0, null)]),
            totalValueUsd: 0.0, totalValueEur: 0.0, changePercent: [], totalValueByCurrency: ['USD' => 0.0, 'EUR' => 0.0],
        );

        $this->assertNull(app(PortfolioDigestService::class)->build($this->user(), $summary, 'daily'));
    }

    public function test_the_email_shows_totals_movers_and_positions(): void
    {
        $user = $this->user();
        $content = app(PortfolioDigestService::class)->build($user, $this->summary(), 'daily');
        $mail = (new PortfolioDigest($this->summary(), 'daily', $content))->toMail($user);
        $html = html_entity_decode((string) $mail->render(), ENT_QUOTES);

        $this->assertSame('Votre portefeuille : 500,00 € (+25 % sur 24 h)', $mail->subject);
        $this->assertStringContainsString('+100,00 €', $html);
        $this->assertStringContainsString('réalisée : +30,00 €', $html);
        $this->assertStringContainsString('+20 % depuis l\'achat', $html);
        $this->assertStringContainsString('1 position n\'est pas comptée', $html);
        $this->assertStringNotContainsString('Solana', $html);
        // No AI summary in tests (no key): no disclaimer either.
        $this->assertStringNotContainsString('Gemini', $html);
    }

    public function test_discord_gets_the_same_figures(): void
    {
        $user = $this->user();
        $content = app(PortfolioDigestService::class)->build($user, $this->summary(), 'weekly');
        $message = (new PortfolioDigest($this->summary(), 'weekly', $content))->toDiscord($user);

        $this->assertStringStartsWith('📊 Résumé de la semaine — 500,00 €', $message);
        $this->assertStringContainsString('Plus-value latente : +50,00 € (+11,11 %) · réalisée : +30,00 €', $message);
        $this->assertStringContainsString('- Bitcoin sur Binance : 300,00 €', $message);
    }

    public function test_a_catalog_name_cannot_inject_a_link(): void
    {
        $summary = $this->summary();
        $summary->entries[0]->asset->name = 'Gratuit [Réclamez ici](https://evil.example)';
        $user = $this->user();
        $content = app(PortfolioDigestService::class)->build($user, $summary, 'daily');
        $html = (string) (new PortfolioDigest($summary, 'daily', $content))->toMail($user)->render();

        $this->assertStringNotContainsString('href="https://evil.example"', $html);
    }
}
