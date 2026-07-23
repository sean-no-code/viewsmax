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
        Schema::table('users', function (Blueprint $table) {
            // YouTube OAuth fields
            $table->text('youtube_access_token')->nullable();
            $table->text('youtube_refresh_token')->nullable();
            $table->timestamp('youtube_token_expires_at')->nullable();
            
            // Privacy consent fields
            $table->timestamp('privacy_consent_at')->nullable();
            $table->string('privacy_consent_version', 50)->nullable();
            $table->string('privacy_consent_ip', 45)->nullable();
            $table->text('privacy_consent_user_agent')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Drop YouTube OAuth fields
            $table->dropColumn([
                'youtube_access_token',
                'youtube_refresh_token',
                'youtube_token_expires_at'
            ]);
            
            // Drop privacy consent fields
            $table->dropColumn([
                'privacy_consent_at',
                'privacy_consent_version',
                'privacy_consent_ip',
                'privacy_consent_user_agent'
            ]);
        });
    }
};
