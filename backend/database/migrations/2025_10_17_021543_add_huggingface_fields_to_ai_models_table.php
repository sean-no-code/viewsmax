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
            $table->string('huggingface_model_id')->nullable()->after('replicates_prediction_id');
            $table->string('huggingface_model_url')->nullable()->after('huggingface_model_id');
            $table->index(['huggingface_model_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_models', function (Blueprint $table) {
            $table->dropIndex(['huggingface_model_id']);
            $table->dropColumn(['huggingface_model_id', 'huggingface_model_url']);
        });
    }
};
