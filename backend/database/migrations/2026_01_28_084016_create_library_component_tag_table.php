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
        Schema::create('library_component_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('library_component_id')->constrained()->cascadeOnDelete();
            $table->foreignId('library_component_tag_id')->constrained('library_component_tags')->cascadeOnDelete();
            $table->timestamps();
            
            $table->unique(['library_component_id', 'library_component_tag_id'], 'comp_tag_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('library_component_tag');
    }
};
