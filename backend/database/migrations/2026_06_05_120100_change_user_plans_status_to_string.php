<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Convert user_plans.status from a fixed enum to a portable string column so it
     * can hold any Stripe subscription status (trialing, active, past_due, canceled, ...).
     */
    public function up(): void
    {
        // On Postgres an enum() column is a varchar + a CHECK constraint that would
        // otherwise reject the new statuses; drop it before widening the column.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE user_plans DROP CONSTRAINT IF EXISTS user_plans_status_check');
        }

        Schema::table('user_plans', function (Blueprint $table) {
            $table->string('status')->default('active')->change();
        });
    }

    public function down(): void
    {
        Schema::table('user_plans', function (Blueprint $table) {
            $table->enum('status', ['active', 'inactive', 'cancelled', 'expired'])->default('active')->change();
        });
    }
};
