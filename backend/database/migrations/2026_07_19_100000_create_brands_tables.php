<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Brands group multiple connected accounts so the composer can select them in
 * one click. Membership spans BOTH account stores — social_accounts and the
 * legacy connections table (YouTube/TikTok) — via one pivot with two nullable
 * FK columns, exactly one of which is set per row.
 *
 * Unlike post_targets.social_account_id (deliberately no FK — targets must
 * fail loudly), these ARE real cascading FKs: disconnecting an account should
 * silently drop it from its brands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->timestamps();
            $table->unique(['user_id', 'name']);
        });

        Schema::create('brand_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('social_account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('connection_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        // NULLs compare distinct, so a plain unique can't stop duplicate
        // members. Two partial uniques (per store) keep membership honest —
        // same approach as post_targets; works on both Postgres and SQLite.
        DB::statement('CREATE UNIQUE INDEX brand_accounts_brand_social_unique ON brand_accounts (brand_id, social_account_id) WHERE social_account_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX brand_accounts_brand_connection_unique ON brand_accounts (brand_id, connection_id) WHERE connection_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_accounts');
        Schema::dropIfExists('brands');
    }
};
