<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            // Display hint for MCP API keys (first 8 + last 5 chars of the
            // plaintext). Safe to store unencrypted; the key itself is hashed.
            $table->string('key_hint', 32)->nullable()->after('abilities');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('key_hint');
        });
    }
};
