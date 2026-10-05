<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The audit log recorded that a tool call failed but not why, so error-rate
// spikes (get_outlier, create_post) couldn't be explained from the data.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_tool_invocations', function (Blueprint $table) {
            $table->text('error')->nullable()->after('is_error');
        });
    }

    public function down(): void
    {
        Schema::table('mcp_tool_invocations', function (Blueprint $table) {
            $table->dropColumn('error');
        });
    }
};
