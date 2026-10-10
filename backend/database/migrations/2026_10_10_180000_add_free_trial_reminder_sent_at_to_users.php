<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Stamped when the "free trial ends tomorrow" email is sent, so
            // SendFreeTrialReminders sends it exactly once per window and a
            // skipped scheduled run can't miss it.
            $table->timestamp('free_trial_reminder_sent_at')->nullable()->after('promo_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('free_trial_reminder_sent_at');
        });
    }
};
