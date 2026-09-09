<?php

namespace Tests\Feature;

use App\Jobs\ProcessInstagramInboundEventJob;
use App\Models\AutomationEvent;
use App\Services\Automations\InstagramWebhookSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Meta webhook intake: verify handshake, raw-body signature check, one
 * ledger row per event (redeliveries are no-ops), self/echo filtering and
 * story-reply classification. Processing is queued, never inline.
 */
class InstagramWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'app-secret';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'social.platforms.instagram.client_secret' => self::SECRET,
            'social.platforms.instagram.webhook_verify_token' => 'verify-me',
        ]);
        Queue::fake();
    }

    private function hook(string $raw, ?string $signature = null): TestResponse
    {
        $signature ??= InstagramWebhookSignature::sign($raw, self::SECRET);

        return $this->call('POST', '/api/webhooks/instagram', [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $raw);
    }

    private function commentPayload(string $commentId = 'c-1', string $from = 'u-1', string $text = 'link please', ?string $parentId = null): string
    {
        return json_encode(['object' => 'instagram', 'entry' => [[
            'id' => 'ig-1', 'time' => 1700000000,
            'changes' => [['field' => 'comments', 'value' => array_filter([
                'id' => $commentId, 'text' => $text, 'media' => ['id' => 'media-1', 'media_product_type' => 'REELS'],
                'from' => ['id' => $from, 'username' => 'fan'], 'parent_id' => $parentId,
            ])]],
        ]]]);
    }

    private function messagePayload(array $message, string $sender = 'igsid-1'): string
    {
        return json_encode(['object' => 'instagram', 'entry' => [[
            'id' => 'ig-1', 'time' => 1700000000,
            'messaging' => [['sender' => ['id' => $sender], 'recipient' => ['id' => 'ig-1'], 'timestamp' => 1700000000123, 'message' => $message]],
        ]]]);
    }

    public function test_verify_echoes_the_challenge_with_the_right_token(): void
    {
        $this->get('/api/webhooks/instagram?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=12345')
            ->assertOk()
            ->assertSee('12345', false)
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function test_verify_rejects_a_wrong_token(): void
    {
        $this->get('/api/webhooks/instagram?hub.mode=subscribe&hub.verify_token=nope&hub.challenge=12345')
            ->assertStatus(403);
    }

    public function test_receive_rejects_an_invalid_signature(): void
    {
        $this->hook($this->commentPayload(), 'sha256=deadbeef')->assertStatus(401);

        $this->assertSame(0, AutomationEvent::count());
        Queue::assertNothingPushed();
    }

    public function test_receive_records_the_event_once_and_queues_processing(): void
    {
        $this->hook($this->commentPayload())->assertOk()->assertJsonPath('queued', 1);

        $event = AutomationEvent::sole();
        $this->assertSame('instagram', $event->platform);
        $this->assertSame('ig-1', $event->account_platform_id);
        $this->assertSame('comment', $event->event_type);
        $this->assertSame('c-1', $event->event_id);
        $this->assertSame('u-1', $event->sender_id);
        $this->assertSame(AutomationEvent::STATUS_RECEIVED, $event->status);
        $this->assertSame('link please', $event->payload['text']);
        $this->assertSame('media-1', $event->payload['media_id']);
        Queue::assertPushed(ProcessInstagramInboundEventJob::class, fn ($job) => $job->automationEventId === $event->id);
    }

    public function test_redelivered_event_is_not_recorded_or_queued_twice(): void
    {
        $this->hook($this->commentPayload())->assertOk();
        $this->hook($this->commentPayload())->assertOk()->assertJsonPath('queued', 0);

        $this->assertSame(1, AutomationEvent::count());
        Queue::assertPushed(ProcessInstagramInboundEventJob::class, 1);
    }

    public function test_own_comments_echoes_and_non_message_events_are_ignored(): void
    {
        // Our own public reply arrives as a comment from the account itself.
        $this->hook($this->commentPayload('c-own', 'ig-1', 'Sent you a DM', 'c-1'))->assertOk();
        // Echo of our outbound DM.
        $this->hook($this->messagePayload(['mid' => 'm-echo', 'text' => 'hi', 'is_echo' => true]))->assertOk();
        // Message from ourselves.
        $this->hook($this->messagePayload(['mid' => 'm-self', 'text' => 'hi'], 'ig-1'))->assertOk();
        // A reaction (no `message` key).
        $this->hook(json_encode(['object' => 'instagram', 'entry' => [['id' => 'ig-1', 'messaging' => [['sender' => ['id' => 'x'], 'recipient' => ['id' => 'ig-1'], 'reaction' => ['action' => 'react']]]]]]))->assertOk();
        // Not an instagram object.
        $this->hook(json_encode(['object' => 'page', 'entry' => []]))->assertOk();

        $this->assertSame(0, AutomationEvent::count());
        Queue::assertNothingPushed();
    }

    public function test_story_reply_is_classified_from_reply_to_story(): void
    {
        $this->hook($this->messagePayload(['mid' => 'm-1', 'text' => 'info', 'reply_to' => ['story' => ['id' => 'story-9', 'url' => 'https://cdn/story.mp4']]]))->assertOk();
        $this->hook($this->messagePayload(['mid' => 'm-2', 'text' => 'hello there']))->assertOk();

        $story = AutomationEvent::where('event_id', 'm-1')->sole();
        $this->assertSame('story_reply', $story->event_type);
        $this->assertSame('story-9', $story->payload['media_id']);
        $this->assertSame(1700000000, $story->payload['occurred_at']);

        $dm = AutomationEvent::where('event_id', 'm-2')->sole();
        $this->assertSame('dm', $dm->event_type);
        $this->assertSame('igsid-1', $dm->sender_id);
    }

    public function test_signature_check_can_be_disabled_for_local_development(): void
    {
        config(['social.platforms.instagram.webhook_signature_check' => false]);

        $this->hook($this->commentPayload(), 'sha256=garbage')->assertOk();
        $this->assertSame(1, AutomationEvent::count());
    }
}
