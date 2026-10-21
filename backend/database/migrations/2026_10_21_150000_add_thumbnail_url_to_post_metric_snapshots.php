<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preview image for the Post Performance cards. Instagram and TikTok hand out
 * expiring CDN links, so the provider returns a fresh one with each day's
 * metrics and the snapshot keeps the latest. YouTube needs nothing stored
 * (the frontend derives its still from the video id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_metric_snapshots', function (Blueprint $table) {
            $table->string('thumbnail_url', 2048)->nullable()->after('url');
        });
    }

    public function down(): void
    {
        Schema::table('post_metric_snapshots', function (Blueprint $table) {
            $table->dropColumn('thumbnail_url');
        });
    }
};
