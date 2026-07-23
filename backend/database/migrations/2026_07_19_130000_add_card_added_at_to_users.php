<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * When the user actually entered card details. stripe_customer_id can't tell
 * you that: the customer is created when a checkout SESSION is built — before
 * the card screen — so onboarding drop-offs looked "carded" in admin.
 * checkout.session.completed / invoice.paid stamp this going forward.
 *
 * Backfill: anyone holding a user_plans row with a Stripe subscription got
 * through checkout at some point, so their card moment is approximated by the
 * earliest such row's created_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('card_added_at')->nullable()->after('stripe_customer_id');
        });

        DB::statement(
            'update users set card_added_at = ('
            .'select min(up.created_at) from user_plans up'
            .' where up.user_id = users.id and up.stripe_subscription_id is not null'
            .') where exists ('
            .'select 1 from user_plans up2'
            .' where up2.user_id = users.id and up2.stripe_subscription_id is not null'
            .')'
        );
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('card_added_at');
        });
    }
};
