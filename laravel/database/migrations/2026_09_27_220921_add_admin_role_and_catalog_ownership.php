<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The asset/platform catalog is shared, and until now any user could edit
 * or re-logo an entry other users hold — e.g. repoint BTC's external_id to
 * a dead coin, zeroing everyone's valuation and firing their alerts, or
 * rename it to phishing text shown in their dashboards and emails. Now:
 * an admin manages the whole catalog, a regular user only what they
 * created and nobody else uses yet (see CatalogPolicy).
 */
return new class extends Migration
{
    private const MODELS = ['assets' => 'App\\Models\\Asset', 'platforms' => 'App\\Models\\Platform'];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('password');
        });

        foreach (self::MODELS as $table => $model) {
            Schema::table($table, function (Blueprint $table) {
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            });

            // Backfilled from the audit trail's 'created' entries. Anything
            // it can't attribute stays null, i.e. admin-only: the safe side.
            DB::table('audit_logs')
                ->where('auditable_type', $model)
                ->where('action', 'created')
                ->whereNotNull('user_id')
                ->whereIn('user_id', DB::table('users')->select('id'))
                ->orderBy('id')
                ->get(['auditable_id', 'user_id'])
                ->each(fn ($log) => DB::table($table)
                    ->where('id', $log->auditable_id)
                    ->whereNull('created_by')
                    ->update(['created_by' => $log->user_id]));
        }

        // decimal(5,2) capped at 999.99 while validation (and the API
        // contract) accept up to 1000: a 1000 % alert was a 500.
        Schema::table('price_alerts', function (Blueprint $table) {
            $table->decimal('percent_threshold', 6, 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('price_alerts', function (Blueprint $table) {
            $table->decimal('percent_threshold', 5, 2)->nullable()->change();
        });

        foreach (array_keys(self::MODELS) as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropConstrainedForeignId('created_by');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
