<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seo_articles', function (Blueprint $table) {
            $table->string('featured_image_url', 2048)->nullable()->after('html');
        });
    }

    public function down(): void
    {
        Schema::table('seo_articles', function (Blueprint $table) {
            $table->dropColumn('featured_image_url');
        });
    }
};
