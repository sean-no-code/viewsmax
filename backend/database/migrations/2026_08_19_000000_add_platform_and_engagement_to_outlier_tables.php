<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-platform + engagement support for outliers (runs on the outlier_db connection).
 * `platform` distinguishes youtube/tiktok/instagram; like/comment power the engagement rate
 * (Instagram has no view count, so engagement/views stay null there).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('outlier_db')->table('videos', function (Blueprint $table) {
            $table->string('platform', 20)->default('youtube')->index();
            $table->unsignedBigInteger('like_count')->nullable();
            $table->unsignedBigInteger('comment_count')->nullable();
        });

        // Instagram has no view count and no views-based outlier score → both nullable.
        Schema::connection('outlier_db')->table('videos', function (Blueprint $table) {
            $table->bigInteger('views')->nullable()->change();
            $table->float('outlier_score')->nullable()->change();
        });

        Schema::connection('outlier_db')->table('channels', function (Blueprint $table) {
            $table->string('platform', 20)->default('youtube')->index();
        });

        // Instagram exposes no follower count → subscriber_count must allow null.
        Schema::connection('outlier_db')->table('channels', function (Blueprint $table) {
            $table->bigInteger('subscriber_count')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::connection('outlier_db')->table('channels', function (Blueprint $table) {
            $table->bigInteger('subscriber_count')->default(0)->change();
        });
        Schema::connection('outlier_db')->table('videos', function (Blueprint $table) {
            $table->bigInteger('views')->default(0)->change();
            $table->float('outlier_score')->default(0)->change();
        });
        // Drop the indexes before the columns so SQLite (test harness) doesn't
        // choke on an index referencing a just-dropped column.
        Schema::connection('outlier_db')->table('videos', function (Blueprint $table) {
            $table->dropIndex('videos_platform_index');
        });
        Schema::connection('outlier_db')->table('videos', function (Blueprint $table) {
            $table->dropColumn(['platform', 'like_count', 'comment_count']);
        });
        Schema::connection('outlier_db')->table('channels', function (Blueprint $table) {
            $table->dropIndex('channels_platform_index');
        });
        Schema::connection('outlier_db')->table('channels', function (Blueprint $table) {
            $table->dropColumn('platform');
        });
    }
};
