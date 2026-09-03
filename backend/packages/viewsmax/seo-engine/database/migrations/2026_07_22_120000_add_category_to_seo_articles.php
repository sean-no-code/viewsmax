<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Article content categories (Guide: Explainer / Guide: How-to / List: Round-up
 * / List: Resources / List: Examples) — set by the writer, editable by the user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_articles', function (Blueprint $table) {
            $table->string('category')->nullable()->after('meta_description');
        });
    }

    public function down(): void
    {
        Schema::table('seo_articles', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
