<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Instagram refuses its iframe embed for most creator accounts, but CaptAPI
// gives us a direct (expiring) CDN video URL — store it so the breakdown page
// can play Instagram natively. Outlier-domain table → outlier_db.
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('outlier_db')->table('videos', function (Blueprint $table) {
            $table->text('video_url')->nullable();
            $table->timestamp('video_url_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('outlier_db')->table('videos', function (Blueprint $table) {
            $table->dropColumn(['video_url', 'video_url_expires_at']);
        });
    }
};
