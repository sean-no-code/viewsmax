<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->decimal('outlier_score', 8, 2)->nullable()->after('id');
            $table->string('youtube_channel_id')->nullable()->after('youtube_video_id');

            // Index for performance
            $table->index('outlier_score');
            $table->index('youtube_channel_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropIndex(['outlier_score']);
            $table->dropIndex(['youtube_channel_id']);
            $table->dropColumn(['outlier_score', 'youtube_channel_id']);
        });
    }
};
