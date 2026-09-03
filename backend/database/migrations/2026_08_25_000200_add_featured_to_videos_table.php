<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Admin-curated default feed: featured videos are what visitors see on the
// Outliers browse page before they search. Outlier-domain table → outlier_db.
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('outlier_db')->table('videos', function (Blueprint $table) {
            $table->boolean('featured')->default(false);
        });
    }

    public function down(): void
    {
        Schema::connection('outlier_db')->table('videos', function (Blueprint $table) {
            $table->dropColumn('featured');
        });
    }
};
