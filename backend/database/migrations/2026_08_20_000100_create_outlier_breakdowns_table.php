<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI breakdowns of outlier videos (idea / hook / structure / visual / transcript).
 * Lives on the outlier_db connection with the rest of the outlier data; references
 * videos by (platform, video_id) rather than an FK to the ingested videos table.
 * Breakdowns are per-video (not per-user): one generation serves everyone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('outlier_db')->create('outlier_breakdowns', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 20);
            $table->string('video_id');
            $table->string('status', 20)->default('pending');
            $table->json('payload')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'video_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('outlier_db')->dropIfExists('outlier_breakdowns');
    }
};
