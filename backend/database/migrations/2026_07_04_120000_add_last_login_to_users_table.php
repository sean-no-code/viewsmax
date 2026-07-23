<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('last_login_at')->nullable()->after('onboarding_completed_at');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            $table->string('last_login_country')->nullable()->after('last_login_ip');
            $table->string('last_login_country_code', 2)->nullable()->after('last_login_country');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['last_login_at', 'last_login_ip', 'last_login_country', 'last_login_country_code']);
        });
    }
};
