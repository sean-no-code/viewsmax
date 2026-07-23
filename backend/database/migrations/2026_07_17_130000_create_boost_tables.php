<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Boosts: per-account automations that fire once a published post reaches a
 * like threshold — Auto Repost (retweet it) and Auto Promo (reply with a promo
 * comment). boost_checks is the work queue AND the dedupe ledger: the unique
 * (post_target_id, feature) row guarantees a post is boosted at most once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boost_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('feature'); // auto_repost | auto_promo
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('likes_threshold')->default(10);
            $table->text('promo_text')->nullable();
            $table->timestamps();
            $table->unique(['social_account_id', 'feature']);
        });

        Schema::create('boost_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_target_id')->constrained('post_targets')->cascadeOnDelete();
            $table->foreignId('boost_setting_id')->constrained('boost_settings')->cascadeOnDelete();
            $table->string('feature'); // denormalized for the unique key
            $table->unsignedTinyInteger('runs_completed')->default(0);
            $table->timestamp('next_run_at')->index();
            $table->string('status')->default('pending'); // pending|triggered|exhausted|failed
            $table->string('result_remote_id')->nullable();
            $table->string('error', 1024)->nullable();
            $table->timestamps();
            $table->unique(['post_target_id', 'feature']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boost_checks');
        Schema::dropIfExists('boost_settings');
    }
};
