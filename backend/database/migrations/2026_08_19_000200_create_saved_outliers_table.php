<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user saved-outliers library. Lives on the outlier_db connection with the rest of
 * the outlier data; `user_id` references users on the main DB, so it is a plain indexed
 * column (no cross-connection FK). We store a self-contained `snapshot` of the video at
 * save time (title/thumb/views?/comment_count/score/duration/published_at/
 * channel_name/avatar/subs) rather than an FK to the ingested videos table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('outlier_db')->create('saved_outliers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('platform', 20);
            $table->string('video_id'); // native platform id (youtube_video_id / tiktok id / ig shortcode)
            $table->json('snapshot');
            $table->timestamps();

            $table->unique(['user_id', 'platform', 'video_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::connection('outlier_db')->dropIfExists('saved_outliers');
    }
};
