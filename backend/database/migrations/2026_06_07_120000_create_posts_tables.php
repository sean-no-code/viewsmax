<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('posts')) {
            Schema::create('posts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->text('caption')->nullable();
                $table->json('media')->nullable();              // [{type:'image'|'video', ...}]
                $table->string('status')->default('draft');     // draft | scheduled | posted
                $table->timestamp('scheduled_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'status']);
                $table->index('scheduled_at');
            });
        }

        if (!Schema::hasTable('post_targets')) {
            Schema::create('post_targets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('post_id')->constrained()->cascadeOnDelete();
                $table->string('platform');                     // youtube | tiktok | instagram | x | ...
                $table->text('caption_override')->nullable();
                $table->timestamps();
                $table->unique(['post_id', 'platform']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('post_targets');
        Schema::dropIfExists('posts');
    }
};
