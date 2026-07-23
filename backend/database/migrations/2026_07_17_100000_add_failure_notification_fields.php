<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Per-user opt-out for publish-failure emails; on by default so
            // failures are never silent for existing users.
            $table->boolean('notify_post_failures')->default(true);
        });

        Schema::table('posts', function (Blueprint $table) {
            // Set when a failure email has been scheduled for the current
            // failure episode; cleared by a manual retry so a re-failure
            // notifies again.
            $table->timestamp('failure_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notify_post_failures');
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('failure_notified_at');
        });
    }
};
