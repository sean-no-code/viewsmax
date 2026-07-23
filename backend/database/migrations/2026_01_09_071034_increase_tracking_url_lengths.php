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
        Schema::table('tracking_events', function (Blueprint $table) {
            $table->text('landing_page_url')->change();
        });

        Schema::table('tracking_goals', function (Blueprint $table) {
            $table->text('conversion_url')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tracking_events', function (Blueprint $table) {
            $table->string('landing_page_url', 255)->change();
        });

        Schema::table('tracking_goals', function (Blueprint $table) {
            $table->string('conversion_url', 255)->change();
        });
    }
};
