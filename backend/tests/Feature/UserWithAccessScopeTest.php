<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\UserAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * User::withAccess() is the SQL twin of UserAccess::denial(): commands that
 * select records in bulk (publish-due, syncs, metrics) must land on exactly
 * the users denial() would allow, so the two can never drift apart.
 */
class UserWithAccessScopeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: callable(): User, 1: bool}> */
    public static function users(): array
    {
        return [
            'inside free window' => [fn () => User::factory()->create(), true],
            'window closed' => [fn () => User::factory()->expired()->create(), false],
            'no window, no plan' => [fn () => User::factory()->withoutWindow()->create(), false],
            'unverified' => [fn () => User::factory()->unverified()->create(), false],
            'active plan, window closed' => [fn () => User::factory()->expired()->withPlan('active')->create(), true],
            'trialing plan, no window' => [fn () => User::factory()->withoutWindow()->withPlan('trialing')->create(), true],
            'cancelled plan' => [fn () => User::factory()->withoutWindow()->withPlan('cancelled')->create(), false],
            'past_due plan' => [fn () => User::factory()->withoutWindow()->withPlan('past_due')->create(), false],
            'expired plan' => [fn () => User::factory()->withoutWindow()->withPlan('expired')->create(), false],
            'admin, no window' => [fn () => User::factory()->admin()->withoutWindow()->create(), true],
            'promo, window closed' => [fn () => User::factory()->promotional()->expired()->create(), true],
            'not onboarded, window open' => [fn () => User::factory()->create(['onboarding_completed_at' => null]), false],
            'not onboarded, trialing plan' => [fn () => User::factory()->withPlan('trialing')->create(['onboarding_completed_at' => null]), true],
            'not onboarded, promo' => [fn () => User::factory()->promotional()->create(['onboarding_completed_at' => null]), true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('users')]
    public function test_scope_agrees_with_denial(callable $make, bool $allowed): void
    {
        $user = $make();

        $this->assertSame($allowed, UserAccess::denial($user->fresh()) === null, 'denial()');
        $this->assertSame($allowed, User::withAccess()->whereKey($user->id)->exists(), 'withAccess()');
    }

    public function test_soft_deleted_users_are_never_selected(): void
    {
        $user = User::factory()->create();
        $user->delete();

        $this->assertFalse(User::withAccess()->whereKey($user->id)->exists());
    }

    public function test_scope_composes_through_relations(): void
    {
        $ok = User::factory()->create();
        $lapsed = User::factory()->expired()->create();
        $ok->socialAccounts()->create(['platform' => 'x', 'platform_account_id' => 'x-1', 'name' => 'Ok', 'username' => 'ok', 'access_token' => 't', 'status' => 'connected']);
        $lapsed->socialAccounts()->create(['platform' => 'x', 'platform_account_id' => 'x-2', 'name' => 'Lapsed', 'username' => 'lapsed', 'access_token' => 't', 'status' => 'connected']);

        $selected = \App\Models\SocialAccount::whereHas('user', fn ($u) => $u->withAccess())->pluck('username')->all();

        $this->assertSame(['ok'], $selected);
    }
}
