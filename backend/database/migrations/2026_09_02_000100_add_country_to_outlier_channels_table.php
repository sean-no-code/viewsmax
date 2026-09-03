<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Channel country (ISO 3166-1 alpha-2, from YouTube snippet.country) for the
 * outliers countries filter. Runs on the outlier_db connection — outlier-domain
 * tables never live on the main DB.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('outlier_db')->table('channels', function (Blueprint $table) {
            $table->string('country', 2)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::connection('outlier_db')->table('channels', function (Blueprint $table) {
            $table->dropIndex('channels_country_index');
        });
        Schema::connection('outlier_db')->table('channels', function (Blueprint $table) {
            $table->dropColumn('country');
        });
    }
};
