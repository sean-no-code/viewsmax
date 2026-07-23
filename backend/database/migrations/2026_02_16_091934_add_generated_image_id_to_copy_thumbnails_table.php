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
        Schema::table('copy_thumbnails', function (Blueprint $table) {
            $table->foreignId('generated_image_id')->nullable()->after('ai_model_id')->constrained('generated_images')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('copy_thumbnails', function (Blueprint $table) {
            $table->dropForeign(['generated_image_id']);
            $table->dropColumn('generated_image_id');
        });
    }
};
