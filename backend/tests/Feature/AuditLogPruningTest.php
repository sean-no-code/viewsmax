<?php

namespace Tests\Feature;

use App\Models\ApiKeyRequest;
use App\Models\McpToolInvocation;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The MCP and API-key audit logs (which carry full response bodies) keep 30
 * days: a daily model:prune deletes older rows.
 */
class AuditLogPruningTest extends TestCase
{
    use RefreshDatabase;

    public function test_rows_older_than_30_days_are_deleted(): void
    {
        $user = User::factory()->create();

        foreach ([31, 29] as $days) {
            $at = now()->subDays($days);
            McpToolInvocation::forceCreate(['user_id' => $user->id, 'tool' => "t{$days}", 'created_at' => $at]);
            ApiKeyRequest::forceCreate(['user_id' => $user->id, 'method' => 'GET', 'path' => "/p{$days}", 'status' => 200, 'created_at' => $at]);
        }

        $this->artisan('model:prune', ['--model' => [McpToolInvocation::class, ApiKeyRequest::class]])->assertSuccessful();

        $this->assertSame(['t29'], McpToolInvocation::pluck('tool')->all());
        $this->assertSame(['/p29'], ApiKeyRequest::pluck('path')->all());
    }

    public function test_pruning_is_scheduled_daily_for_both_logs(): void
    {
        // withSchedule() registers its events when Artisan boots.
        $this->app->make(Kernel::class)->all();

        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command ?? '', 'model:prune'));

        $this->assertNotNull($event, 'Expected model:prune to be scheduled.');
        $this->assertSame('0 0 * * *', $event->expression);
        $this->assertStringContainsString('McpToolInvocation', $event->command);
        $this->assertStringContainsString('ApiKeyRequest', $event->command);
    }
}
