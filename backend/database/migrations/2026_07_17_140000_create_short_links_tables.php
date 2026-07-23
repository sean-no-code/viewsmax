<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shortlinks: tracked /l/{slug} redirects minted from URLs found in post
 * captions (opt-in per post). Deliberately separate from tracking_links —
 * those are offer-scoped query-parameter tags, not standalone redirects.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('short_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->nullable()->constrained()->nullOnDelete();
            $table->string('slug', 16)->unique();
            $table->text('destination_url');
            $table->unsignedInteger('clicks_count')->default(0);
            $table->timestamp('last_clicked_at')->nullable();
            $table->timestamps();
            $table->index(['post_id']);
        });

        Schema::create('short_link_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('short_link_id')->constrained()->cascadeOnDelete();
            // The tracker's client-generated visitor UUID (cookie) when present —
            // joins to tracking_visitors.visitor_id for cross-attribution.
            $table->uuid('visitor_uuid')->nullable()->index();
            $table->string('referer', 1024)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->boolean('shorten_links')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('short_link_clicks');
        Schema::dropIfExists('short_links');
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('shorten_links');
        });
    }
};
