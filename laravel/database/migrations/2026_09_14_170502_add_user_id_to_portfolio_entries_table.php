<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('portfolio_entries', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        // Backfill: attribute pre-existing (pre-multi-user) entries to the
        // oldest user account so no data is silently dropped.
        DB::table('portfolio_entries')->update([
            'user_id' => DB::table('users')->orderBy('id')->value('id'),
        ]);

        Schema::table('portfolio_entries', function (Blueprint $table) {
            // asset_id's foreign key relies on the composite unique index below
            // as its supporting index (no other index covers it); MySQL refuses
            // to drop that index without a replacement already in place.
            $table->index('asset_id');
        });

        Schema::table('portfolio_entries', function (Blueprint $table) {
            $table->dropUnique(['asset_id', 'platform_id']);
        });

        Schema::table('portfolio_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->unique(['user_id', 'asset_id', 'platform_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('portfolio_entries', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'asset_id', 'platform_id']);
            $table->unique(['asset_id', 'platform_id']);
        });

        Schema::table('portfolio_entries', function (Blueprint $table) {
            $table->dropIndex(['asset_id']);
        });

        Schema::table('portfolio_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
