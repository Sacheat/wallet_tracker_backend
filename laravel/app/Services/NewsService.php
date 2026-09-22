<?php

namespace App\Services;

use App\Enums\AssetType;
use App\Models\Asset;
use App\Services\NewsProviders\CryptoCompareNewsProvider;
use App\Services\NewsProviders\MarketauxNewsProvider;
use App\Services\NewsProviders\NewsProviderInterface;
use Illuminate\Support\Collection;

class NewsService
{
    /** @var array<string, NewsProviderInterface> */
    private array $providers;

    public function __construct(
        CryptoCompareNewsProvider $cryptoCompareNewsProvider,
        MarketauxNewsProvider $marketauxNewsProvider,
    ) {
        $this->providers = [
            AssetType::Crypto->value => $cryptoCompareNewsProvider,
            AssetType::Stock->value => $marketauxNewsProvider,
            AssetType::Etf->value => $marketauxNewsProvider,
            // Gold has no provider on purpose: no reliable source found
            // (see MarketauxNewsProvider's docblock) — degrades to [] below,
            // same as any asset type without a registered provider.
        ];
    }

    /**
     * @return array<int, array{title: string, url: string, source: string, published_at: string}>
     */
    public function getNewsForAsset(Asset $asset): array
    {
        $provider = $this->providers[$asset->type?->value] ?? null;

        return $provider ? $provider->getNews($asset) : [];
    }

    /**
     * @param  Collection<int, Asset>  $assets
     * @return array<int, array{title: string, url: string, source: string, published_at: string, asset_symbol: string}>
     */
    public function getNewsForAssets(Collection $assets): array
    {
        return $assets
            ->unique('id')
            ->flatMap(function (Asset $asset) {
                return collect($this->getNewsForAsset($asset))
                    ->map(fn (array $item) => [...$item, 'asset_symbol' => $asset->symbol]);
            })
            ->unique('url')
            ->sortByDesc('published_at')
            ->take(20)
            ->values()
            ->toArray();
    }
}
