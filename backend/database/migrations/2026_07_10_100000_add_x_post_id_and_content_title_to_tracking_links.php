<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracking_links', function (Blueprint $table) {
            // X post (tweet) id a link is pinned to — published via the app.
            $table->string('x_post_id')->nullable()->after('beehiiv_post_id')->index();
            // Snapshot of the linked content's title (video title / newsletter
            // subject / first-tweet excerpt) for index display.
            $table->string('content_title', 255)->nullable()->after('name');
        });

        // Backfill YouTube titles from the videos table. Per-row updates keep
        // this portable across SQLite (tests) and MySQL/Postgres (join-update
        // syntax differs).
        $titles = DB::table('videos')->whereNotNull('title')->pluck('title', 'youtube_video_id');
        DB::table('tracking_links')
            ->whereNotNull('youtube_video_id')
            ->whereNull('content_title')
            ->orderBy('id')
            ->chunkById(200, function ($links) use ($titles) {
                foreach ($links as $link) {
                    $title = $titles[$link->youtube_video_id] ?? null;
                    if ($title !== null) {
                        DB::table('tracking_links')->where('id', $link->id)
                            ->update(['content_title' => mb_substr($title, 0, 255)]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('tracking_links', function (Blueprint $table) {
            $table->dropColumn(['x_post_id', 'content_title']);
        });
    }
};
