<?php

namespace Tests\Feature;

use App\Enums\AssetType;
use App\Models\Asset;
use App\Services\NewsService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * No DB here (see ExchangeRatesTest) — Asset instances are in-memory, never
 * saved.
 */
class NewsUrlFilterTest extends TestCase
{
    public function test_news_items_without_an_http_url_are_dropped(): void
    {
        Http::preventStrayRequests();
        config(['services.marketaux.key' => 'fake-key']);
        $article = fn (string $url) => ['title' => $url, 'url' => $url, 'source' => 'x', 'published_at' => '2026-09-22T10:00:00Z'];
        Http::fake(['api.marketaux.com/*' => Http::response(['data' => [
            $article('https://example.com/ok'),
            $article('http://example.com/ok-too'),
            $article('javascript:alert(1)'),
            $article('data:text/html,<script>alert(1)</script>'),
            $article('//example.com/protocol-relative'),
        ]])]);
        $asset = new Asset(['name' => 'Apple Inc.', 'symbol' => 'AAPL', 'type' => AssetType::Stock]);
        $asset->id = 1;

        $news = app(NewsService::class)->getNewsForAsset($asset);

        $this->assertSame(['https://example.com/ok', 'http://example.com/ok-too'], array_column($news, 'url'));
    }
}
