<?php

namespace Tests\Feature;

use App\Contracts\Quota\QuotaGate;
use App\Support\Quota\Keys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The gate's arithmetic: the day's shared counter against each tier's .env
 * threshold, rolling over at midnight Pacific like Google's quota.
 */
class YouTubeQuotaCounterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The documented defaults, pinned: a dev box's YOUTUBE_QUOTA_* / BREAKDOWNS_* must not change the arithmetic.
        config([
            'services.youtube.quota.enabled' => true,
            'services.youtube.quota.thresholds' => [
                'search_list' => ['free' => 48, 'paid' => 90, 'admin' => null],
                'videos_insert' => ['free' => 10, 'paid' => 90, 'admin' => null],
            ],
            'services.youtube.quota.per_user' => [
                'search_list' => ['free' => 2, 'paid' => 20, 'admin' => null],
                'videos_insert' => ['free' => 1, 'paid' => 10, 'admin' => null],
                'breakdowns' => ['free' => 2, 'paid' => 20, 'admin' => null],
            ],
        ]);
    }

    private function gate(): QuotaGate
    {
        return app(QuotaGate::class);
    }

    public function test_allows_while_the_call_would_stay_inside_the_tiers_threshold(): void
    {
        Cache::put(Keys::counter('search_list'), 46);

        $decision = $this->gate()->allows('search_list', 'free', 2);

        $this->assertTrue($decision->allowed);
        $this->assertSame(46, $decision->used);
        $this->assertSame(48, $decision->cap);
    }

    public function test_denies_when_the_call_would_cross_the_tiers_threshold(): void
    {
        Cache::put(Keys::counter('search_list'), 47);

        $this->assertFalse($this->gate()->allows('search_list', 'free', 2)->allowed);
        // Paid users have headroom above the free line.
        $this->assertTrue($this->gate()->allows('search_list', 'paid', 2)->allowed);
    }

    public function test_admins_are_never_limited(): void
    {
        Cache::put(Keys::counter('search_list'), 10_000);

        $decision = $this->gate()->allows('search_list', 'admin', 4);

        $this->assertTrue($decision->allowed);
        $this->assertNull($decision->cap);
    }

    public function test_a_resource_without_thresholds_is_only_counted(): void
    {
        Cache::put(Keys::counter('videos_list'), 10_000);

        $this->assertTrue($this->gate()->allows('videos_list', 'free', 500)->allowed);
    }

    public function test_thresholds_come_from_env(): void
    {
        config(['services.youtube.quota.thresholds.search_list.free' => 10]);
        Cache::put(Keys::counter('search_list'), 10);

        $decision = $this->gate()->allows('search_list', 'free', 1);

        $this->assertFalse($decision->allowed);
        $this->assertSame(10, $decision->cap);
    }

    public function test_a_blank_env_value_means_unlimited_and_zero_means_refused(): void
    {
        config(['services.youtube.quota.thresholds.search_list.free' => '']);
        Cache::put(Keys::counter('search_list'), 10_000);
        $this->assertTrue($this->gate()->allows('search_list', 'free', 1)->allowed);

        config(['services.youtube.quota.thresholds.videos_insert.free' => 0]);
        $this->assertFalse($this->gate()->allows('videos_insert', 'free', 1)->allowed, 'YouTube publishing paid-only');
    }

    public function test_the_gate_can_be_switched_off_in_env(): void
    {
        config(['services.youtube.quota.enabled' => false]);
        Cache::put(Keys::counter('search_list'), 10_000);

        $decision = $this->gate()->allows('search_list', 'free', 4);

        $this->assertTrue($decision->allowed);
        $this->assertSame('disabled', $decision->reason);
    }

    public function test_a_users_own_daily_allowance_is_checked_after_the_shared_cap(): void
    {
        config(['services.youtube.quota.per_user.search_list.free' => 2]);
        $gate = $this->gate();

        $this->assertTrue($gate->allows('search_list', 'free', 2, userId: 7)->allowed);
        $gate->recordUserFetch(7, 'search_list');
        $this->assertTrue($gate->allows('search_list', 'free', 2, userId: 7)->allowed);
        $gate->recordUserFetch(7, 'search_list');

        $third = $gate->allows('search_list', 'free', 2, userId: 7);
        $this->assertFalse($third->allowed);
        $this->assertSame('user', $third->scope);
        $this->assertSame('user_limit', $third->reason);
        $this->assertSame(2, $third->used);
        $this->assertSame(2, $third->cap);

        // Another user is unaffected; no user means the shared cap only.
        $this->assertTrue($gate->allows('search_list', 'free', 2, userId: 8)->allowed);
        $this->assertTrue($gate->allows('search_list', 'free', 2)->allowed);
    }

    public function test_paid_users_have_a_bigger_allowance_and_admins_none(): void
    {
        $gate = $this->gate();
        foreach (range(1, 20) as $i) {
            $gate->recordUserFetch(9, 'search_list');
        }

        $this->assertFalse($gate->allows('search_list', 'paid', 2, userId: 9)->allowed, '20 is the paid default');
        $this->assertTrue($gate->allows('search_list', 'admin', 2, userId: 9)->allowed);
    }

    public function test_uploads_and_breakdowns_have_their_own_per_user_allowances(): void
    {
        $gate = $this->gate();

        $this->assertTrue($gate->allows('videos_insert', 'free', 1, userId: 5)->allowed);
        $gate->recordUserFetch(5, 'videos_insert');
        $upload = $gate->allows('videos_insert', 'free', 1, userId: 5);
        $this->assertFalse($upload->allowed, '1 upload/day is the free default');
        $this->assertSame('user', $upload->scope);

        // Searches and breakdowns are counted separately.
        $this->assertTrue($gate->allows('search_list', 'free', 2, userId: 5)->allowed);
        $gate->recordUserFetch(5, 'breakdowns');
        $gate->recordUserFetch(5, 'breakdowns');
        $this->assertFalse($gate->allows('breakdowns', 'free', 1, userId: 5)->allowed, '2 breakdowns/day is the free default');
        $this->assertTrue($gate->allows('breakdowns', 'paid', 1, userId: 5)->allowed);
    }

    public function test_breakdowns_have_no_shared_cap(): void
    {
        Cache::put(Keys::counter('breakdowns'), 1_000_000);

        $this->assertTrue($this->gate()->allows('breakdowns', 'free', 1)->allowed);
    }

    public function test_the_shared_cap_wins_over_the_users_allowance(): void
    {
        Cache::put(Keys::counter('search_list'), 48);

        $decision = $this->gate()->allows('search_list', 'free', 2, userId: 7);

        $this->assertFalse($decision->allowed);
        $this->assertSame('project', $decision->scope);
    }

    public function test_the_counter_rolls_over_at_midnight_pacific_like_googles_quota(): void
    {
        Carbon::setTestNow('2026-10-29 06:30:00'); // 23:30 on the 28th in Los Angeles
        $this->assertSame('2026-10-28', Keys::date());
        $this->assertSame('2026-10-29T07:00:00+00:00', Keys::resetsAt()->format(DATE_ATOM));

        Carbon::setTestNow('2026-10-29 07:30:00'); // 00:30 on the 29th
        $this->assertSame('2026-10-29', Keys::date());
        $this->assertSame('2026-10-30T07:00:00+00:00', Keys::resetsAt()->format(DATE_ATOM));
    }

    public function test_fails_open_when_the_counter_cannot_be_read(): void
    {
        // A cache outage must never block users: allow, and say so in the log.
        Cache::shouldReceive('get')->andThrow(new \RuntimeException('redis down'));

        $decision = $this->gate()->allows('search_list', 'free', 2);

        $this->assertTrue($decision->allowed);
        $this->assertSame('fail_open', $decision->reason);
    }
}
