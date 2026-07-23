<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tracking_links', function (Blueprint $table) {
            $table->string('youtube_video_id')->nullable()->after('video_id');
            $table->index('youtube_video_id');
        });

        // Backfill youtube_video_id from videos table
        DB::statement("
            UPDATE tracking_links 
            SET youtube_video_id = (SELECT youtube_video_id FROM videos WHERE videos.id = tracking_links.video_id)
            WHERE video_id IS NOT NULL
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tracking_links', function (Blueprint $table) {
            $table->dropColumn('youtube_video_id');
        });
    }
};
