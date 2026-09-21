<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Same gap as asset_id (see 2026_09_14_180413_restrict_asset_deletion_
     * when_referenced), just missed for platforms at the time: platform_id
     * on portfolio_entries was still cascadeOnDelete, so deleting a shared
     * Platform (e.g. "Binance") silently wiped every OTHER user's portfolio
     * entries on it, not just the deleter's own.
     */
    public function up(): void
    {
        Schema::table('portfolio_entries', function (Blueprint $table) {
            $table->dropForeign(['platform_id']);
        });

        Schema::table('portfolio_entries', function (Blueprint $table) {
            $table->foreign('platform_id')->references('id')->on('platforms')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('portfolio_entries', function (Blueprint $table) {
            $table->dropForeign(['platform_id']);
        });

        Schema::table('portfolio_entries', function (Blueprint $table) {
            $table->foreign('platform_id')->references('id')->on('platforms')->cascadeOnDelete();
        });
    }
};
