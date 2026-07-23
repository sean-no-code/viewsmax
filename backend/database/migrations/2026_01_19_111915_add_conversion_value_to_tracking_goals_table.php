<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tracking_goals', function (Blueprint $table) {
            $table->decimal('conversion_value', 10, 2)->default(0)->after('conversion_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tracking_goals', function (Blueprint $table) {
            $table->dropColumn('conversion_value');
        });
    }
};
