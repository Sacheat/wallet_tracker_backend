<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catalog logos were free-form URLs typed by any user: since the catalog is
 * shared, pointing one at your own server logged the IP of everyone who
 * displayed it. Logos are now uploaded and served from our own storage
 * (see LogoStorage); the old URLs are dropped rather than migrated — they
 * point to servers we don't control, which is precisely the problem.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['assets', 'platforms'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('logo_url');
                $table->string('logo_path')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['assets', 'platforms'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('logo_path');
                $table->string('logo_url')->nullable();
            });
        }
    }
};
