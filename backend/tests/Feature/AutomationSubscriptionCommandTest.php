<?php

namespace Tests\Feature;

use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\AutomationRun;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Housekeeping: stale webhook subscriptions are renewed only for accounts
 * with live automations; old ledger rows are pruned without breaking runs.
 */
class AutomationSubscriptionCommandTest extends TestCase
{
    use RefreshDatabase;

    private function account(User $user, string $igId, ?\DateTimeInterface $subscribedAt): SocialAccount
    {
        return SocialAccount::create([
            'user_id' => $user->id, 'platform' => 'instagram', 'platform_account_id' => $igId,
            'access_token' => 't', 'token_expires_at' => now()->addMonth(), 'status' => SocialAccount::STATUS_CONNECTED,
            'scopes' => Automation::REQUIRED_IG_SCOPES, 'webhook_subscribed_at' => $subscribedAt,
        ]);
    }

    private function automation(SocialAccount $account, string $status = Automation::STATUS_LIVE): Automation
    {
        return Automation::create([
            'user_id' => $account->user_id, 'social_account_id' => $account->id, 'trigger_type' => 'dm',
            'status' => $status, 'keyword_mode' => 'any', 'dm_text' => 'hi',
        ]);
    }

    public function test_ensure_subscriptions_renews_stale_accounts_with_live_automations_only(): void
    {
        Http::fake(['graph.instagram.com/*/subscribed_apps' => Http::response(['success' => true])]);
        $user = User::factory()->create();
        $stale = $this->account($user, 'ig-stale', now()->subDays(40));
        $never = $this->account($user, 'ig-never', null);
        $fresh = $this->account($user, 'ig-fresh', now()->subDay());
        $stoppedOnly = $this->account($user, 'ig-stopped', null);
        $this->automation($stale);
        $this->automation($never);
        $this->automation($fresh);
        $this->automation($stoppedOnly, Automation::STATUS_STOPPED);

        $this->artisan('automations:ensure-subscriptions')->assertExitCode(0);

        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/ig-stale/subscribed_apps'));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/ig-never/subscribed_apps'));
        $this->assertTrue($stale->fresh()->webhook_subscribed_at->gt(now()->subMinute()));
        $this->assertNotNull($never->fresh()->webhook_subscribed_at);
        $this->assertNull($stoppedOnly->fresh()->webhook_subscribed_at);
    }

    public function test_prune_events_deletes_old_rows_and_keeps_runs(): void
    {
        $user = User::factory()->create();
        $account = $this->account($user, 'ig-1', now());
        $automation = $this->automation($account);
        $old = AutomationEvent::create(['platform' => 'instagram', 'account_platform_id' => 'ig-1', 'event_type' => 'dm', 'event_id' => 'm-old', 'sender_id' => 'u', 'status' => 'matched', 'received_at' => now()->subDays(45)]);
        $recent = AutomationEvent::create(['platform' => 'instagram', 'account_platform_id' => 'ig-1', 'event_type' => 'dm', 'event_id' => 'm-new', 'sender_id' => 'u', 'status' => 'matched', 'received_at' => now()->subDay()]);
        $run = AutomationRun::create(['automation_id' => $automation->id, 'user_id' => $user->id, 'automation_event_id' => $old->id, 'trigger_type' => 'dm', 'event_id' => 'm-old', 'sender_id' => 'u', 'status' => 'completed']);

        $this->artisan('automations:prune-events --days=30')->assertExitCode(0);

        $this->assertNull(AutomationEvent::find($old->id));
        $this->assertNotNull(AutomationEvent::find($recent->id));
        $this->assertNull($run->fresh()->automation_event_id);
        $this->assertSame('completed', $run->fresh()->status);
    }
}
