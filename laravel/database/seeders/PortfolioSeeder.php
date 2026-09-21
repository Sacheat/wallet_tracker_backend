<?php

namespace Database\Seeders;

use App\Enums\AssetType;
use App\Models\Asset;
use App\Models\Platform;
use App\Models\PortfolioEntry;
use App\Models\User;
use Illuminate\Database\Seeder;

class PortfolioSeeder extends Seeder
{
    public function run(): void
    {
        $bitcoin = Asset::create([
            'name' => 'Bitcoin',
            'symbol' => 'BTC',
            'type' => AssetType::Crypto,
            'external_id' => 'bitcoin',
        ]);

        $ethereum = Asset::create([
            'name' => 'Ethereum',
            'symbol' => 'ETH',
            'type' => AssetType::Crypto,
            'external_id' => 'ethereum',
        ]);

        $solana = Asset::create([
            'name' => 'Solana',
            'symbol' => 'SOL',
            'type' => AssetType::Crypto,
            'external_id' => 'solana',
        ]);

        // Demonstrates the multi-asset dispatch; value_usd stays 0 until
        // ALPHA_VANTAGE_API_KEY is set in .env.
        $apple = Asset::create([
            'name' => 'Apple Inc.',
            'symbol' => 'AAPL',
            'type' => AssetType::Stock,
            'external_id' => 'AAPL',
        ]);

        // external_id is Yahoo Finance's own ticker (GC=F, COMEX gold futures)
        // — no free "official" gold API was found, see YahooFinancePriceProvider.
        $gold = Asset::create([
            'name' => 'Or (once troy)',
            'symbol' => 'XAU',
            'type' => AssetType::Gold,
            'external_id' => 'GC=F',
        ]);

        $binance = Platform::create(['name' => 'Binance']);
        $kraken = Platform::create(['name' => 'Kraken']);

        $testUser = User::firstWhere('email', 'test@example.com');

        // A second account demonstrates that portfolio entries are private
        // per-user even though Asset/Platform stay a shared catalog.
        $otherUser = User::firstOrCreate(
            ['email' => 'other@example.com'],
            ['name' => 'Other User', 'password' => $testUser->password],
        );

        PortfolioEntry::create([
            'user_id' => $testUser->id,
            'asset_id' => $bitcoin->id,
            'platform_id' => $binance->id,
            'quantity' => 0.1,
        ]);

        PortfolioEntry::create([
            'user_id' => $testUser->id,
            'asset_id' => $bitcoin->id,
            'platform_id' => $kraken->id,
            'quantity' => 0.02,
        ]);

        PortfolioEntry::create([
            'user_id' => $testUser->id,
            'asset_id' => $ethereum->id,
            'platform_id' => $binance->id,
            'quantity' => 1.5,
        ]);

        PortfolioEntry::create([
            'user_id' => $testUser->id,
            'asset_id' => $solana->id,
            'platform_id' => $kraken->id,
            'quantity' => 12,
        ]);

        PortfolioEntry::create([
            'user_id' => $testUser->id,
            'asset_id' => $apple->id,
            'platform_id' => $kraken->id,
            'quantity' => 3,
        ]);

        PortfolioEntry::create([
            'user_id' => $testUser->id,
            'asset_id' => $gold->id,
            'platform_id' => $binance->id,
            'quantity' => 0.5,
        ]);

        PortfolioEntry::create([
            'user_id' => $otherUser->id,
            'asset_id' => $ethereum->id,
            'platform_id' => $kraken->id,
            'quantity' => 0.75,
        ]);
    }
}
