<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Social\Providers\InstagramProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * The Instagram Graph calls behind automations: webhook subscription, the
 * media list for the post picker, public comment replies, DMs and private
 * replies — including the 190 → needs_reauth path.
 */
class InstagramMessagingProviderTest extends TestCase
{
    use RefreshDatabase;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        config(['social.platforms.instagram.client_id' => 'id', 'social.platforms.instagram.client_secret' => 'secret']);
        $this->account = SocialAccount::create([
            'user_id' => User::factory()->create()->id,
            'platform' => 'instagram',
            'platform_account_id' => 'ig-1',
            'access_token' => 'tok',
            'token_expires_at' => now()->addDays(30),
            'status' => SocialAccount::STATUS_CONNECTED,
            'scopes' => ['instagram_business_basic'],
        ]);
    }

    public function test_subscribe_webhooks_records_subscription_on_success(): void
    {
        Http::fake(['graph.instagram.com/*/ig-1/subscribed_apps' => Http::response(['success' => true])]);

        $ok = (new InstagramProvider)->subscribeWebhooks($this->account, ['comments', 'messages']);

        $this->assertTrue($ok);
        $fresh = $this->account->fresh();
        $this->assertNotNull($fresh->webhook_subscribed_at);
        $this->assertSame(['comments', 'messages'], $fresh->meta('webhook_fields'));
        Http::assertSent(fn ($r) => str_contains($r->url(), '/ig-1/subscribed_apps') && $r['subscribed_fields'] === 'comments,messages');
    }

    public function test_subscribe_webhooks_failure_leaves_account_unsubscribed(): void
    {
        Http::fake(['graph.instagram.com/*/subscribed_apps' => Http::response(['error' => ['message' => 'nope', 'code' => 100]], 400)]);

        $this->assertFalse((new InstagramProvider)->subscribeWebhooks($this->account));
        $this->assertNull($this->account->fresh()->webhook_subscribed_at);
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $this->account->fresh()->status);
    }

    public function test_list_media_normalizes_items_and_returns_cursor(): void
    {
        Http::fake(['graph.instagram.com/*/me/media*' => Http::response([
            'data' => [
                ['id' => '1', 'caption' => 'Reel one', 'media_type' => 'VIDEO', 'media_product_type' => 'REELS', 'thumbnail_url' => 'https://cdn/1.jpg', 'permalink' => 'https://ig/p/1', 'timestamp' => '2026-09-01T00:00:00+0000'],
                ['id' => '2', 'media_type' => 'IMAGE', 'media_url' => 'https://cdn/2.jpg', 'permalink' => 'https://ig/p/2'],
            ],
            'paging' => ['cursors' => ['after' => 'CURSOR2']],
        ])]);

        $page = (new InstagramProvider)->listMedia($this->account, null, 24);

        $this->assertSame('CURSOR2', $page['next_cursor']);
        $this->assertCount(2, $page['items']);
        $this->assertSame('https://cdn/1.jpg', $page['items'][0]['thumbnail_url']);
        $this->assertSame('REELS', $page['items'][0]['media_product_type']);
        // Images fall back to media_url for the thumbnail.
        $this->assertSame('https://cdn/2.jpg', $page['items'][1]['thumbnail_url']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/me/media') && str_contains($r->url(), 'limit=24'));
    }

    public function test_list_media_with_expired_token_flags_reauth_and_throws(): void
    {
        Http::fake(['graph.instagram.com/*/me/media*' => Http::response(['error' => ['message' => 'Session expired', 'type' => 'OAuthException', 'code' => 190]], 400)]);

        try {
            (new InstagramProvider)->listMedia($this->account);
            $this->fail('expected exception');
        } catch (RuntimeException) {
        }

        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $this->account->fresh()->status);
    }

    public function test_reply_to_comment_posts_to_replies_edge(): void
    {
        Http::fake(['graph.instagram.com/*/c-1/replies' => Http::response(['id' => 'r-9'])]);

        $result = (new InstagramProvider)->replyToComment($this->account, 'c-1', 'Sent you a DM!');

        $this->assertTrue($result->success);
        $this->assertSame('r-9', $result->remoteCommentId);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/c-1/replies') && $r['message'] === 'Sent you a DM!');
    }

    public function test_send_message_and_private_reply_use_the_messages_edge(): void
    {
        Http::fake(['graph.instagram.com/*/ig-1/messages' => Http::response(['recipient_id' => 'u-1', 'message_id' => 'm-1'])]);
        $provider = new InstagramProvider;

        $dm = $provider->sendMessage($this->account, 'igsid-1', ['text' => 'hello']);
        $this->assertTrue($dm->success);
        $this->assertSame('m-1', $dm->messageId);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/ig-1/messages')
            && $r['recipient'] === ['id' => 'igsid-1'] && $r['message'] === ['text' => 'hello']
            && $r->hasHeader('Authorization', 'Bearer tok'));

        $reply = $provider->sendPrivateReply($this->account, 'c-1', ['text' => 'hi']);
        $this->assertTrue($reply->success);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/ig-1/messages') && $r['recipient'] === ['comment_id' => 'c-1']);
    }

    public function test_message_failures_are_classified(): void
    {
        Http::fakeSequence('graph.instagram.com/*/ig-1/messages')
            ->push(['error' => ['message' => 'This message is sent outside of allowed window.', 'type' => 'OAuthException', 'code' => 10, 'error_subcode' => 2534022]], 400)
            ->push(['error' => ['message' => 'Application request limit reached', 'code' => 4]], 400)
            ->push(['error' => ['message' => 'Session expired', 'type' => 'OAuthException', 'code' => 190]], 400);
        $provider = new InstagramProvider;

        $window = $provider->sendMessage($this->account, 'u', ['text' => 'x']);
        $this->assertFalse($window->success);
        $this->assertTrue($window->isOutsideWindow());
        $this->assertFalse($window->isRateLimited());

        $limited = $provider->sendMessage($this->account, 'u', ['text' => 'x']);
        $this->assertTrue($limited->isRateLimited());

        $expired = $provider->sendMessage($this->account, 'u', ['text' => 'x']);
        $this->assertTrue($expired->isAuthError());
        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $this->account->fresh()->status);
    }
}
