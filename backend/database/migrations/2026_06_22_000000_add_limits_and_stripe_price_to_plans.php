<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Numeric per-tier limits for the Pricing + Features ticket. NULL means
     * "unlimited". stripe_price_id maps a plan to its Stripe Price so
     * upgrade/downgrade can resolve the target tier from a price_id.
     */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('max_channels')->nullable()->after('features');
            $table->unsignedInteger('max_offers')->nullable()->after('max_channels');
            $table->unsignedInteger('max_posts_per_month')->nullable()->after('max_offers');
            $table->string('stripe_price_id')->nullable()->after('max_posts_per_month');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['max_channels', 'max_offers', 'max_posts_per_month', 'stripe_price_id']);
        });
    }
};
