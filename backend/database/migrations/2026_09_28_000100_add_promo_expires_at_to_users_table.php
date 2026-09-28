<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Promotional customers: admin-created users who get the product free for a
 * window (7/14/30 days) or forever, with no card on file. The window lives on
 * the user, not on user_plans, because there is no subscription to attach it
 * to — `promotional_customer` role + null here means "never expires".
 *
 * Also inserts the role so a deploy doesn't depend on re-running RoleSeeder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('promo_expires_at')->nullable()->after('card_added_at');
        });

        if (! DB::table('roles')->where('name', 'promotional_customer')->exists()) {
            DB::table('roles')->insert([
                'name' => 'promotional_customer',
                'display_name' => 'Promotional customer',
                'description' => 'Free access for a fixed window (or unlimited) with no card on file',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('promo_expires_at');
        });
    }
};
