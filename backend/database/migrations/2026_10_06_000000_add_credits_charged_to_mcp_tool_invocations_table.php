<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// MCP tool calls now cost credits (App\Mcp\Methods\SafeCallTool). Record what
// each call charged so the user's AI activity log can show where credits went.
// NULL = nothing charged (call failed, was refused, or predates metering).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mcp_tool_invocations', function (Blueprint $table) {
            $table->unsignedInteger('credits_charged')->nullable()->after('error');
        });
    }

    public function down(): void
    {
        Schema::table('mcp_tool_invocations', function (Blueprint $table) {
            $table->dropColumn('credits_charged');
        });
    }
};
