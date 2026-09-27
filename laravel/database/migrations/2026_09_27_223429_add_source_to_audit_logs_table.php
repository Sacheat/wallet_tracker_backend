<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An entry with no user_id was ambiguous: an account deleted since (its
 * history is kept, user_id nulled) or a console action (scheduler,
 * artisan) on a model with no owner to fall back on. 'web' / 'console',
 * set at write time by Auditable. Existing rows stay null: which of the
 * two they were can't be reconstructed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('source', 10)->nullable()->after('action');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
