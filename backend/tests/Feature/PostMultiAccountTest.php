<?php

namespace Tests\Feature;

use App\Jobs\PublishToXJob;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Social\SocialProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Multi-account targets: a post can fan out to several accounts on the SAME
 * platform. Targets carry an explicit social_account_id; legacy payloads
 * (platforms[]) still work and publish via the newest connected account.
 */
class PostMultiAccountTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test-token')->plainTextToken;
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'];
    }

    private function connectX(User $user, string $accountId, string $token): SocialAccount
    {
        return $user->socialAccounts()->create([
            'platform' => 'x',
            'platform_account_id' => $accountId,
            'name' => "Account {$accountId}",
            'username' => "user_{$accountId}",
            'access_token' => $token,
            'token_expires_at' => now()->addDay(),
            'scopes' => ['tweet.write'],
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
    }

    public function test_create_post_with_two_x_accounts_creates_two_targets(): void
    {
        Queue::fake();
        $a = $this->connectX($this->user, 'x-1', 'token-a');
        $b = $this->connectX($this->user, 'x-2', 'token-b');

        $response = $this->withHeaders($this->auth())->postJson('/api/posts', [
            'caption' => 'hello from both accounts',
            'status' => 'draft',
            'targets' => [
                ['platform' => 'x', 'social_account_id' => $a->id],
                ['platform' => 'x', 'social_account_id' => $b->id],
            ],
        ]);

        $response->assertStatus(201);
        $targets = PostTarget::where('post_id', $response->json('id'))->get();
        $this->assertCount(2, $targets);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $targets->pluck('social_account_id')->all());
        $this->assertSame(['x', 'x'], $targets->pluck('platform')->all());
    }

    public function test_publish_uses_each_targets_own_account(): void
    {
        $tokensSeen = [];
        Http::fake([
            'api.twitter.com/2/tweets' => function ($request) use (&$tokensSeen) {
                $tokensSeen[] = $request->header('Authorization')[0] ?? null;

                return Http::response(['data' => ['id' => 't'.count($tokensSeen)]]);
            },
        ]);

        $a = $this->connectX($this->user, 'x-1', 'token-a');
        $b = $this->connectX($this->user, 'x-2', 'token-b');

        $post = Post::create([
            'user_id' => $this->user->id,
            'caption' => 'multi',
            'media' => [],
            'status' => Post::STATUS_POSTED,
        ]);
        $t1 = $post->targets()->create(['platform' => 'x', 'status' => PostTarget::STATUS_PENDING, 'social_account_id' => $a->id]);
        $t2 = $post->targets()->create(['platform' => 'x', 'status' => PostTarget::STATUS_PENDING, 'social_account_id' => $b->id]);

        (new PublishToXJob($t1->id))->handle(app(SocialProviderManager::class));
        (new PublishToXJob($t2->id))->handle(app(SocialProviderManager::class));

        $this->assertSame(PostTarget::STATUS_PUBLISHED, $t1->fresh()->status, (string) $t1->fresh()->error);
        $this->assertSame(PostTarget::STATUS_PUBLISHED, $t2->fresh()->status, (string) $t2->fresh()->error);
        $this->assertSame(['Bearer token-a', 'Bearer token-b'], $tokensSeen);
    }

    public function test_target_with_disconnected_account_fails_clearly(): void
    {
        Http::fake();
        $a = $this->connectX($this->user, 'x-1', 'token-a');
        $post = Post::create([
            'user_id' => $this->user->id,
            'caption' => 'multi',
            'media' => [],
            'status' => Post::STATUS_POSTED,
        ]);
        $target = $post->targets()->create(['platform' => 'x', 'status' => PostTarget::STATUS_PENDING, 'social_account_id' => $a->id]);
        $a->delete(); // account disconnected before the job ran

        (new PublishToXJob($target->id))->handle(app(SocialProviderManager::class));

        $target->refresh();
        $this->assertSame(PostTarget::STATUS_FAILED, $target->status);
        $this->assertStringContainsStringIgnoringCase('disconnected', (string) $target->error);
        Http::assertNothingSent();
    }

    public function test_foreign_account_is_rejected(): void
    {
        $other = User::factory()->create();
        $foreign = $this->connectX($other, 'x-9', 'token-f');

        $this->withHeaders($this->auth())->postJson('/api/posts', [
            'caption' => 'nope',
            'status' => 'draft',
            'targets' => [
                ['platform' => 'x', 'social_account_id' => $foreign->id],
            ],
        ])->assertStatus(422);
    }

    public function test_platform_mismatched_account_is_rejected(): void
    {
        $a = $this->connectX($this->user, 'x-1', 'token-a');

        $this->withHeaders($this->auth())->postJson('/api/posts', [
            'caption' => 'nope',
            'status' => 'draft',
            'targets' => [
                ['platform' => 'linkedin', 'social_account_id' => $a->id],
            ],
        ])->assertStatus(422);
    }

    public function test_duplicate_account_targets_are_rejected(): void
    {
        $a = $this->connectX($this->user, 'x-1', 'token-a');

        $this->withHeaders($this->auth())->postJson('/api/posts', [
            'caption' => 'dupe',
            'status' => 'draft',
            'targets' => [
                ['platform' => 'x', 'social_account_id' => $a->id],
                ['platform' => 'x', 'social_account_id' => $a->id],
            ],
        ])->assertStatus(422);
    }

    public function test_legacy_platforms_payload_still_works(): void
    {
        Queue::fake();
        $this->connectX($this->user, 'x-1', 'token-a');

        $response = $this->withHeaders($this->auth())->postJson('/api/posts', [
            'caption' => 'legacy shape',
            'status' => 'draft',
            'platforms' => ['x'],
        ]);

        $response->assertStatus(201);
        $targets = PostTarget::where('post_id', $response->json('id'))->get();
        $this->assertCount(1, $targets);
        $this->assertNull($targets->first()->social_account_id);
    }

    public function test_legacy_target_publishes_via_newest_connected_account(): void
    {
        $tokensSeen = [];
        Http::fake([
            'api.twitter.com/2/tweets' => function ($request) use (&$tokensSeen) {
                $tokensSeen[] = $request->header('Authorization')[0] ?? null;

                return Http::response(['data' => ['id' => 't1']]);
            },
        ]);
        $old = $this->connectX($this->user, 'x-old', 'token-old');
        $old->forceFill(['created_at' => now()->subDay()])->save();
        $this->connectX($this->user, 'x-new', 'token-new');

        $post = Post::create([
            'user_id' => $this->user->id,
            'caption' => 'legacy',
            'media' => [],
            'status' => Post::STATUS_POSTED,
        ]);
        $target = $post->targets()->create(['platform' => 'x', 'status' => PostTarget::STATUS_PENDING]);

        (new PublishToXJob($target->id))->handle(app(SocialProviderManager::class));

        $this->assertSame(PostTarget::STATUS_PUBLISHED, $target->fresh()->status, (string) $target->fresh()->error);
        $this->assertSame(['Bearer token-new'], $tokensSeen);
    }
}
