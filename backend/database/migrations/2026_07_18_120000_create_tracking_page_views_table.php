<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Site-wide pageview tracking: tracker.js beacons every page load on pages
 * carrying the user's meta tag — not just tracking-link clicks. This is what
 * makes "Visitors" mean real site visitors (GA-style) and powers the
 * Sources/Referrers acquisition tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Some environments carry an orphaned tracking_page_views table from a
        // removed 2026-07-08 build (different schema, no code references).
        // Preserve its data under a _legacy name rather than dropping it.
        if (Schema::hasTable('tracking_page_views') && ! Schema::hasColumn('tracking_page_views', 'inferred_platform')) {
            Schema::rename('tracking_page_views', 'tracking_page_views_legacy');
        }

        if (Schema::hasTable('tracking_page_views')) {
            return;
        }

        Schema::create('tracking_page_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The offer whose offer_url matches the viewed page, when one does.
            $table->foreignId('tracking_event_id')->nullable()
                ->constrained('tracking_events')->nullOnDelete();
            $table->foreignId('tracking_visitor_id')->constrained('tracking_visitors')->cascadeOnDelete();
            $table->string('url', 2048);
            $table->string('path', 512)->nullable();
            $table->string('referrer', 2048)->nullable();
            // Explicit index names: the renamed legacy table keeps its
            // auto-generated (schema-global) index names, which would collide.
            $table->string('inferred_platform')->index('tpv_platform_idx');
            $table->timestamp('created_at')->index('tpv_created_idx');

            $table->index(['user_id', 'created_at'], 'tpv_user_created_idx');
            $table->index('tracking_event_id', 'tpv_event_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_page_views');
    }
};
