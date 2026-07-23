<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            // Video metadata from YouTube API
            $table->string('title', 500)->nullable()->after('youtube_video_id');
            $table->text('description')->nullable()->after('title');
            $table->string('thumbnail_url', 500)->nullable()->after('description');
            $table->string('thumbnail_medium_url', 500)->nullable()->after('thumbnail_url');
            $table->string('thumbnail_high_url', 500)->nullable()->after('thumbnail_medium_url');
            $table->timestamp('published_at')->nullable()->after('thumbnail_high_url');
            
            // Video statistics
            $table->bigInteger('view_count')->default(0)->after('published_at');
            $table->bigInteger('like_count')->default(0)->after('view_count');
            $table->bigInteger('comment_count')->default(0)->after('like_count');
            
            // Video content details
            $table->string('duration', 50)->nullable()->after('comment_count'); // ISO 8601 format (e.g., "PT5M30S")
            $table->string('definition', 10)->nullable()->after('duration'); // "hd" or "sd"
            $table->boolean('has_captions')->default(false)->after('definition');
            
            // Indexes for better query performance
            $table->index('published_at');
            $table->index('view_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropIndex(['published_at']);
            $table->dropIndex(['view_count']);
            $table->dropColumn([
                'title',
                'description',
                'thumbnail_url',
                'thumbnail_medium_url',
                'thumbnail_high_url',
                'published_at',
                'view_count',
                'like_count',
                'comment_count',
                'duration',
                'definition',
                'has_captions',
            ]);
        });
    }
};
