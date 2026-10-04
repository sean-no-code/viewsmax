<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a user signed up: 'app' (SPA /api/register), 'agent' (the API host's
 * /register during an AI-agent OAuth connection), 'admin' (created by an
 * admin). NULL = before this existed, shown and filtered as 'app'.
 * signup_client is the OAuth client name (e.g. "Claude") for agent signups.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('signup_source', 16)->nullable()->index()->after('promo_expires_at');
            $table->string('signup_client')->nullable()->after('signup_source');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['signup_source']);
            $table->dropColumn(['signup_source', 'signup_client']);
        });
    }
};
