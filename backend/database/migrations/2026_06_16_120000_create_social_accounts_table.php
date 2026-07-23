<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * Stores a single connected social profile/page for a user. A user can have
     * multiple accounts on the same platform (e.g. several Facebook Pages or
     * Instagram business accounts), so the unique key spans platform + the
     * platform's own account id.
     */
    public function up(): void
    {
        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            // facebook | instagram | threads | linkedin | bluesky | x | tiktok | youtube | google_business
            $table->string('platform')->index();

            // The platform's identifier for this specific account/page/profile.
            $table->string('platform_account_id')->nullable();

            // Human-readable details for display in the UI.
            $table->string('name')->nullable();
            $table->string('username')->nullable();
            $table->string('avatar_url', 1024)->nullable();
            $table->string('profile_url', 1024)->nullable();

            // OAuth credentials. Tokens are encrypted at the model layer.
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();
            $table->json('scopes')->nullable();

            // Platform-specific extras (page access tokens, IG user id, AT proto DID,
            // GMB account/location ids, open id, etc.).
            $table->json('metadata')->nullable();

            // connected | needs_reauth | revoked | error
            $table->string('status')->default('connected')->index();
            $table->string('last_error', 1024)->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'platform', 'platform_account_id'], 'social_accounts_user_platform_account_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('social_accounts');
    }
};
