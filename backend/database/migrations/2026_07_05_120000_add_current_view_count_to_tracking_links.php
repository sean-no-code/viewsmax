<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reach denominator (views-based conversion rate): a freshly-refreshed current
 * view count for the link's content, alongside the existing initial_view_count
 * snapshot. views_since = max(0, current_view_count - initial_view_count).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracking_links', function (Blueprint $table) {
            $table->unsignedBigInteger('current_view_count')->nullable()->after('initial_view_count');
            $table->timestamp('reach_synced_at')->nullable()->after('current_view_count');
        });
    }

    public function down(): void
    {
        Schema::table('tracking_links', function (Blueprint $table) {
            $table->dropColumn(['current_view_count', 'reach_synced_at']);
        });
    }
};
