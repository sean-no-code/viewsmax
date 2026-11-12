<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How many posts a user has ever published, kept on the user because posts are
 * hard-deleted: App\Support\PostAllowance caps an unpaid account's posts in
 * total, and counting rows would hand a slot back for every published post the
 * account deletes. Post::booted() keeps it up to date; existing users start
 * from the published posts they still have.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('posts_published_count')->default(0);
        });

        DB::table('users')->update([
            'posts_published_count' => DB::raw("(select count(*) from posts where posts.user_id = users.id and posts.status = 'posted')"),
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('posts_published_count');
        });
    }
};
