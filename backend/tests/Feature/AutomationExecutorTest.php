<?php

namespace Tests\Feature;

use App\Jobs\ExecuteAutomationRunJob;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\ShortLink;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Automations\AutomationMessageBuilder;
use App\Services\Social\SocialProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Executing a run: public reply then private reply for comments, direct
 * message for story replies / DMs, tracked links per recipient, and the
 * failure classes (reauth, outside window, stopped).
 */
class AutomationExecutorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://vmx.test', 'social.platforms.instagram.client_secret' => 's']);
        $this->user = User::factory()->create();
        $this->account = SocialAccount::create([
            'user_id' => $this->user->id, 'platform' => 'instagram', 'platform_account_id' => 'ig-1',
            'access_token' => 'tok', 'token_expires_at' => now()->addMonth(), 'status' => SocialAccount::STATUS_CONNECTED,
            'scopes' => Automation::REQUIRED_IG_SCOPES,
        ]);
    }

    private function automation(array $attrs = []): Automation
    {
        return Automation::create(array_merge([
            'user_id' => $this->user->id, 'social_account_id' => $this->account->id, 'trigger_type' => 'comment',
            'status' => Automation::STATUS_LIVE, 'post_match' => 'any', 'keyword_mode' => 'any', 'dm_text' => 'Here you go',
        ], $attrs));
    }

    private function makeRun(Automation $a, array $attrs = []): AutomationRun
    {
        return AutomationRun::create(array_merge([
            'automation_id' => $a->id, 'user_id' => $this->user->id, 'trigger_type' => $a->trigger_type,
            'event_id' => 'c-1', 'sender_id' => 'u-1', 'status' => AutomationRun::STATUS_PENDING,
        ], $attrs));
    }

    private function execute(AutomationRun $run): AutomationRun
    {
        (new ExecuteAutomationRunJob($run->id, $this->account->id))
            ->handle(app(SocialProviderManager::class), app(AutomationMessageBuilder::class));

        return $run->fresh();
    }

    public function test_comment_trigger_replies_publicly_then_sends_a_private_reply_card(): void
    {
        Http::fake([
            'graph.instagram.com/*/c-1/replies' => Http::response(['id' => 'r-1']),
            'graph.instagram.com/*/ig-1/messages' => Http::response(['message_id' => 'm-1']),
        ]);
        $a = $this->automation([
            'reply_enabled' => true, 'reply_texts' => ['Sent you a DM!'],
            'dm_text' => 'Your guide', 'dm_subtitle' => 'Tap below', 'dm_button_label' => 'Get it', 'dm_button_url' => 'https://example.com/guide',
        ]);

        $run = $this->execute($this->makeRun($a));

        $this->assertSame(AutomationRun::STATUS_COMPLETED, $run->status, (string) $run->error);
        $this->assertSame(AutomationRun::REPLY_SENT, $run->reply_status);
        $this->assertSame('r-1', $run->reply_remote_id);
        $this->assertSame(AutomationRun::DM_SENT, $run->dm_status);
        $this->assertSame('m-1', $run->dm_remote_id);
        $this->assertNotNull($run->executed_at);
        $this->assertNotNull($a->fresh()->last_run_at);

        $link = ShortLink::sole();
        $this->assertSame($a->id, $link->automation_id);
        $this->assertSame($run->id, $link->automation_run_id);
        $this->assertSame('https://example.com/guide', $link->destination_url);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/c-1/replies') && $r['message'] === 'Sent you a DM!');
        Http::assertSent(function ($r) use ($link) {
            if (! str_contains($r->url(), '/ig-1/messages')) {
                return false;
            }
            $element = $r['message']['attachment']['payload']['elements'][0] ?? null;

            return $r['recipient'] === ['comment_id' => 'c-1']
                && $r['message']['attachment']['payload']['template_type'] === 'generic'
                && $element['title'] === 'Your guide'
                && $element['subtitle'] === 'Tap below'
                && $element['buttons'][0] === ['type' => 'web_url', 'url' => 'https://vmx.test/l/'.$link->slug, 'title' => 'Get it'];
        });
    }

    public function test_text_dm_shortens_inline_urls_per_run(): void
    {
        Http::fake(['graph.instagram.com/*/ig-1/messages' => Http::response(['message_id' => 'm'])]);
        $a = $this->automation(['trigger_type' => 'dm', 'dm_text' => 'Grab it: https://example.com/x and https://example.com/x again']);

        $first = $this->execute($this->makeRun($a, ['event_id' => 'm-1', 'sender_id' => 'u-1']));
        $second = $this->execute($this->makeRun($a, ['event_id' => 'm-2', 'sender_id' => 'u-2']));

        $this->assertSame(AutomationRun::STATUS_COMPLETED, $first->status);
        $this->assertSame(AutomationRun::STATUS_COMPLETED, $second->status);
        // One link per distinct URL per run → 2 rows, distinct slugs.
        $this->assertSame(2, ShortLink::count());
        $this->assertSame(2, ShortLink::distinct('slug')->count('slug'));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/ig-1/messages')
            && $r['recipient'] === ['id' => 'u-1']
            && substr_count($r['message']['text'], 'https://vmx.test/l/') === 2
            && ! str_contains($r['message']['text'], 'example.com'));
    }

    public function test_story_reply_sends_a_direct_message_without_a_public_reply(): void
    {
        Http::fake(['graph.instagram.com/*/ig-1/messages' => Http::response(['message_id' => 'm'])]);
        $a = $this->automation(['trigger_type' => 'story_reply', 'reply_enabled' => true, 'reply_texts' => ['x']]);

        $run = $this->execute($this->makeRun($a, ['event_id' => 'mid-1', 'sender_id' => 'igsid-7']));

        $this->assertSame(AutomationRun::STATUS_COMPLETED, $run->status);
        $this->assertNull($run->reply_status);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/replies'));
        Http::assertSent(fn ($r) => $r['recipient'] === ['id' => 'igsid-7'] && $r['message'] === ['text' => 'Here you go']);
    }

    public function test_failed_public_reply_does_not_block_the_dm(): void
    {
        Http::fake([
            'graph.instagram.com/*/c-1/replies' => Http::response(['error' => ['message' => 'nope', 'code' => 100]], 400),
            'graph.instagram.com/*/ig-1/messages' => Http::response(['message_id' => 'm-1']),
        ]);
        $a = $this->automation(['reply_enabled' => true, 'reply_texts' => ['hey']]);

        $run = $this->execute($this->makeRun($a));

        $this->assertSame(AutomationRun::STATUS_PARTIAL, $run->status);
        $this->assertSame(AutomationRun::REPLY_FAILED, $run->reply_status);
        $this->assertSame(AutomationRun::DM_SENT, $run->dm_status);
    }

    public function test_expired_token_marks_account_needs_reauth_and_fails_the_run(): void
    {
        Http::fake(['graph.instagram.com/*/ig-1/messages' => Http::response(['error' => ['message' => 'Session expired', 'type' => 'OAuthException', 'code' => 190]], 400)]);
        $a = $this->automation(['trigger_type' => 'dm']);

        $run = $this->execute($this->makeRun($a));

        $this->assertSame(AutomationRun::STATUS_FAILED, $run->status);
        $this->assertSame(AutomationRun::ERROR_NEEDS_REAUTH, $run->error);
        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $this->account->fresh()->status);
        $this->assertStringContainsString('reconnecting', $a->fresh()->last_error);
    }

    public function test_missing_scopes_fail_without_calling_instagram(): void
    {
        Http::fake();
        $this->account->update(['scopes' => ['instagram_business_basic']]);
        $a = $this->automation(['trigger_type' => 'dm']);

        $run = $this->execute($this->makeRun($a));

        $this->assertSame(AutomationRun::ERROR_NEEDS_REAUTH, $run->error);
        Http::assertNothingSent();
    }

    public function test_outside_window_fails_the_run_without_retry(): void
    {
        Http::fake(['graph.instagram.com/*/ig-1/messages' => Http::response(['error' => ['message' => 'outside of allowed window', 'code' => 10, 'error_subcode' => 2534022]], 400)]);
        $a = $this->automation(['trigger_type' => 'dm']);

        $run = $this->execute($this->makeRun($a));

        $this->assertSame(AutomationRun::STATUS_FAILED, $run->status);
        $this->assertSame(AutomationRun::ERROR_OUTSIDE_WINDOW, $run->error);
        $this->assertSame(AutomationRun::DM_FAILED, $run->dm_status);
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $this->account->fresh()->status);
    }

    public function test_stopped_automation_skips_the_run(): void
    {
        Http::fake();
        $a = $this->automation(['status' => Automation::STATUS_STOPPED]);

        $run = $this->execute($this->makeRun($a));

        $this->assertSame(AutomationRun::STATUS_SKIPPED, $run->status);
        $this->assertSame(AutomationRun::ERROR_STOPPED, $run->error);
        Http::assertNothingSent();
    }

    public function test_a_non_pending_run_is_never_executed_twice(): void
    {
        Http::fake();
        $a = $this->automation(['trigger_type' => 'dm']);
        $run = $this->makeRun($a, ['status' => AutomationRun::STATUS_COMPLETED]);

        $this->execute($run);

        Http::assertNothingSent();
    }

    public function test_clicking_a_run_link_marks_the_run_clicked_once(): void
    {
        $a = $this->automation();
        $run = $this->makeRun($a, ['status' => AutomationRun::STATUS_COMPLETED, 'dm_status' => AutomationRun::DM_SENT]);
        $link = ShortLink::create([
            'user_id' => $this->user->id, 'automation_id' => $a->id, 'automation_run_id' => $run->id,
            'slug' => 'runlink', 'destination_url' => 'https://example.com/guide',
        ]);

        $this->get('/l/runlink')->assertRedirect('https://example.com/guide');
        $first = $run->fresh()->clicked_at;
        $this->assertNotNull($first);

        $this->travel(5)->minutes();
        $this->get('/l/runlink')->assertRedirect();
        $this->assertTrue($run->fresh()->clicked_at->equalTo($first));
        $this->assertSame(2, $link->fresh()->clicks_count);

        $stats = Automation::withStats()->find($a->id);
        $this->assertSame(1, $stats->runs_count);
        $this->assertSame(1, $stats->dms_sent_count);
        $this->assertSame(1, $stats->clicked_count);
        $this->assertSame(1.0, $stats->ctr());
    }
}
