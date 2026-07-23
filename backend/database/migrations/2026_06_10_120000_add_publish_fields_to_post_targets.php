<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            // Per-platform publish lifecycle: pending | publishing | published | failed
            $table->string('status')->default('pending')->after('caption_override');
            $table->string('platform_post_id')->nullable()->after('status');
            $table->text('error')->nullable()->after('platform_post_id');
            $table->timestamp('published_at')->nullable()->after('error');
            // Platform-specific publish settings + raw API payloads (e.g. TikTok
            // privacy_level, disable_comment/duet/stitch, publish_id, status fetches).
            $table->json('options')->nullable()->after('published_at');
            $table->json('meta')->nullable()->after('options');
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::table('post_targets', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'platform_post_id', 'error', 'published_at', 'options', 'meta']);
        });
    }
};
