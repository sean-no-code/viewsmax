<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The switch that turns an account off: set, every entry point answers
 * "account disabled, contact support" (App\Support\UserAccess::ACCOUNT_DISABLED)
 * and background work skips the user; null again and the account is back as it
 * was. A timestamp so the row also says when it was switched off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('disabled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('disabled_at');
        });
    }
};
