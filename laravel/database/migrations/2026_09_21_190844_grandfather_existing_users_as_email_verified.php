<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Email verification is becoming blocking (see EnsureEmailIsVerified on
 * /api/*) — without this, every account created before today would be
 * locked out of the app it could already use, for a requirement that
 * didn't exist when they signed up.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('users')->whereNull('email_verified_at')->update([
            'email_verified_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Deliberately not reversed: we can't tell which rows this specific
        // migration verified apart from ones a user genuinely verified
        // afterward, and un-verifying real users on rollback would be worse
        // than leaving them verified.
    }
};
