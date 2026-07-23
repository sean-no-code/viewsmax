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
        Schema::table('generated_images', function (Blueprint $table) {
            $table->string('base_image_url')->nullable()->after('base_image_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('generated_images', function (Blueprint $table) {
            $table->dropColumn('base_image_url');
        });
    }
};
