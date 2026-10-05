<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Social\CaptionRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * POST /api/social/posts uses the post text as the YouTube title, and YouTube
 * rejects an empty title — so a YouTube account can't be targeted without text.
 */
class SocialPostYouTubeTitleTest extends TestCase
{
    use RefreshDatabase;

    private function account(User $user, string $platform): SocialAccount
    {
        return SocialAccount::create([
            'user_id' => $user->id,
            'platform' => $platform,
            'platform_account_id' => "{$platform}-1",
            'name' => 'Account',
            'access_token' => 'token',
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
    }

    public function test_youtube_post_without_text_is_rejected(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $yt = $this->account($user, 'youtube');

        $this->withToken($user->createToken('t')->plainTextToken)
            ->postJson('/api/social/posts', [
                'media' => [['type' => 'video', 'url' => 'https://cdn.example/clip.mp4']],
                'account_ids' => [$yt->id],
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => CaptionRules::YOUTUBE_TITLE_REQUIRED]);

        $this->assertDatabaseCount('social_posts', 0);
    }

    public function test_other_platforms_still_accept_media_without_text(): void
    {
        Queue::fake();
        $user = User::factory()->create();
        $x = $this->account($user, 'x');

        $this->withToken($user->createToken('t')->plainTextToken)
            ->postJson('/api/social/posts', [
                'media' => [['type' => 'image', 'url' => 'https://cdn.example/pic.jpg']],
                'account_ids' => [$x->id],
            ])
            ->assertSuccessful();
    }
}
