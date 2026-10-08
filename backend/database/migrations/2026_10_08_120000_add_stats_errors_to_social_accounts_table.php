<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Why the last audience:refresh / posts:refresh-metrics run could not get data
// for the account, so the Analytics pages can tell the user instead of
// silently showing a gap. Cleared on the next successful run.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->string('follower_stats_error', 1024)->nullable()->after('last_error');
            $table->string('post_stats_error', 1024)->nullable()->after('follower_stats_error');
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn(['follower_stats_error', 'post_stats_error']);
        });
    }
};
