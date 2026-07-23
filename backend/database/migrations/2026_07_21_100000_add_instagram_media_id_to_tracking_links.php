<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Instagram reach: pin a tracking link to a specific IG media the user
 * published through the app, so we can baseline its insights `views` at link
 * creation and count reach since then (mirrors youtube_video_id / beehiiv_post_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracking_links', function (Blueprint $table) {
            $table->string('instagram_media_id')->nullable()->after('x_post_id');
            $table->index('instagram_media_id');
        });
    }

    public function down(): void
    {
        Schema::table('tracking_links', function (Blueprint $table) {
            $table->dropIndex(['instagram_media_id']);
            $table->dropColumn('instagram_media_id');
        });
    }
};
