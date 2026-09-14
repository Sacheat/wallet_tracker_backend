<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Assets are a shared catalog any authenticated user can maintain (see
     * step 2 notes). cascadeOnDelete on asset_id meant deleting an Asset
     * silently wiped every OTHER user's portfolio entries and alerts for it
     * — not just the deleter's own. Restricting at the DB level, backed by
     * an explicit pre-check in AssetController, keeps that blast radius out
     * even though asset mutation itself stays unrestricted by design.
     */
    public function up(): void
    {
        Schema::table('portfolio_entries', function (Blueprint $table) {
            $table->dropForeign(['asset_id']);
        });

        Schema::table('portfolio_entries', function (Blueprint $table) {
            $table->foreign('asset_id')->references('id')->on('assets')->restrictOnDelete();
        });

        Schema::table('price_alerts', function (Blueprint $table) {
            $table->dropForeign(['asset_id']);
        });

        Schema::table('price_alerts', function (Blueprint $table) {
            $table->foreign('asset_id')->references('id')->on('assets')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('portfolio_entries', function (Blueprint $table) {
            $table->dropForeign(['asset_id']);
        });

        Schema::table('portfolio_entries', function (Blueprint $table) {
            $table->foreign('asset_id')->references('id')->on('assets')->cascadeOnDelete();
        });

        Schema::table('price_alerts', function (Blueprint $table) {
            $table->dropForeign(['asset_id']);
        });

        Schema::table('price_alerts', function (Blueprint $table) {
            $table->foreign('asset_id')->references('id')->on('assets')->cascadeOnDelete();
        });
    }
};
