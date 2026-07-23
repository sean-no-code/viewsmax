<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which brand the composer had selected when the post was created —
 * informational only. The publish path never reads it; targets already pin
 * their own accounts. Deleting a brand keeps its posts (brand_id nulls).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable()->after('user_id')->constrained('brands')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('brand_id');
        });
    }
};
