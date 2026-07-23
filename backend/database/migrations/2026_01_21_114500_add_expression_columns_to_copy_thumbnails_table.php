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
            $table->string('source_image_url')->nullable()->after('source_image_path');
            $table->text('extracted_expression')->nullable()->after('comfy_prompt_id');
            $table->text('transformed_prompt')->nullable()->after('extracted_expression');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('copy_thumbnails', function (Blueprint $table) {
            $table->dropColumn(['source_image_url', 'extracted_expression', 'transformed_prompt']);
        });
    }
};
