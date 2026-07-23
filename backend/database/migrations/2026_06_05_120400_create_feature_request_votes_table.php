<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_request_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('feature_request_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['user_id', 'feature_request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_request_votes');
    }
};
