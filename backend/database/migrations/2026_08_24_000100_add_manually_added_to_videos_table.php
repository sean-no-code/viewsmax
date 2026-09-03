<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Videos added deliberately by URL are exempt from the browse min-score gate;
// this flag marks them. Outlier-domain table → outlier_db connection.
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('outlier_db')->table('videos', function (Blueprint $table) {
            $table->boolean('manually_added')->default(false);
        });
    }

    public function down(): void
    {
        Schema::connection('outlier_db')->table('videos', function (Blueprint $table) {
            $table->dropColumn('manually_added');
        });
    }
};
