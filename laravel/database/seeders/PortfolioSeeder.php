<?php

namespace Database\Seeders;

use App\Models\Crypto;
use App\Models\Platform;
use App\Models\PortfolioEntry;
use Illuminate\Database\Seeder;

class PortfolioSeeder extends Seeder
{
    public function run(): void
    {
        $bitcoin = Crypto::create([
            'name' => 'Bitcoin',
            'symbol' => 'BTC',
            'coingecko_id' => 'bitcoin',
        ]);

        $ethereum = Crypto::create([
            'name' => 'Ethereum',
            'symbol' => 'ETH',
            'coingecko_id' => 'ethereum',
        ]);

        $solana = Crypto::create([
            'name' => 'Solana',
            'symbol' => 'SOL',
            'coingecko_id' => 'solana',
        ]);

        $binance = Platform::create(['name' => 'Binance']);
        $kraken = Platform::create(['name' => 'Kraken']);

        PortfolioEntry::create([
            'crypto_id' => $bitcoin->id,
            'platform_id' => $binance->id,
            'quantity' => 0.1,
        ]);

        PortfolioEntry::create([
            'crypto_id' => $bitcoin->id,
            'platform_id' => $kraken->id,
            'quantity' => 0.02,
        ]);

        PortfolioEntry::create([
            'crypto_id' => $ethereum->id,
            'platform_id' => $binance->id,
            'quantity' => 1.5,
        ]);

        PortfolioEntry::create([
            'crypto_id' => $solana->id,
            'platform_id' => $kraken->id,
            'quantity' => 12,
        ]);
    }
}
