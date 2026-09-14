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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('email_notifications_enabled')->default(true)->after('password');
            $table->string('discord_webhook_url')->nullable()->after('email_notifications_enabled');
            $table->boolean('daily_digest_enabled')->default(false)->after('discord_webhook_url');
            $table->boolean('weekly_digest_enabled')->default(false)->after('daily_digest_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'email_notifications_enabled',
                'discord_webhook_url',
                'daily_digest_enabled',
                'weekly_digest_enabled',
            ]);
        });
    }
};
