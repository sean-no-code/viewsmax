<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `users:promo` is how the promotional_customer role gets granted — the role
 * that unlocks limited-release platforms (config/social → promo_only) and,
 * when promo_expires_at is null, an open-ended free window.
 */
class PromoRoleCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_grants_the_promotional_role(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com', 'promo_expires_at' => now()->subDay()]);

        $this->artisan('users:promo jane@example.com')
            ->expectsOutputToContain('jane@example.com')
            ->assertSuccessful();

        $this->assertTrue($user->fresh()->isPromotional());
    }

    public function test_it_warns_when_the_role_also_opens_an_endless_free_window(): void
    {
        // promo_expires_at = null + the role means hasActivePromo() is true
        // forever, i.e. free access — not just early platform access.
        User::factory()->create(['email' => 'jane@example.com', 'promo_expires_at' => null]);

        $this->artisan('users:promo jane@example.com')
            ->expectsOutputToContain('free access')
            ->assertSuccessful();
    }

    public function test_it_is_idempotent(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com']);

        $this->artisan('users:promo jane@example.com')->assertSuccessful();
        $this->artisan('users:promo jane@example.com')->assertSuccessful();

        $this->assertSame(1, $user->roles()->where('name', User::PROMO_ROLE)->count());
    }

    public function test_it_revokes_the_role(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com']);
        $user->roles()->attach(
            Role::firstOrCreate(['name' => User::PROMO_ROLE], ['display_name' => 'Promotional customer'])->id
        );

        $this->artisan('users:promo jane@example.com --revoke')->assertSuccessful();

        $this->assertFalse($user->fresh()->isPromotional());
    }

    public function test_it_fails_on_an_unknown_email(): void
    {
        $this->artisan('users:promo nobody@example.com')
            ->expectsOutputToContain('No user')
            ->assertFailed();
    }

    public function test_it_leaves_other_roles_alone_when_revoking(): void
    {
        $user = User::factory()->create(['email' => 'jane@example.com']);
        foreach ([User::PROMO_ROLE, 'customer'] as $name) {
            $user->roles()->attach(Role::firstOrCreate(['name' => $name], ['display_name' => $name])->id);
        }

        $this->artisan('users:promo jane@example.com --revoke')->assertSuccessful();

        $this->assertFalse($user->fresh()->isPromotional());
        $this->assertTrue($user->fresh()->hasRole('customer'));
    }
}
