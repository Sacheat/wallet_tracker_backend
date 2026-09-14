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
        Schema::rename('cryptos', 'assets');

        Schema::table('assets', function (Blueprint $table) {
            $table->renameColumn('coingecko_id', 'external_id');
            $table->string('type')->default('crypto')->after('symbol');
        });

        // Existing rows were all cryptos before this migration; make that explicit
        // instead of relying on the column default for historical data.
        DB::table('assets')->update(['type' => 'crypto']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropColumn('type');
            $table->renameColumn('external_id', 'coingecko_id');
        });

        Schema::rename('assets', 'cryptos');
    }
};
