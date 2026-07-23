<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Offers (tracking_events) gain soft-delete so deleting one preserves its
     * tracking history while freeing a plan slot. Soft-deleted offers are
     * invisible to the user and do not count toward the offer limit.
     */
    public function up(): void
    {
        Schema::table('tracking_events', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('tracking_events', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
