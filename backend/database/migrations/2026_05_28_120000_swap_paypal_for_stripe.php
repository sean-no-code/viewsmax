<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_plans', function (Blueprint $table) {
            $table->renameColumn('paypal_subscription_id', 'stripe_subscription_id');
            $table->renameColumn('paypal_plan_id', 'stripe_price_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('stripe_customer_id')->nullable()->after('public_id');
            $table->index('stripe_customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['stripe_customer_id']);
            $table->dropColumn('stripe_customer_id');
        });

        Schema::table('user_plans', function (Blueprint $table) {
            $table->renameColumn('stripe_subscription_id', 'paypal_subscription_id');
            $table->renameColumn('stripe_price_id', 'paypal_plan_id');
        });
    }
};
