<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attribution spine (content-intelligence Phase 1a): capture where a click came
 * from (referrer/UTM/inferred platform) and persist which click+link earned each
 * conversion, plus each visitor's first touch. New id columns are plain nullable
 * indexes (no DB-level FK) so the migration is portable to SQLite test runs and
 * so a deleted click/link never cascades away a conversion's revenue record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracking_clicks', function (Blueprint $table) {
            $table->text('referrer')->nullable()->after('tracking_link_id');
            $table->text('landing_url')->nullable()->after('referrer');
            $table->string('utm_source')->nullable()->after('landing_url');
            $table->string('utm_medium')->nullable()->after('utm_source');
            $table->string('utm_campaign')->nullable()->after('utm_medium');
            $table->string('utm_term')->nullable()->after('utm_campaign');
            $table->string('utm_content')->nullable()->after('utm_term');
            $table->string('inferred_platform')->nullable()->after('utm_content')->index();
        });

        Schema::table('tracking_visitors', function (Blueprint $table) {
            $table->unsignedBigInteger('first_touch_click_id')->nullable()->after('user_agent');
        });

        Schema::table('tracking_conversions', function (Blueprint $table) {
            $table->unsignedBigInteger('tracking_click_id')->nullable()->after('tracking_event_id')->index();
            $table->unsignedBigInteger('tracking_link_id')->nullable()->after('tracking_click_id')->index();
            $table->unsignedBigInteger('first_touch_click_id')->nullable()->after('tracking_link_id');
        });
    }

    public function down(): void
    {
        Schema::table('tracking_clicks', function (Blueprint $table) {
            $table->dropColumn([
                'referrer', 'landing_url', 'utm_source', 'utm_medium',
                'utm_campaign', 'utm_term', 'utm_content', 'inferred_platform',
            ]);
        });

        Schema::table('tracking_visitors', function (Blueprint $table) {
            $table->dropColumn('first_touch_click_id');
        });

        Schema::table('tracking_conversions', function (Blueprint $table) {
            $table->dropColumn(['tracking_click_id', 'tracking_link_id', 'first_touch_click_id']);
        });
    }
};
