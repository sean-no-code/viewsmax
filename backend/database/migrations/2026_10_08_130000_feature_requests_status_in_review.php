<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Feature requests now go through in_review → approved → implemented and are
// hidden from other users until approved. Requests that already existed were
// public, so they keep their visibility: anything not already marked done
// becomes "approved".
return new class extends Migration
{
    public function up(): void
    {
        DB::table('feature_requests')
            ->whereIn(DB::raw('lower(status)'), ['done', 'implemented', 'shipped', 'released'])
            ->update(['status' => 'implemented']);
        DB::table('feature_requests')
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'implemented'))
            ->update(['status' => 'approved']);

        Schema::table('feature_requests', function (Blueprint $table) {
            $table->string('status')->default('in_review')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('feature_requests', function (Blueprint $table) {
            $table->string('status')->nullable()->default(null)->change();
        });
    }
};
