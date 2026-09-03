<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily engagement snapshot per published post. Denormalized on purpose: posts
 * live in two rival stores (post_targets and social_post_targets), so we key on
 * (platform, remote_post_id) rather than FK into either. The posts:refresh-metrics
 * job upserts one row per post per day; the Audience Growth "top posts" list
 * orders by engagement_total and derives a day-over-day delta from the series.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('post_metric_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform');
            $table->string('remote_post_id');
            $table->string('url', 1024)->nullable();
            $table->string('caption_excerpt', 300)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('likes')->default(0);
            $table->unsignedBigInteger('comments')->default(0);
            $table->unsignedBigInteger('shares')->default(0);
            $table->unsignedBigInteger('views')->default(0);
            $table->unsignedBigInteger('engagement_total')->default(0);
            $table->date('snapshot_date');
            $table->timestamps();

            $table->unique(['platform', 'remote_post_id', 'snapshot_date']);
            $table->index(['user_id', 'snapshot_date']);
            $table->index('engagement_total');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_metric_snapshots');
    }
};
