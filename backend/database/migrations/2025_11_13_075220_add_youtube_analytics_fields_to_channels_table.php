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
        Schema::table('channels', function (Blueprint $table) {
            // Playlist data (JSON array) - USED by FE
            $table->json('playlists')->nullable(); // [{id, title, description, thumbnail, itemCount, publishedAt, privacy}]

            // Analytics data (JSON objects) - USED by FE
            $table->json('views_over_time')->nullable(); // [{date, views, estimatedMinutesWatched, averageViewDuration}]
            $table->json('audience_demographics')->nullable(); // {ageGroups: {...}, gender: {...}, topCountries: [...]}
            $table->json('watch_time_analytics')->nullable(); // {averageViewDuration, totalWatchTimeHours}

            // Analytics metadata - USED by FE for conditional display
            $table->boolean('analytics_eligible')->default(false);
            $table->string('analytics_reason')->nullable(); // Why analytics may not be available
            $table->timestamp('analytics_last_updated')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn([
                'playlists',
                'views_over_time',
                'audience_demographics',
                'watch_time_analytics',
                'analytics_eligible',
                'analytics_reason',
                'analytics_last_updated'
            ]);
        });
    }
};
