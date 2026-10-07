<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per recipient (customer / admin) per publish-failure episode,
     * whether the email was sent, skipped (and why) or failed. The failed
     * platforms and their errors are copied in at send time because a retry
     * nulls post_targets.error. Append-only.
     */
    public function up(): void
    {
        Schema::create('post_failure_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // the post's owner
            $table->string('recipient_type', 20); // customer | admin
            $table->string('recipient_email')->nullable(); // null when skipped for no address
            $table->string('status', 20); // sent | skipped | failed
            $table->string('reason')->nullable(); // opted_out | recovered | no_admin_address | exception message
            $table->json('failures')->nullable(); // [{platform, social_account_id, error}]
            $table->timestamp('created_at');

            $table->index(['post_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_failure_notifications');
    }
};
