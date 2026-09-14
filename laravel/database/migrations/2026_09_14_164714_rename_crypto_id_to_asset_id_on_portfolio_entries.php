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
        Schema::table('portfolio_entries', function (Blueprint $table) {
            $table->dropForeign(['crypto_id']);
            $table->dropUnique(['crypto_id', 'platform_id']);
        });

        Schema::table('portfolio_entries', function (Blueprint $table) {
            $table->renameColumn('crypto_id', 'asset_id');
        });

        Schema::table('portfolio_entries', function (Blueprint $table) {
            $table->foreign('asset_id')->references('id')->on('assets')->cascadeOnDelete();
            $table->unique(['asset_id', 'platform_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('portfolio_entries', function (Blueprint $table) {
            $table->dropForeign(['asset_id']);
            $table->dropUnique(['asset_id', 'platform_id']);
        });

        Schema::table('portfolio_entries', function (Blueprint $table) {
            $table->renameColumn('asset_id', 'crypto_id');
        });

        Schema::table('portfolio_entries', function (Blueprint $table) {
            $table->foreign('crypto_id')->references('id')->on('cryptos')->cascadeOnDelete();
            $table->unique(['crypto_id', 'platform_id']);
        });
    }
};
