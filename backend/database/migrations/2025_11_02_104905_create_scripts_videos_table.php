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
        Schema::create('scripts_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('script_id')->constrained()->onDelete('cascade');
            $table->foreignId('video_id')->constrained()->onDelete('cascade');
            $table->timestamps();
            
            // Ensure unique combinations of script and video
            $table->unique(['script_id', 'video_id']);
            
            // Add indexes for better performance
            $table->index('script_id');
            $table->index('video_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scripts_videos');
    }
};
