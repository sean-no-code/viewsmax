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
        $schema = Schema::connection('outlier_db');

        // Idempotent: the outlier DB may already be provisioned — and these
        // tables may exist under their RENAMED names (channels/videos) if the
        // follow-up rename migration already ran there. Either way: skip.
        if ($schema->hasTable('outlier_channels') || $schema->hasTable('channels')) {
            return;
        }

        $schema->create('outlier_channels', function (Blueprint $table) {
            $table->id();
            $table->string('youtube_channel_id')->unique()->index();
            $table->string('channel_name')->nullable();
            $table->string('profile_image_url')->nullable();
            $table->bigInteger('subscriber_count')->default(0);
            $table->bigInteger('video_count')->default(0);
            
            // Multiplier Fields
            $table->bigInteger('average_views')->nullable();
            $table->timestamp('average_calculated_at')->nullable();
            $table->json('average_video_ids')->nullable(); // Store IDs used for avg calculation
            
            $table->timestamps();
        });

        $schema->create('outlier_videos', function (Blueprint $table) {
            $table->id(); // BigIncrements
            $table->foreignId('outlier_channel_id')->constrained('outlier_channels')->onDelete('cascade');
            $table->string('youtube_video_id')->unique()->index();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->string('thumbnail_medium_url')->nullable();
            $table->bigInteger('views')->default(0);
            $table->float('outlier_score')->default(0);
            $table->string('duration')->nullable(); // ISO 8601 or parsed? Sticking to string representation for now
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            
            // Indexes for searching/filtering
            $table->index('outlier_score');
            $table->index('views');
            $table->index('published_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $schema = Schema::connection('outlier_db');
        $schema->dropIfExists('outlier_videos');
        $schema->dropIfExists('outlier_channels');
    }
};
