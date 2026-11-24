<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pro is no longer sold (2026-11-23): analytics moved down to Creator. The row
 * stays, with its Stripe price id, so existing subscribers keep their plan and
 * their renewals still resolve to it; is_active = false takes it off /api/plans
 * and out of checkout, the same way PlanSeeder retires the creator_* tiers.
 * A migration because deploys run migrate, not the seeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('plans')->where('name', 'pro')->update(['is_active' => false]);
    }

    public function down(): void
    {
        DB::table('plans')->where('name', 'pro')->update(['is_active' => true]);
    }
};
