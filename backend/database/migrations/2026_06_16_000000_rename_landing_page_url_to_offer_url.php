<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rename tracking_events.landing_page_url -> offer_url to match the
     * "Offer" domain language used across the app.
     */
    public function up(): void
    {
        Schema::table('tracking_events', function (Blueprint $table) {
            $table->renameColumn('landing_page_url', 'offer_url');
        });
    }

    public function down(): void
    {
        Schema::table('tracking_events', function (Blueprint $table) {
            $table->renameColumn('offer_url', 'landing_page_url');
        });
    }
};
