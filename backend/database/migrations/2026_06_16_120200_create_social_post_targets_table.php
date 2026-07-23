<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * One row per (post, account) delivery attempt. Tracks the publish status,
     * the platform's returned post id, and any per-account error independently
     * so a single post can succeed on some platforms and fail on others.
     */
    public function up(): void
    {
        Schema::create('social_post_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_post_id')->constrained()->onDelete('cascade');
            $table->foreignId('social_account_id')->constrained()->onDelete('cascade');

            // Denormalised for quick filtering / display without a join.
            $table->string('platform')->index();

            // pending | publishing | published | failed | skipped
            $table->string('status')->default('pending')->index();

            // The platform's id for the created post and a link to view it.
            $table->string('remote_post_id')->nullable();
            $table->string('remote_post_url', 1024)->nullable();

            $table->string('error', 1024)->nullable();
            $table->json('response')->nullable();

            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            $table->unique(['social_post_id', 'social_account_id'], 'social_post_targets_post_account_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_post_targets');
    }
};
