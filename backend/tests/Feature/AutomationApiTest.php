<?php

namespace Tests\Feature;

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The /api/automations CRUD surface: feature flag, plan cap, scope gating
 * with the reconnect hint, validation, start/stop with webhook subscription,
 * index stats, ownership scoping and the media picker proxy.
 */
class AutomationApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'social.platforms.instagram.automations_enabled' => true,
            'social.platforms.instagram.client_id' => 'id',
            'social.platforms.instagram.client_secret' => 'secret',
        ]);
        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test')->plainTextToken;
        $this->account = $this->account($this->user, 'ig-1');
        Plan::create(['name' => 'free', 'display_name' => 'Free', 'price' => 0, 'is_active' => true, 'max_automations' => 3]);
    }

    private function account(User $user, string $igId, array $scopes = Automation::REQUIRED_IG_SCOPES): SocialAccount
    {
        return SocialAccount::create([
            'user_id' => $user->id, 'platform' => 'instagram', 'platform_account_id' => $igId, 'username' => 'me',
            'access_token' => 'tok', 'token_expires_at' => now()->addMonth(), 'status' => SocialAccount::STATUS_CONNECTED,
            'scopes' => array_merge(['instagram_business_basic'], $scopes),
        ]);
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'];
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'social_account_id' => $this->account->id,
            'name' => 'Auto-DM links',
            'trigger_type' => 'comment',
            'post_match' => 'specific',
            'posts' => [['id' => 'media-1', 'thumbnail_url' => 'https://cdn/1.jpg', 'permalink' => 'https://ig/p/1', 'caption' => 'Reel']],
            'keyword_mode' => 'contains',
            'keywords' => [' Link ', 'PRICE', 'link'],
            'reply_enabled' => true,
            'reply_texts' => ['Sent you a DM!'],
            'dm_text' => 'Here is the link',
            'dm_button_label' => 'Open',
            'dm_button_url' => 'https://example.com/guide',
        ], $overrides);
    }

    public function test_create_returns_the_automation_stopped_with_normalized_keywords(): void
    {
        $res = $this->withHeaders($this->auth())->postJson('/api/automations', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'stopped')
            ->assertJsonPath('data.keywords', ['link', 'price'])
            ->assertJsonPath('data.trigger_summary', 'User comments on a specific Post or Reel and comment contains link, price')
            ->assertJsonPath('data.stats.runs', 0)
            ->assertJsonPath('data.stats.ctr', null)
            ->assertJsonPath('data.needs_reconnect', false)
            ->assertJsonPath('data.social_account.username', 'me');

        $this->assertSame($this->user->id, Automation::find($res->json('data.id'))->user_id);
    }

    public function test_create_requires_the_feature_flag(): void
    {
        config(['social.platforms.instagram.automations_enabled' => false]);

        $this->withHeaders($this->auth())->postJson('/api/automations', $this->payload())->assertStatus(422);
    }

    public function test_create_requires_messaging_scopes_with_a_reconnect_hint(): void
    {
        $old = $this->account($this->user, 'ig-old', []);

        $this->withHeaders($this->auth())->postJson('/api/automations', $this->payload(['social_account_id' => $old->id]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'reconnect_required')
            ->assertJsonPath('social_account_id', $old->id)
            ->assertJsonPath('missing_scopes', Automation::REQUIRED_IG_SCOPES);
    }

    public function test_create_enforces_the_plan_cap(): void
    {
        Plan::where('name', 'free')->update(['max_automations' => 1]);

        $this->withHeaders($this->auth())->postJson('/api/automations', $this->payload())->assertCreated();
        $this->withHeaders($this->auth())->postJson('/api/automations', $this->payload())
            ->assertStatus(422)
            ->assertJsonFragment(['message' => "You've reached your plan's limit of 1 automation(s). Delete an existing automation or upgrade your plan to add more."]);

        // NULL = unlimited.
        Plan::where('name', 'free')->update(['max_automations' => null]);
        $this->withHeaders($this->auth())->postJson('/api/automations', $this->payload())->assertCreated();
    }

    public function test_create_validates_config(): void
    {
        $post = fn (array $o) => $this->withHeaders($this->auth())->postJson('/api/automations', $this->payload($o));

        $post(['keywords' => []])->assertStatus(422);                                      // contains needs keywords
        $post(['post_match' => 'specific', 'posts' => []])->assertStatus(422);              // specific needs posts
        $post(['trigger_type' => 'dm', 'reply_enabled' => true])->assertStatus(422);        // replies only for comments
        $post(['dm_text' => str_repeat('x', 81)])->assertStatus(422);                       // button ⇒ 80-char card title
        $post(['dm_button_label' => 'This label is way too long'])->assertStatus(422);      // 20-char button
        $post(['dm_button_url' => null, 'dm_button_label' => null, 'dm_text' => str_repeat('x', 500)])->assertCreated(); // text DM up to 1000
        $post(['trigger_type' => 'story_reply', 'post_match' => null, 'posts' => null, 'reply_enabled' => false, 'keyword_mode' => 'any'])
            ->assertCreated()->assertJsonPath('data.post_match', null)->assertJsonPath('data.keywords', null);
        $post(['social_account_id' => 999])->assertNotFound();
    }

    public function test_start_subscribes_webhooks_and_goes_live(): void
    {
        Http::fake(['graph.instagram.com/*/ig-1/subscribed_apps' => Http::response(['success' => true])]);
        $id = $this->withHeaders($this->auth())->postJson('/api/automations', $this->payload())->json('data.id');

        $this->withHeaders($this->auth())->postJson("/api/automations/{$id}/start")
            ->assertOk()->assertJsonPath('data.status', 'live');

        Http::assertSent(fn ($r) => str_contains($r->url(), '/ig-1/subscribed_apps') && $r['subscribed_fields'] === 'comments,messages');
        $this->assertNotNull($this->account->fresh()->webhook_subscribed_at);

        // Fresh subscription → start again without another Graph call.
        $this->withHeaders($this->auth())->postJson("/api/automations/{$id}/stop")->assertOk()->assertJsonPath('data.status', 'stopped');
        $this->withHeaders($this->auth())->postJson("/api/automations/{$id}/start")->assertOk();
        Http::assertSentCount(1);
    }

    public function test_start_fails_when_meta_refuses_the_subscription_or_scopes_are_missing(): void
    {
        Http::fake(['graph.instagram.com/*/subscribed_apps' => Http::response(['error' => ['message' => 'no', 'code' => 100]], 400)]);
        $id = $this->withHeaders($this->auth())->postJson('/api/automations', $this->payload())->json('data.id');

        $this->withHeaders($this->auth())->postJson("/api/automations/{$id}/start")->assertStatus(422);
        $this->assertSame('stopped', Automation::find($id)->status);

        // Scopes lost (e.g. account reconnected before the flag was on).
        $this->account->update(['scopes' => ['instagram_business_basic']]);
        $this->withHeaders($this->auth())->postJson("/api/automations/{$id}/start")
            ->assertStatus(422)->assertJsonPath('code', 'reconnect_required');
    }

    public function test_index_returns_stats_and_filters(): void
    {
        $a = Automation::create($this->payload(['user_id' => $this->user->id, 'platform' => 'instagram', 'status' => 'live', 'keywords' => ['link']]));
        $b = Automation::create($this->payload(['user_id' => $this->user->id, 'platform' => 'instagram', 'name' => 'Story info', 'trigger_type' => 'story_reply', 'post_match' => null, 'posts' => null, 'reply_enabled' => false]));
        foreach ([['m-1', 'sent', true], ['m-2', 'sent', false], ['m-3', 'failed', false]] as [$event, $dm, $clicked]) {
            AutomationRun::create([
                'automation_id' => $a->id, 'user_id' => $this->user->id, 'trigger_type' => 'comment', 'event_id' => $event, 'sender_id' => 'u',
                'status' => $dm === 'sent' ? 'completed' : 'failed', 'dm_status' => $dm, 'clicked_at' => $clicked ? now() : null,
            ]);
        }

        $res = $this->withHeaders($this->auth())->getJson('/api/automations')->assertOk();
        $rows = collect($res->json('data.automations'))->keyBy('id');
        $this->assertSame(['runs' => 3, 'dms_sent' => 2, 'clicked' => 1, 'ctr' => 0.5], $rows[$a->id]['stats']);
        $this->assertSame(['runs' => 0, 'dms_sent' => 0, 'clicked' => 0, 'ctr' => null], $rows[$b->id]['stats']);
        $this->assertSame(3, $res->json('data.limit'));
        $this->assertSame(2, $res->json('data.used'));
        $this->assertTrue($res->json('data.enabled'));

        $this->withHeaders($this->auth())->getJson('/api/automations?trigger_type=story_reply')->assertOk()->assertJsonCount(1, 'data.automations');
        $this->withHeaders($this->auth())->getJson('/api/automations?status=live')->assertOk()->assertJsonPath('data.automations.0.id', $a->id);
        $this->withHeaders($this->auth())->getJson('/api/automations?search=story')->assertOk()->assertJsonPath('data.automations.0.id', $b->id);
    }

    public function test_update_show_runs_and_delete_are_scoped_to_the_owner(): void
    {
        $id = $this->withHeaders($this->auth())->postJson('/api/automations', $this->payload())->json('data.id');
        $stranger = User::factory()->create()->createToken('t')->plainTextToken;
        $foreign = ['Authorization' => 'Bearer '.$stranger, 'Accept' => 'application/json'];

        Auth::forgetGuards(); // RequestGuard caches the first user across in-test requests
        $this->withHeaders($foreign)->getJson("/api/automations/{$id}")->assertNotFound();
        $this->withHeaders($foreign)->putJson("/api/automations/{$id}", ['name' => 'x'])->assertNotFound();
        $this->withHeaders($foreign)->getJson("/api/automations/{$id}/runs")->assertNotFound();
        $this->withHeaders($foreign)->deleteJson("/api/automations/{$id}")->assertNotFound();
        Auth::forgetGuards();

        $this->withHeaders($this->auth())->putJson("/api/automations/{$id}", ['name' => 'Renamed', 'keyword_mode' => 'any', 'dm_button_url' => null, 'dm_button_label' => null])
            ->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.keywords', null)->assertJsonPath('data.dm_button_url', null);
        $this->withHeaders($this->auth())->getJson("/api/automations/{$id}")->assertOk()->assertJsonPath('data.recent_runs', []);
        $this->withHeaders($this->auth())->getJson("/api/automations/{$id}/runs")->assertOk()->assertJsonPath('data.total', 0);
        $this->withHeaders($this->auth())->deleteJson("/api/automations/{$id}")->assertOk();
        $this->assertSoftDeleted('automations', ['id' => $id]);
        $this->withHeaders($this->auth())->getJson('/api/automations')->assertOk()->assertJsonCount(0, 'data.automations');
    }

    public function test_accounts_endpoint_reports_scope_status(): void
    {
        $old = $this->account($this->user, 'ig-old', []);

        $res = $this->withHeaders($this->auth())->getJson('/api/automations/accounts')->assertOk();
        $rows = collect($res->json('data.accounts'))->keyBy('id');
        $this->assertTrue($rows[$this->account->id]['has_messaging_scopes']);
        $this->assertTrue($rows[$this->account->id]['can_automate']);
        $this->assertFalse($rows[$old->id]['has_messaging_scopes']);
        $this->assertSame(Automation::REQUIRED_IG_SCOPES, $rows[$old->id]['missing_scopes']);
    }

    public function test_media_endpoint_proxies_caches_and_refreshes(): void
    {
        Http::fake(['graph.instagram.com/*/me/media*' => Http::response(['data' => [['id' => '1', 'media_type' => 'IMAGE', 'media_url' => 'https://cdn/1.jpg']], 'paging' => ['cursors' => ['after' => 'C2']]])]);

        $this->withHeaders($this->auth())->getJson("/api/automations/media?social_account_id={$this->account->id}")
            ->assertOk()->assertJsonPath('data.items.0.id', '1')->assertJsonPath('data.next_cursor', 'C2');
        $this->withHeaders($this->auth())->getJson("/api/automations/media?social_account_id={$this->account->id}")->assertOk();
        Http::assertSentCount(1);

        $this->withHeaders($this->auth())->getJson("/api/automations/media?social_account_id={$this->account->id}&refresh=1")->assertOk();
        Http::assertSentCount(2);

        // Someone else's account is a 404.
        $other = $this->account(User::factory()->create(), 'ig-x');
        $this->withHeaders($this->auth())->getJson("/api/automations/media?social_account_id={$other->id}")->assertNotFound();
    }

    public function test_media_endpoint_flags_reauth_on_an_expired_token(): void
    {
        Http::fake(['graph.instagram.com/*/me/media*' => Http::response(['error' => ['message' => 'expired', 'type' => 'OAuthException', 'code' => 190]], 400)]);

        $this->withHeaders($this->auth())->getJson("/api/automations/media?social_account_id={$this->account->id}")
            ->assertStatus(422)->assertJsonPath('code', 'reconnect_required');
    }
}
