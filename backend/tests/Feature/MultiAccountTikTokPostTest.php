<?php

namespace Tests\Feature;

use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * TikTok is now multi-account (moved off the single `connections` store). A post
 * can fan out to two TikTok accounts, each becoming its own per-account target —
 * the exact scenario that previously overwrote the one connection.
 */
class MultiAccountTikTokPostTest extends TestCase
{
    use RefreshDatabase;

    private function connectTikTok(User $user, string $accountId): SocialAccount
    {
        return $user->socialAccounts()->create([
            'platform' => 'tiktok',
            'platform_account_id' => $accountId,
            'name' => "TikTok {$accountId}",
            'access_token' => "token-{$accountId}",
            'token_expires_at' => now()->addDay(),
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
    }

    public function test_post_fans_out_to_two_tiktok_accounts_as_two_targets(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;
        $a = $this->connectTikTok($user, 'tt-1');
        $b = $this->connectTikTok($user, 'tt-2');

        $response = $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->postJson('/api/posts', [
                'caption' => 'to both TikToks',
                'status' => 'draft',
                'media' => [['type' => 'video', 'url' => 'https://cdn.example/clip.mp4', 'path' => 'posts/1/clip.mp4']],
                'targets' => [
                    ['platform' => 'tiktok', 'social_account_id' => $a->id],
                    ['platform' => 'tiktok', 'social_account_id' => $b->id],
                ],
            ]);

        $response->assertStatus(201);
        $targets = PostTarget::where('post_id', $response->json('id'))->get();
        $this->assertCount(2, $targets);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $targets->pluck('social_account_id')->all());
        $this->assertSame(['tiktok', 'tiktok'], $targets->pluck('platform')->all());
    }
}
