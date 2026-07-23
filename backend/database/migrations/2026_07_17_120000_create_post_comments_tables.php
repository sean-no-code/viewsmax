<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comments composed alongside a post: extra messages posted after a target
 * publishes (X thread replies, LinkedIn/Instagram comments, Threads replies),
 * each with an optional delay. post_comments holds the composed text once per
 * post; post_target_comments tracks per-target delivery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->text('body');
            $table->unsignedInteger('delay_seconds')->default(0);
            $table->timestamps();
            $table->index(['post_id', 'position']);
        });

        Schema::create('post_target_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_comment_id')->constrained('post_comments')->cascadeOnDelete();
            $table->foreignId('post_target_id')->constrained('post_targets')->cascadeOnDelete();
            $table->string('status')->default('pending'); // pending|posting|posted|skipped|failed
            $table->string('platform_comment_id')->nullable();
            $table->string('error', 1024)->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
            $table->unique(['post_comment_id', 'post_target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_target_comments');
        Schema::dropIfExists('post_comments');
    }
};
