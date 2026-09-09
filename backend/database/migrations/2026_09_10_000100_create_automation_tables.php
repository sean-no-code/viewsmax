<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ManyChat-style automations: a trigger on a connected social account
     * (comment / story reply / DM) that answers with a public reply and/or a
     * DM. Three tables:
     *  - automations        the user's config (one row per automation)
     *  - automation_events  inbound webhook ledger (HTTP-level idempotency +
     *                       the "why didn't it fire?" support trail)
     *  - automation_runs    one row per fired automation (Runs, CTR, dedupe)
     */
    public function up(): void
    {
        Schema::create('automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            // instagram now; tiktok later (denormalized from the account).
            $table->string('platform', 20)->default('instagram');
            $table->string('name', 120)->default('Untitled');
            // comment | story_reply | dm
            $table->string('trigger_type', 20);
            // live | stopped
            $table->string('status', 16)->default('stopped');

            // Comment trigger: which posts. specific | any. `posts` caches
            // [{id, media_type, thumbnail_url, permalink, caption}] for display;
            // matching uses `id` only.
            $table->string('post_match', 20)->nullable();
            $table->json('posts')->nullable();
            $table->boolean('include_replies')->default(false);

            // any | contains | exact, over lowercased/trimmed keywords.
            $table->string('keyword_mode', 20)->default('any');
            $table->json('keywords')->nullable();
            // Per-sender re-fire guard (0 = off).
            $table->unsignedSmallInteger('cooldown_hours')->default(24);

            // Public reply under the comment (comment trigger only); one of
            // reply_texts is picked at random per run.
            $table->boolean('reply_enabled')->default(false);
            $table->json('reply_texts')->nullable();

            // The DM. Plain text (<=1000) when there is no button; with a
            // button it is sent as a generic-template card whose title is
            // dm_text (<=80, Instagram's limit).
            $table->text('dm_text');
            $table->string('dm_subtitle', 80)->nullable();
            $table->string('dm_image_url', 1024)->nullable();
            $table->string('dm_button_label', 20)->nullable();
            $table->text('dm_button_url')->nullable();

            $table->timestamp('last_run_at')->nullable();
            $table->string('last_error', 1024)->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['social_account_id', 'trigger_type', 'status'], 'automations_account_trigger_status_idx');
            $table->index(['user_id', 'status']);
        });

        Schema::create('automation_events', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 20);
            // Webhook entry.id = the IG professional account id
            // (= social_accounts.platform_account_id).
            $table->string('account_platform_id', 64);
            // comment | story_reply | dm
            $table->string('event_type', 20);
            // Comment id or message mid.
            $table->string('event_id', 120);
            $table->string('sender_id', 64);
            // The normalized event (not the whole webhook batch).
            $table->json('payload')->nullable();
            // received | matched | ignored | failed
            $table->string('status', 16)->default('received');
            $table->string('ignore_reason', 60)->nullable();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['platform', 'event_id'], 'automation_events_platform_event_unique');
            $table->index(['platform', 'account_platform_id', 'received_at'], 'automation_events_account_received_idx');
        });

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('trigger_type', 20);
            $table->string('event_id', 120);
            // IGSID (messages) or the commenter's IG user id.
            $table->string('sender_id', 64);
            $table->string('sender_username', 80)->nullable();
            // Comment media id or story id.
            $table->string('media_id', 64)->nullable();
            $table->string('inbound_text', 1000)->nullable();
            $table->string('matched_keyword', 60)->nullable();
            // pending | completed | partial | failed | skipped
            $table->string('status', 16)->default('pending');
            // sent | failed | skipped
            $table->string('reply_status', 16)->nullable();
            $table->string('reply_remote_id', 64)->nullable();
            // sent | failed | skipped
            $table->string('dm_status', 16)->nullable();
            $table->string('dm_remote_id', 120)->nullable();
            // First click on any of this run's tracked links.
            $table->timestamp('clicked_at')->nullable();
            $table->string('error', 1024)->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->unique(['automation_id', 'event_id'], 'automation_runs_automation_event_unique');
            $table->index(['automation_id', 'created_at']);
            $table->index(['automation_id', 'sender_id', 'created_at'], 'automation_runs_sender_cooldown_idx');
            $table->index(['user_id', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automation_events');
        Schema::dropIfExists('automations');
    }
};
