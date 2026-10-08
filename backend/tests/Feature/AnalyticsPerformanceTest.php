<?php

namespace Tests\Feature;

use App\Models\AudienceSnapshot;
use App\Models\PostMetricSnapshot;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GET /api/analytics/performance — the Profile/Post performance pages. Returns
 * every connected account (with its stats status) plus per-day follower counts
 * and per-day engagement deltas over the previous+current period, and the posts
 * published in the range with their latest metrics.
 */
class AnalyticsPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private array $auth;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-08 12:00:00');
        $this->user = User::factory()->create(['public_id' => (string) Str::uuid()]);
        $token = $this->user->createToken('test')->plainTextToken;
        $this->auth = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function account(string $platform, array $extra = [], ?User $user = null): SocialAccount
    {
        return SocialAccount::create(array_merge([
            'user_id' => ($user ?? $this->user)->id,
            'platform' => $platform,
            'platform_account_id' => $platform.'-'.Str::random(4),
            'name' => ucfirst($platform).' Acct',
            'username' => '@'.$platform,
            'access_token' => 'tok',
            'status' => SocialAccount::STATUS_CONNECTED,
        ], $extra));
    }

    private function followers(SocialAccount $a, string $date, int $count): void
    {
        AudienceSnapshot::create(['social_account_id' => $a->id, 'snapshot_date' => $date, 'follower_count' => $count]);
    }

    private function postSnapshot(SocialAccount $a, string $remoteId, string $date, array $m, ?string $publishedAt = null): void
    {
        PostMetricSnapshot::create([
            'user_id' => $a->user_id,
            'social_account_id' => $a->id,
            'platform' => $a->platform,
            'remote_post_id' => $remoteId,
            'url' => "https://example.test/{$remoteId}",
            'caption_excerpt' => "Post {$remoteId}",
            'published_at' => $publishedAt,
            'likes' => $m['likes'] ?? 0,
            'comments' => $m['comments'] ?? 0,
            'shares' => $m['shares'] ?? 0,
            'views' => $m['views'] ?? 0,
            'engagement_total' => array_sum($m),
            'snapshot_date' => $date,
        ]);
    }

    private function fetch(array $query = []): array
    {
        return $this->withHeaders($this->auth)
            ->getJson('/api/analytics/performance?'.http_build_query($query))
            ->assertOk()
            ->json('data');
    }

    public function test_days_cover_the_previous_and_current_period(): void
    {
        $data = $this->fetch(['from' => '2026-10-02', 'to' => '2026-10-08']);

        $this->assertSame('2026-10-02', $data['from']);
        $this->assertSame('2026-10-08', $data['to']);
        $this->assertCount(14, $data['days']);
        $this->assertSame('2026-09-25', $data['days'][0]);
        $this->assertSame('2026-10-08', $data['days'][13]);
        $this->assertSame([], $data['accounts']);
        $this->assertSame([], $data['posts']);
    }

    public function test_defaults_to_the_last_28_days(): void
    {
        $data = $this->fetch();

        $this->assertSame('2026-09-11', $data['from']);
        $this->assertSame('2026-10-08', $data['to']);
        $this->assertCount(56, $data['days']);
    }

    public function test_follower_series_is_forward_filled_and_null_before_the_first_snapshot(): void
    {
        $x = $this->account('x');
        $this->followers($x, '2026-10-04', 100);
        $this->followers($x, '2026-10-06', 120); // no row on the 5th or the 7th/8th

        $data = $this->fetch(['from' => '2026-10-02', 'to' => '2026-10-08']);
        $acct = $data['accounts'][0];

        $this->assertSame($x->id, $acct['id']);
        $this->assertSame('x', $acct['platform']);
        $this->assertSame('@x', $acct['username']);
        $this->assertTrue($acct['supported']);
        $this->assertSame('connected', $acct['status']);
        // 14 days: 25 Sep … 8 Oct. First snapshot on index 9 (4 Oct).
        $this->assertSame(array_fill(0, 9, null) + [9 => 100, 10 => 100, 11 => 120, 12 => 120, 13 => 120], $acct['followers']);
    }

    public function test_follower_series_uses_the_last_snapshot_before_the_window_as_a_starting_point(): void
    {
        $x = $this->account('x');
        $this->followers($x, '2026-09-01', 90);

        $data = $this->fetch(['from' => '2026-10-02', 'to' => '2026-10-08']);

        $this->assertSame(array_fill(0, 14, 90), $data['accounts'][0]['followers']);
    }

    public function test_engagement_is_the_day_over_day_delta_of_each_post(): void
    {
        $yt = $this->account('youtube');
        // Old post (published long before the window): the first snapshot is a
        // baseline, only later growth counts.
        $this->postSnapshot($yt, 'old', '2026-10-03', ['likes' => 100, 'comments' => 10, 'shares' => 0, 'views' => 1000], '2026-01-01');
        $this->postSnapshot($yt, 'old', '2026-10-04', ['likes' => 105, 'comments' => 12, 'shares' => 1, 'views' => 1050], '2026-01-01');
        $this->postSnapshot($yt, 'old', '2026-10-05', ['likes' => 104, 'comments' => 12, 'shares' => 1, 'views' => 1100], '2026-01-01'); // likes dropped → 0, not negative
        // New post: the first snapshot (the day after publishing) counts in full.
        $this->postSnapshot($yt, 'new', '2026-10-07', ['likes' => 20, 'comments' => 2, 'shares' => 3, 'views' => 300], '2026-10-06 09:00:00');

        $data = $this->fetch(['from' => '2026-10-02', 'to' => '2026-10-08']);
        $e = $data['accounts'][0]['engagement'];

        $expectedLikes = array_fill(0, 14, 0);
        $expectedLikes[9] = 5;   // 4 Oct
        $expectedLikes[12] = 20; // 7 Oct
        $this->assertSame($expectedLikes, $e['likes']);
        $this->assertSame(2, $e['comments'][9]);
        $this->assertSame(0, $e['comments'][10]);
        $this->assertSame(2, $e['comments'][12]);
        $this->assertSame(1, $e['shares'][9]);
        $this->assertSame(3, $e['shares'][12]);
        $this->assertSame(50, $e['views'][9]);
        $this->assertSame(50, $e['views'][10]);
        $this->assertSame(300, $e['views'][12]);
    }

    public function test_posts_published_in_range_carry_their_latest_metrics(): void
    {
        $yt = $this->account('youtube');
        $this->postSnapshot($yt, 'in', '2026-10-05', ['likes' => 1, 'comments' => 0, 'shares' => 0, 'views' => 10], '2026-10-04 10:00:00');
        $this->postSnapshot($yt, 'in', '2026-10-07', ['likes' => 9, 'comments' => 1, 'shares' => 2, 'views' => 90], '2026-10-04 10:00:00');
        $this->postSnapshot($yt, 'before', '2026-10-07', ['likes' => 50, 'comments' => 5, 'shares' => 0, 'views' => 500], '2026-09-01 10:00:00');
        $this->postSnapshot($yt, 'nodate', '2026-10-06', ['likes' => 3, 'comments' => 0, 'shares' => 0, 'views' => 30], null);

        $data = $this->fetch(['from' => '2026-10-02', 'to' => '2026-10-08']);
        $posts = collect($data['posts'])->keyBy('remote_post_id');

        $this->assertSame(['in', 'nodate'], $posts->keys()->sort()->values()->all());
        $this->assertSame(9, $posts['in']['likes']);
        $this->assertSame(1, $posts['in']['comments']);
        $this->assertSame(2, $posts['in']['shares']);
        $this->assertSame(90, $posts['in']['views']);
        $this->assertSame($yt->id, $posts['in']['account_id']);
        $this->assertSame('youtube', $posts['in']['platform']);
        $this->assertSame('https://example.test/in', $posts['in']['url']);
        $this->assertSame('Post in', $posts['in']['caption']);
        $this->assertSame('2026-10-04T10:00:00+00:00', $posts['in']['published_at']);
        // No published_at → the first snapshot date stands in.
        $this->assertSame('2026-10-06T00:00:00+00:00', $posts['nodate']['published_at']);
    }

    public function test_accounts_report_support_status_and_stats_errors(): void
    {
        $this->account('linkedin');
        $this->account('x', ['status' => SocialAccount::STATUS_NEEDS_REAUTH, 'last_error' => 'Token revoked']);
        $this->account('youtube', ['follower_stats_error' => 'HTTP 403', 'post_stats_error' => null]);

        $data = $this->fetch(['from' => '2026-10-02', 'to' => '2026-10-08']);
        $byPlatform = collect($data['accounts'])->keyBy('platform');

        $this->assertFalse($byPlatform['linkedin']['supported']);
        $this->assertSame('needs_reauth', $byPlatform['x']['status']);
        $this->assertSame('Token revoked', $byPlatform['x']['last_error']);
        $this->assertSame('HTTP 403', $byPlatform['youtube']['follower_stats_error']);
        $this->assertNull($byPlatform['youtube']['post_stats_error']);
        $this->assertSame(array_fill(0, 14, null), $byPlatform['youtube']['followers']);
    }

    public function test_is_scoped_to_the_authenticated_user(): void
    {
        $other = User::factory()->create();
        $theirs = $this->account('x', [], $other);
        $this->followers($theirs, '2026-10-08', 999);
        $this->postSnapshot($theirs, 'theirs', '2026-10-08', ['likes' => 1, 'comments' => 0, 'shares' => 0, 'views' => 0], '2026-10-07');

        $data = $this->fetch();

        $this->assertSame([], $data['accounts']);
        $this->assertSame([], $data['posts']);
    }

    public function test_rejects_bad_dates_and_too_long_ranges(): void
    {
        $this->withHeaders($this->auth)->getJson('/api/analytics/performance?from=nope')->assertStatus(422);
        $this->withHeaders($this->auth)->getJson('/api/analytics/performance?from=2024-01-01&to=2026-10-08')->assertStatus(422);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/analytics/performance')->assertStatus(401);
    }
}
