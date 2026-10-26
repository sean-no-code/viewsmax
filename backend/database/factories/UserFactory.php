<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            // Finished onboarding (card added or grandfathered): the usual
            // user. Tests of the card step set this null explicitly.
            'onboarding_completed_at' => now(),
            // Inside the card-free window a real signup gets (Registration):
            // the usual user has access. Lapsed users use expired().
            'promo_expires_at' => now()->addDays(User::CARD_FREE_DAYS),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /** Free window closed, no plan: locked to billing, skipped by background work. */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => ['promo_expires_at' => now()->subDay()]);
    }

    /** No window at all (pre-paywall signups, admin-created accounts): expired unless they hold a plan or a role. */
    public function withoutWindow(): static
    {
        return $this->state(fn (array $attributes) => ['promo_expires_at' => null]);
    }

    public function promotional(): static
    {
        return $this->afterCreating(fn (User $user) => $user->roles()->attach(
            Role::firstOrCreate(['name' => User::PROMO_ROLE], ['display_name' => 'Promotional customer'])->id,
        ));
    }

    public function admin(): static
    {
        return $this->afterCreating(fn (User $user) => $user->roles()->attach(
            Role::firstOrCreate(['name' => User::ADMIN_ROLE], ['display_name' => 'Admin'])->id,
        ));
    }

    /** A live subscription (status active by default; pass 'trialing', 'cancelled', … to vary). */
    public function withPlan(string $status = 'active', ?Plan $plan = null): static
    {
        return $this->afterCreating(function (User $user) use ($status, $plan) {
            $plan ??= Plan::firstOrCreate(
                ['name' => 'starter'],
                ['display_name' => 'Starter', 'price' => 29, 'currency' => 'USD', 'is_active' => true, 'stripe_price_id' => 'price_starter'],
            );
            $user->plans()->attach($plan->id, ['status' => $status, 'starts_at' => now(), 'stripe_subscription_id' => 'sub_'.Str::random(8)]);
        });
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
