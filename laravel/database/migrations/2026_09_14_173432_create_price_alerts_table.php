<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('price_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Null asset_id = a portfolio-total alert rather than a per-asset one.
            $table->foreignId('asset_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->decimal('threshold', 20, 8)->nullable();
            $table->decimal('percent_threshold', 5, 2)->nullable();
            $table->string('window')->nullable();
            $table->boolean('is_active')->default(true);
            // Edge-triggering for threshold alerts: only notify when the
            // condition flips from false to true, not on every scheduler tick.
            $table->boolean('condition_met')->default(false);
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_alerts');
    }
};
