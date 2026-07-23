<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * A SocialPost is the user-authored content (text + media) that may be
     * published to one or more connected accounts. Per-target delivery status
     * lives in social_post_targets.
     */
    public function up(): void
    {
        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            $table->text('content')->nullable();

            // Array of media descriptors: [{ url, type: image|video, mime, alt }]
            $table->json('media')->nullable();

            // Optional link to attach (mainly used by LinkedIn / Bluesky cards).
            $table->string('link', 1024)->nullable();

            // draft | scheduled | queued | publishing | published | partial | failed
            $table->string('status')->default('draft')->index();

            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_posts');
    }
};
