<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_plans', function (Blueprint $table) {
            // Stamped when the "trial ending / card about to be charged" reminder is
            // sent, so SendTrialEndingReminders sends it exactly once per trial and a
            // skipped scheduled run can't miss it.
            $table->timestamp('trial_reminder_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user_plans', function (Blueprint $table) {
            $table->dropColumn('trial_reminder_sent_at');
        });
    }
};
