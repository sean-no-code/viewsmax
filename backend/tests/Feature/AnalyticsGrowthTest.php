<?php

namespace Tests\Feature;

use App\Models\AudienceSnapshot;
use App\Models\PostMetricSnapshot;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Audience Growth endpoints: per-platform follower series (with day-over-day
 * delta) and the engagement-ranked posts list, both scoped to the auth user.
 */
class AnalyticsGrowthTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['public_id' => (string) Str::uuid()]);
        $token = $this->user->createToken('test')->plainTextToken;
        $this->auth = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    private function account(string $platform): SocialAccount
    {
        return SocialAccount::create([
            'user_id' => $this->user->id,
            'platform' => $platform,
            'platform_account_id' => $platform.'-1',
            'name' => ucfirst($platform).' Acct',
            'access_token' => 'tok',
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
    }

    public function test_audience_returns_per_platform_series_with_delta(): void
    {
        $x = $this->account('x');
        $yt = $this->account('youtube');

        AudienceSnapshot::create(['social_account_id' => $x->id, 'snapshot_date' => now()->subDay()->toDateString(), 'follower_count' => 1000]);
        AudienceSnapshot::create(['social_account_id' => $x->id, 'snapshot_date' => now()->toDateString(), 'follower_count' => 1100]);
        AudienceSnapshot::create(['social_account_id' => $yt->id, 'snapshot_date' => now()->toDateString(), 'follower_count' => 5000]);

        $data = $this->withHeaders($this->auth)->getJson('/api/analytics/audience')->assertOk()->json('data');

        $byPlatform = collect($data['platforms'])->keyBy('platform');
        $this->assertSame(1100, $byPlatform['x']['current']);
        $this->assertSame(100, $byPlatform['x']['delta']);
        $this->assertCount(2, $byPlatform['x']['points']);
        $this->assertTrue($byPlatform['x']['supported']);
        $this->assertSame(5000, $byPlatform['youtube']['current']);
        $this->assertSame(0, $byPlatform['youtube']['delta']);
    }

    public function test_audience_marks_unsupported_platforms(): void
    {
        $this->account('linkedin');

        $data = $this->withHeaders($this->auth)->getJson('/api/analytics/audience')->assertOk()->json('data');

        $li = collect($data['platforms'])->firstWhere('platform', 'linkedin');
        $this->assertNotNull($li);
        $this->assertFalse($li['supported']);
        $this->assertSame([], $li['points']);
    }

    public function test_audience_is_scoped_to_the_user(): void
    {
        $other = User::factory()->create();
        $otherAccount = SocialAccount::create([
            'user_id' => $other->id, 'platform' => 'x', 'platform_account_id' => 'x-other',
            'name' => 'Other', 'access_token' => 't', 'status' => SocialAccount::STATUS_CONNECTED,
        ]);
        AudienceSnapshot::create(['social_account_id' => $otherAccount->id, 'snapshot_date' => now()->toDateString(), 'follower_count' => 9999]);

        $data = $this->withHeaders($this->auth)->getJson('/api/analytics/audience')->assertOk()->json('data');

        $this->assertSame([], $data['platforms']);
    }

    public function test_posts_ranked_by_engagement_with_delta(): void
    {
        $x = $this->account('x');

        // Post A: 10 → 30 (delta 20). Post B: 50 today only (delta 0).
        foreach ([['A', now()->subDay()->toDateString(), 10], ['A', now()->toDateString(), 30], ['B', now()->toDateString(), 50]] as [$id, $date, $total]) {
            PostMetricSnapshot::create([
                'user_id' => $this->user->id,
                'social_account_id' => $x->id,
                'platform' => 'x',
                'remote_post_id' => $id,
                'url' => 'https://x/'.$id,
                'caption_excerpt' => 'Post '.$id,
                'likes' => $total,
                'comments' => 0,
                'shares' => 0,
                'views' => 0,
                'engagement_total' => $total,
                'snapshot_date' => $date,
            ]);
        }

        $data = $this->withHeaders($this->auth)->getJson('/api/analytics/posts')->assertOk()->json('data');

        $this->assertSame('B', $data['posts'][0]['remote_post_id']);
        $this->assertSame(50, $data['posts'][0]['engagement_total']);
        $this->assertSame(0, $data['posts'][0]['engagement_delta']);
        $this->assertSame('A', $data['posts'][1]['remote_post_id']);
        $this->assertSame(30, $data['posts'][1]['engagement_total']);
        $this->assertSame(20, $data['posts'][1]['engagement_delta']);
    }

    public function test_posts_can_filter_by_platform(): void
    {
        $x = $this->account('x');
        $yt = $this->account('youtube');
        PostMetricSnapshot::create(['user_id' => $this->user->id, 'social_account_id' => $x->id, 'platform' => 'x', 'remote_post_id' => 'A', 'engagement_total' => 10, 'snapshot_date' => now()->toDateString()]);
        PostMetricSnapshot::create(['user_id' => $this->user->id, 'social_account_id' => $yt->id, 'platform' => 'youtube', 'remote_post_id' => 'V', 'engagement_total' => 20, 'snapshot_date' => now()->toDateString()]);

        $data = $this->withHeaders($this->auth)->getJson('/api/analytics/posts?platform=youtube')->assertOk()->json('data');

        $this->assertCount(1, $data['posts']);
        $this->assertSame('youtube', $data['posts'][0]['platform']);
    }

    public function test_endpoints_require_auth(): void
    {
        $this->getJson('/api/analytics/audience')->assertStatus(401);
        $this->getJson('/api/analytics/posts')->assertStatus(401);
    }
}
