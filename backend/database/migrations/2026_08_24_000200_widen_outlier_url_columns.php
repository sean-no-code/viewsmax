<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Instagram CDN URLs (thumbnails, avatars) regularly exceed 600 chars —
// varchar(255) made every IG ingest fail on Postgres. SQLite tests never
// caught it because SQLite doesn't enforce varchar lengths.
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('outlier_db')->table('videos', function (Blueprint $table) {
            $table->text('thumbnail_url')->nullable()->change();
            $table->text('thumbnail_medium_url')->nullable()->change();
        });
        Schema::connection('outlier_db')->table('channels', function (Blueprint $table) {
            $table->text('profile_image_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::connection('outlier_db')->table('videos', function (Blueprint $table) {
            $table->string('thumbnail_url')->nullable()->change();
            $table->string('thumbnail_medium_url')->nullable()->change();
        });
        Schema::connection('outlier_db')->table('channels', function (Blueprint $table) {
            $table->string('profile_image_url')->nullable()->change();
        });
    }
};
