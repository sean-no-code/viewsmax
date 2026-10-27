<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permanent record of which ViewsMax user first connected each social
 * account (platform + the platform's own account id, plus the handle at the
 * time). Written on every connect, never removed by a disconnect or by
 * "Delete my data": a handle that was once connected to one ViewsMax account
 * can only be connected to that account again, so a second signup with the
 * same channel is sent to sign in with the original account instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_handle_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 40);
            $table->string('platform_account_id', 191);
            $table->string('handle', 255)->nullable();
            $table->timestamp('first_connected_at');
            $table->timestamp('last_connected_at');
            $table->timestamps();

            $table->unique(['platform', 'platform_account_id']);
            $table->index(['user_id', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_handle_claims');
    }
};
