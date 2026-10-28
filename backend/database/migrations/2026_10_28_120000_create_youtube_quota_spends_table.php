<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('youtube_quota_spends', function (Blueprint $table) {
            $table->id();
            $table->string('resource', 40);
            $table->string('endpoint', 120);
            $table->string('method', 8);
            $table->unsignedSmallInteger('status')->nullable();
            $table->unsignedInteger('units');
            // No FK: rows outlive deleted users and the ledger must never block a delete.
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('spent_at');
            // The Pacific day the spend counts towards (Google's quota day).
            $table->date('pt_date');

            $table->index(['resource', 'pt_date']);
            $table->index('spent_at');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('youtube_quota_spends');
    }
};
