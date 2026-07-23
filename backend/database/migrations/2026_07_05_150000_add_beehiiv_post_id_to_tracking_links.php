<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tracking link can be placed in a Beehiiv newsletter post; its views feed the
 * same reach-based conversion rate as YouTube video views. The reach denominator
 * (initial/current_view_count + snapshots) is source-agnostic; this just records
 * WHICH Beehiiv post backs the link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracking_links', function (Blueprint $table) {
            $table->string('beehiiv_post_id')->nullable()->after('youtube_video_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('tracking_links', function (Blueprint $table) {
            $table->dropColumn('beehiiv_post_id');
        });
    }
};
