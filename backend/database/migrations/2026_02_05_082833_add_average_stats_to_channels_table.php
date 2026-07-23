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
            $table->bigInteger('average_views')->nullable()->after('views_over_time');
            $table->json('average_video_ids')->nullable()->after('average_views');
            $table->timestamp('average_calculated_at')->nullable()->after('average_video_ids');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn([
                'average_views',
                'average_video_ids',
                'average_calculated_at'
            ]);
        });
    }
};
