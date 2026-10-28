<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AnalyticsAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * User::withAnalytics() is the SQL twin of AnalyticsAccess::allows(): the
 * commands that select in bulk (channels:sync-videos, audience:refresh,
 * posts:refresh-metrics, posts:import-recent) must land on exactly the users
 * the predicate would allow, so the two can never drift apart.
 */
class UserWithAnalyticsScopeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: ?string, 1: string, 2: bool}> */
    public static function plans(): array
    {
        return [
            'no plan at all' => [null, 'active', false],
            'free' => ['free', 'active', false],
            'starter' => ['starter', 'active', false],
            'creator' => ['creator', 'active', false],
            'pro' => ['pro', 'active', true],
            'agency' => ['agency', 'active', true],
            'pro, trialing' => ['pro', 'trialing', true],
            'pro, cancelled' => ['pro', 'cancelled', false],
            'pro, past_due' => ['pro', 'past_due', false],
            'legacy creator_elite' => ['creator_elite', 'active', true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('plans')]
    public function test_scope_agrees_with_the_predicate(?string $planName, string $status, bool $allowed): void
    {
        $factory = User::factory();
        if ($planName !== null) {
            $factory = $factory->withPlan($status, $this->planNamed($planName));
        }
        $user = $factory->create();

        $this->assertSame($allowed, AnalyticsAccess::allows($user->fresh()), 'allows()');
        $this->assertSame($allowed, User::withAnalytics()->whereKey($user->id)->exists(), 'withAnalytics()');
    }

    public function test_admins_and_promotional_customers_need_no_plan(): void
    {
        foreach ([User::factory()->admin(), User::factory()->promotional()] as $factory) {
            $user = $factory->withoutWindow()->create();

            $this->assertTrue(AnalyticsAccess::allows($user->fresh()), 'allows()');
            $this->assertTrue(User::withAnalytics()->whereKey($user->id)->exists(), 'withAnalytics()');
        }
    }

    public function test_a_plan_without_analytics_is_excluded_even_with_access(): void
    {
        // The two gates are orthogonal: a creator-tier user in good standing
        // passes withAccess() and must still fail withAnalytics().
        $creator = User::factory()->withPlan('active', $this->planNamed('creator'))->create();

        $this->assertTrue(User::withAccess()->whereKey($creator->id)->exists());
        $this->assertFalse(User::withAnalytics()->whereKey($creator->id)->exists());
    }

    public function test_soft_deleted_users_are_never_selected(): void
    {
        $user = User::factory()->withPlan('active', $this->planNamed('pro'))->create();
        $user->delete();

        $this->assertFalse(User::withAnalytics()->whereKey($user->id)->exists());
    }
}
