<?php

use App\Models\PostTarget;
use App\Models\SocialAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-account targets: a post may fan out to several accounts on the SAME
 * platform, so targets now pin the account they publish through.
 *
 * Deliberately NOT a foreign key: when a user disconnects an account, its
 * targets must fail loudly ("account disconnected") rather than silently
 * republishing through whichever account remains (nullOnDelete would do
 * exactly that via the legacy fallback).
 */
return new class extends Migration
{
    /** Platforms whose tokens live in the social_accounts store. */
    private const SOCIAL_PLATFORMS = ['x', 'linkedin', 'threads', 'instagram', 'facebook', 'bluesky'];

    public function up(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            $table->unsignedBigInteger('social_account_id')->nullable()->after('platform');
            $table->index('social_account_id');
        });

        Schema::table('post_targets', function (Blueprint $table) {
            $table->dropUnique(['post_id', 'platform']);
        });

        // A plain 3-column unique won't do: NULLs compare distinct in Postgres,
        // so legacy (null-account) rows could duplicate. Two partial uniques
        // keep both shapes honest. Laravel's builder can't express these.
        DB::statement('CREATE UNIQUE INDEX post_targets_post_account_unique ON post_targets (post_id, social_account_id) WHERE social_account_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX post_targets_post_platform_legacy_unique ON post_targets (post_id, platform) WHERE social_account_id IS NULL');

        // Backfill: pin existing social-platform targets to the account that
        // would have published them (the owner's newest connected account).
        PostTarget::whereNull('social_account_id')
            ->whereIn('platform', self::SOCIAL_PLATFORMS)
            ->with('post')
            ->chunkById(200, function ($targets) {
                foreach ($targets as $target) {
                    if (! $target->post) {
                        continue;
                    }
                    $accountId = SocialAccount::where('user_id', $target->post->user_id)
                        ->where('platform', $target->platform)
                        ->where('status', SocialAccount::STATUS_CONNECTED)
                        ->latest()
                        ->value('id');
                    if ($accountId) {
                        $target->forceFill(['social_account_id' => $accountId])->saveQuietly();
                    }
                }
            });
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS post_targets_post_account_unique');
        DB::statement('DROP INDEX IF EXISTS post_targets_post_platform_legacy_unique');

        Schema::table('post_targets', function (Blueprint $table) {
            $table->unique(['post_id', 'platform']);
            $table->dropIndex(['social_account_id']);
            $table->dropColumn('social_account_id');
        });
    }
};
