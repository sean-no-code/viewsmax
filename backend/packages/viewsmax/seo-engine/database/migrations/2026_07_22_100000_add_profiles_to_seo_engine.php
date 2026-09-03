<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-tenant SEO: the engine is a USER feature, not an admin tool. Each user
 * configures one profile per offer (their competitors, their WordPress blog,
 * their cadence) and every keyword/article/prospect is scoped to that profile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The offer this profile promotes (offers = tracking_events).
            $table->foreignId('tracking_event_id')->constrained('tracking_events')->cascadeOnDelete();
            $table->json('competitors');            // ["hootsuite.com", ...]
            $table->string('wp_url')->nullable();
            $table->string('wp_username')->nullable();
            $table->text('wp_app_password')->nullable(); // encrypted cast
            $table->unsignedInteger('articles_per_week')->default(3);
            $table->boolean('auto_publish')->default(true);
            $table->boolean('enabled')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'tracking_event_id']);
        });

        // Scope the three datasets to a profile. Keyword uniqueness becomes
        // per-profile (two users can chase the same term).
        Schema::table('seo_keywords', function (Blueprint $table) {
            $table->foreignId('seo_profile_id')->nullable()->after('id')->constrained('seo_profiles')->cascadeOnDelete();
            $table->dropUnique(['keyword']);
            $table->unique(['seo_profile_id', 'keyword']);
        });
        Schema::table('seo_articles', function (Blueprint $table) {
            $table->foreignId('seo_profile_id')->nullable()->after('id')->constrained('seo_profiles')->cascadeOnDelete();
        });
        Schema::table('seo_backlink_prospects', function (Blueprint $table) {
            $table->foreignId('seo_profile_id')->nullable()->after('id')->constrained('seo_profiles')->cascadeOnDelete();
            $table->dropUnique(['domain', 'competitor_domain']);
            $table->unique(['seo_profile_id', 'domain', 'competitor_domain'], 'seo_prospects_profile_domain_unique');
        });
    }

    public function down(): void
    {
        Schema::table('seo_backlink_prospects', function (Blueprint $table) {
            $table->dropUnique('seo_prospects_profile_domain_unique');
            $table->dropConstrainedForeignId('seo_profile_id');
            $table->unique(['domain', 'competitor_domain']);
        });
        Schema::table('seo_articles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('seo_profile_id');
        });
        Schema::table('seo_keywords', function (Blueprint $table) {
            $table->dropUnique(['seo_profile_id', 'keyword']);
            $table->dropConstrainedForeignId('seo_profile_id');
            $table->unique(['keyword']);
        });
        Schema::dropIfExists('seo_profiles');
    }
};
