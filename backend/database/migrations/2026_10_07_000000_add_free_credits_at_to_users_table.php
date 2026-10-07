<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Self-signups now get free credits with no time limit instead of a 7-day
// window. free_credits_at marks those accounts (when the credits were granted)
// so the "locked once the credits are used up" rule applies to them only, not
// to older accounts that never had free credits.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('free_credits_at')->nullable()->after('promo_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('free_credits_at');
        });
    }
};
