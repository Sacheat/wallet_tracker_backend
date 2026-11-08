<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Buy/sell journal behind each position. portfolio_entries.quantity is
     * kept as a cached total recomputed from these rows on every write, so
     * every existing reader (portfolio, alerts, digests) keeps working as is.
     */
    public function up(): void
    {
        Schema::create('portfolio_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portfolio_entry_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->decimal('quantity', 20, 8);
            // Price per unit, in `currency`, as the user paid it. Nullable
            // only for positions that existed before this journal: the
            // backfill below has a quantity but no known price.
            $table->decimal('unit_price', 24, 10)->nullable();
            $table->decimal('fee', 20, 8)->default(0);
            $table->string('currency', 3);
            // USD→EUR/GBP/CHF rates on the trade date (Frankfurter history),
            // so profit can be computed exactly in any supported currency,
            // exchange-rate effect included. Null while unit_price is null.
            $table->json('fx_rates')->nullable();
            $table->timestamp('executed_at');
            $table->timestamps();

            $table->index(['portfolio_entry_id', 'executed_at']);
        });

        // Every existing position becomes one opening buy, priceless: no
        // quantity is lost, and profit shows as unknown until the user fills
        // in what they paid. Query builder on purpose: no audit-log rows for
        // a schema migration.
        $now = now();
        DB::table('portfolio_entries')
            ->join('users', 'users.id', '=', 'portfolio_entries.user_id')
            ->select('portfolio_entries.id', 'portfolio_entries.quantity', 'portfolio_entries.created_at', 'users.currency')
            ->orderBy('portfolio_entries.id')
            ->chunk(500, function ($entries) use ($now) {
                DB::table('portfolio_transactions')->insert($entries->map(fn ($entry) => [
                    'portfolio_entry_id' => $entry->id,
                    'type' => 'buy',
                    'quantity' => $entry->quantity,
                    'unit_price' => null,
                    'fee' => 0,
                    'currency' => $entry->currency ?? 'USD',
                    'fx_rates' => null,
                    'executed_at' => $entry->created_at ?? $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_transactions');
    }
};
