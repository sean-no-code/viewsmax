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
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('youtube_channel_id', 255);
            
            // YouTube channel data fields
            $table->string('channel_name', 255)->nullable();
            $table->text('channel_description')->nullable();
            $table->bigInteger('subscriber_count')->default(0);
            $table->integer('video_count')->default(0);
            $table->bigInteger('view_count')->default(0);
            $table->string('custom_url', 255)->nullable();
            $table->string('country', 10)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->text('profile_image_url')->nullable();
            
            // OAuth handshake data (for future API queries)
            $table->text('youtube_access_token')->nullable();
            $table->text('youtube_refresh_token')->nullable();
            $table->timestamp('youtube_token_expires_at')->nullable();
            $table->text('oauth_scopes')->nullable(); // JSON array
            
            $table->timestamps();
            
            // Indexes
            $table->index('user_id');
            $table->index('youtube_channel_id');
            
            // Ensure one channel per user per YouTube channel ID
            $table->unique(['user_id', 'youtube_channel_id'], 'unique_user_channel');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
