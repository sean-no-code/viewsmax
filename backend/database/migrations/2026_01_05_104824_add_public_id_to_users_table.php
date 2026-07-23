<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('public_id')->unique()->after('id')->nullable();
        });
        
        // Populate existing users
        DB::table('users')->orderBy('id')->chunk(100, function ($users) {
            foreach ($users as $user) {
                if (empty($user->public_id)) {
                    DB::table('users')
                        ->where('id', $user->id)
                        ->update(['public_id' => Str::uuid()]);
                }
            }
        });

        // Make it not nullable after population
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('public_id')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('public_id');
        });
    }
};
