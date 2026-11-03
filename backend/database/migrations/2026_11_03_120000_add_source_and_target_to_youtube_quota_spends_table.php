<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('youtube_quota_spends', function (Blueprint $table) {
            // Which unit of work made the call (job:…, cmd:…, mcp:…, api:…) and
            // what it asked for (playlist / video ids / channel / query), so a
            // run of rows reads as one search's fan-out rather than as repeats.
            $table->string('source', 80)->nullable()->after('user_id')->index();
            $table->string('target', 120)->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('youtube_quota_spends', function (Blueprint $table) {
            $table->dropIndex(['source']);
            $table->dropColumn(['source', 'target']);
        });
    }
};
