<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Columns the automations feature needs on existing tables:
     *  - plans.max_automations           per-tier cap (NULL = unlimited)
     *  - social_accounts.webhook_subscribed_at  per-account Meta webhook
     *    subscription state (a real column so the daily re-subscribe
     *    command can query it on both Postgres and SQLite)
     *  - short_links.automation_id / automation_run_id  one tracked link per
     *    DM recipient, so a click is attributable to the run (CTR)
     */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('max_automations')->nullable()->after('max_posts_per_month');
        });

        Schema::table('social_accounts', function (Blueprint $table) {
            $table->timestamp('webhook_subscribed_at')->nullable()->after('last_synced_at');
        });

        Schema::table('short_links', function (Blueprint $table) {
            $table->foreignId('automation_id')->nullable()->after('tracking_link_id')
                ->constrained('automations')->nullOnDelete();
            $table->foreignId('automation_run_id')->nullable()->after('automation_id')
                ->constrained('automation_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('short_links', function (Blueprint $table) {
            $table->dropConstrainedForeignId('automation_run_id');
            $table->dropConstrainedForeignId('automation_id');
        });
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn('webhook_subscribed_at');
        });
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('max_automations');
        });
    }
};
