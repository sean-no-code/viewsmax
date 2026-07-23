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
        Schema::table('thumbnails', function (Blueprint $table) {
            $table->string('comfy_prompt_id')->nullable()->after('ai_model_id');
            $table->index('comfy_prompt_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('thumbnails', function (Blueprint $table) {
            $table->dropIndex(['comfy_prompt_id']);
            $table->dropColumn('comfy_prompt_id');
        });
    }
};
