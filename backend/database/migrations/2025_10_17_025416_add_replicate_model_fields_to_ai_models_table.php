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
        Schema::table('ai_models', function (Blueprint $table) {
            $table->string('replicate_model_name')->nullable()->after('huggingface_model_url');
            $table->string('replicate_model_url')->nullable()->after('replicate_model_name');
            $table->index(['replicate_model_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_models', function (Blueprint $table) {
            $table->dropIndex(['replicate_model_name']);
            $table->dropColumn(['replicate_model_name', 'replicate_model_url']);
        });
    }
};
