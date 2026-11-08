<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Target share of the portfolio per asset type, e.g.
     * {"crypto": 50, "etf": 30, "stock": 15, "gold": 5}. A JSON column
     * rather than a table: at most four numbers, always replaced together
     * (they must add up to 100), never queried on their own.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('allocation_targets')->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('allocation_targets');
        });
    }
};
