<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Keywords mined from competitors, scored for buying intent. Status walks
        // discovered -> drafted -> published (or skipped).
        Schema::create('seo_keywords', function (Blueprint $table) {
            $table->id();
            $table->string('keyword')->unique();
            $table->string('competitor_domain')->nullable();   // where it was mined from
            $table->string('competitor_url', 2048)->nullable(); // their ranking page
            $table->unsignedInteger('search_volume')->default(0);
            $table->unsignedInteger('difficulty')->default(0);
            $table->decimal('cpc', 8, 2)->default(0);
            $table->unsignedInteger('intent_score')->default(0);
            $table->string('status')->default('discovered')->index(); // discovered|drafted|published|skipped
            $table->string('source')->default('dataforseo');
            $table->timestamps();
        });

        Schema::create('seo_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seo_keyword_id')->constrained('seo_keywords')->cascadeOnDelete();
            $table->string('title');
            $table->string('slug');
            $table->string('meta_description', 320)->nullable();
            $table->longText('html');
            $table->string('status')->default('review')->index(); // review|queued|published|failed
            $table->string('wordpress_post_id')->nullable();
            $table->string('published_url', 2048)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        // Backlink opportunities: pages/domains that link to competitors (or fit
        // guest-post patterns) that we can go after. Status is a mini-CRM.
        Schema::create('seo_backlink_prospects', function (Blueprint $table) {
            $table->id();
            $table->string('url', 2048);
            $table->string('domain')->index();
            $table->string('competitor_domain')->nullable(); // who they currently link to
            $table->string('competitor_url', 2048)->nullable();
            $table->string('anchor')->nullable();
            $table->unsignedInteger('domain_rank')->default(0);
            $table->boolean('dofollow')->default(true);
            $table->string('status')->default('new')->index(); // new|contacted|won|rejected
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['domain', 'competitor_domain']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_backlink_prospects');
        Schema::dropIfExists('seo_articles');
        Schema::dropIfExists('seo_keywords');
    }
};
