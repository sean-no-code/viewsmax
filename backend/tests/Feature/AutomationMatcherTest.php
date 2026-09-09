<?php

namespace Tests\Feature;

use App\Jobs\ExecuteAutomationRunJob;
use App\Jobs\ProcessInstagramInboundEventJob;
use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\AutomationRun;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Automations\AutomationMatcher;
use App\Services\Automations\Data\InboundEvent;
use App\Services\Automations\Data\MatchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Which automation an inbound event fires: post filter, keyword modes,
 * thread replies, per-sender cooldown, priority, and the ledger + run rows
 * the inbound job writes.
 */
class AutomationMatcherTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->user = User::factory()->create();
        $this->account = $this->makeAccount($this->user, 'ig-1');
    }

    private function makeAccount(User $user, string $igId): SocialAccount
    {
        return SocialAccount::create([
            'user_id' => $user->id, 'platform' => 'instagram', 'platform_account_id' => $igId,
            'access_token' => 't', 'token_expires_at' => now()->addMonth(), 'status' => SocialAccount::STATUS_CONNECTED,
            'scopes' => Automation::REQUIRED_IG_SCOPES,
        ]);
    }

    private function automation(array $attrs = [], ?SocialAccount $account = null): Automation
    {
        $account ??= $this->account;

        return Automation::create(array_merge([
            'user_id' => $account->user_id, 'social_account_id' => $account->id, 'trigger_type' => 'comment',
            'status' => Automation::STATUS_LIVE, 'post_match' => 'any', 'keyword_mode' => 'any', 'dm_text' => 'hi',
        ], $attrs));
    }

    private function comment(string $text = 'link please', string $media = 'media-1', string $id = 'c-1', string $sender = 'u-1', ?string $parent = null, string $ig = 'ig-1'): InboundEvent
    {
        return new InboundEvent('instagram', $ig, 'comment', $id, $sender, 'fan', $text, $media, $parent);
    }

    public function test_specific_post_with_keyword_beats_any_post_any_keyword(): void
    {
        $generic = $this->automation();
        $specific = $this->automation(['post_match' => 'specific', 'posts' => [['id' => 'media-1']], 'keyword_mode' => 'contains', 'keywords' => ['link']]);
        $anyPostKeyword = $this->automation(['keyword_mode' => 'contains', 'keywords' => ['link']]);

        $result = app(AutomationMatcher::class)->match($this->comment('LINK please'));

        $this->assertSame($specific->id, $result->automation->id);
        $this->assertSame('link', $result->keyword);

        // Other media → the specific one is out; keyword beats any.
        $this->assertSame($anyPostKeyword->id, app(AutomationMatcher::class)->match($this->comment('link', 'media-2'))->automation->id);
        // No keyword → only the generic one fits.
        $this->assertSame($generic->id, app(AutomationMatcher::class)->match($this->comment('nice!', 'media-2'))->automation->id);
    }

    public function test_ignore_reasons_explain_near_misses(): void
    {
        $matcher = app(AutomationMatcher::class);

        $this->assertSame(MatchResult::NO_ACCOUNT, $matcher->match($this->comment(ig: 'ig-unknown'))->ignoreReason);
        $this->assertSame(MatchResult::NO_LIVE_AUTOMATIONS, $matcher->match($this->comment())->ignoreReason);

        $this->automation(['status' => Automation::STATUS_STOPPED]);
        $this->assertSame(MatchResult::NO_LIVE_AUTOMATIONS, $matcher->match($this->comment())->ignoreReason);

        $a = $this->automation(['post_match' => 'specific', 'posts' => [['id' => 'media-1']], 'keyword_mode' => 'exact', 'keywords' => ['link']]);
        $this->assertSame(MatchResult::MEDIA_MISMATCH, $matcher->match($this->comment('link', 'media-2'))->ignoreReason);
        $this->assertSame(MatchResult::KEYWORD_MISMATCH, $matcher->match($this->comment('the link please'))->ignoreReason);
        $this->assertSame(MatchResult::THREAD_REPLY, $matcher->match($this->comment('link', parent: 'c-0'))->ignoreReason);

        $a->update(['include_replies' => true]);
        $this->assertTrue($matcher->match($this->comment('link', parent: 'c-0'))->isMatch());
    }

    public function test_sender_cooldown_skips_a_second_event_within_the_window(): void
    {
        $a = $this->automation(['cooldown_hours' => 24]);
        AutomationRun::create([
            'automation_id' => $a->id, 'user_id' => $this->user->id, 'trigger_type' => 'comment',
            'event_id' => 'c-old', 'sender_id' => 'u-1', 'status' => 'completed', 'created_at' => now()->subHours(2),
        ]);
        $matcher = app(AutomationMatcher::class);

        $this->assertSame(MatchResult::COOLDOWN, $matcher->match($this->comment(id: 'c-new'))->ignoreReason);
        // Another sender is fine.
        $this->assertTrue($matcher->match($this->comment(id: 'c-new', sender: 'u-2'))->isMatch());
        // Cooldown off → fires again.
        $a->update(['cooldown_hours' => 0]);
        $this->assertTrue($matcher->match($this->comment(id: 'c-new'))->isMatch());
    }

    public function test_events_for_another_account_never_match(): void
    {
        $other = $this->makeAccount(User::factory()->create(), 'ig-2');
        $this->automation([], $other);

        $this->assertFalse(app(AutomationMatcher::class)->match($this->comment())->isMatch());
    }

    public function test_same_ig_account_under_two_users_fires_exactly_one_automation(): void
    {
        $second = $this->makeAccount(User::factory()->create(), 'ig-1');
        $mine = $this->automation();
        $this->automation([], $second);

        $result = app(AutomationMatcher::class)->match($this->comment());

        $this->assertSame($mine->id, $result->automation->id, 'ties resolve to the lowest id');
    }

    public function test_inbound_job_creates_one_run_and_marks_the_ledger(): void
    {
        $a = $this->automation(['keyword_mode' => 'contains', 'keywords' => ['link']]);
        $event = AutomationEvent::create([
            'platform' => 'instagram', 'account_platform_id' => 'ig-1', 'event_type' => 'comment', 'event_id' => 'c-1',
            'sender_id' => 'u-1', 'payload' => $this->comment()->toArray(), 'status' => 'received', 'received_at' => now(),
        ]);

        (new ProcessInstagramInboundEventJob($event->id))->handle(app(AutomationMatcher::class));
        // A retry / redelivery of the same event is a no-op.
        (new ProcessInstagramInboundEventJob($event->id))->handle(app(AutomationMatcher::class));

        $run = AutomationRun::sole();
        $this->assertSame($a->id, $run->automation_id);
        $this->assertSame($this->user->id, $run->user_id);
        $this->assertSame($event->id, $run->automation_event_id);
        $this->assertSame('c-1', $run->event_id);
        $this->assertSame('fan', $run->sender_username);
        $this->assertSame('link', $run->matched_keyword);
        $this->assertSame(AutomationRun::STATUS_PENDING, $run->status);
        $this->assertSame(AutomationEvent::STATUS_MATCHED, $event->fresh()->status);
        Queue::assertPushed(ExecuteAutomationRunJob::class, 1);
        Queue::assertPushed(ExecuteAutomationRunJob::class, fn ($j) => $j->runId === $run->id && $j->socialAccountId === $this->account->id);
    }

    public function test_inbound_job_marks_unmatched_events_ignored_with_a_reason(): void
    {
        $this->automation(['keyword_mode' => 'contains', 'keywords' => ['price']]);
        $event = AutomationEvent::create([
            'platform' => 'instagram', 'account_platform_id' => 'ig-1', 'event_type' => 'comment', 'event_id' => 'c-1',
            'sender_id' => 'u-1', 'payload' => $this->comment('hello')->toArray(), 'status' => 'received', 'received_at' => now(),
        ]);

        (new ProcessInstagramInboundEventJob($event->id))->handle(app(AutomationMatcher::class));

        $this->assertSame(AutomationEvent::STATUS_IGNORED, $event->fresh()->status);
        $this->assertSame(MatchResult::KEYWORD_MISMATCH, $event->fresh()->ignore_reason);
        $this->assertSame(0, AutomationRun::count());
        Queue::assertNothingPushed();
    }
}
