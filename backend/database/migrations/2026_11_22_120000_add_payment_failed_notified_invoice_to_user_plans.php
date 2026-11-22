<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_plans', function (Blueprint $table) {
            // The Stripe invoice the "payment failed" email was last sent for.
            // Stripe re-sends invoice.payment_failed on every retry of the same
            // invoice; this keeps the email to one per invoice.
            $table->string('payment_failed_notified_invoice_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user_plans', function (Blueprint $table) {
            $table->dropColumn('payment_failed_notified_invoice_id');
        });
    }
};
