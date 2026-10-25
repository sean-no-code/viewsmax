<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Support\UserAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The one place that decides whether a user may use ViewsMax right now, shared
 * by the REST API (EnsureAccessActive), MCP (SafeCallTool) and the web consent
 * gate (EnsureWebAccess). Each caller only chooses how to deliver the answer.
 */
class UserAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.frontend_url' => 'https://app.example.com/']);
    }

    public function test_a_verified_user_inside_their_free_window_is_allowed(): void
    {
        $this->assertNull(UserAccess::denial(User::factory()->create(['promo_expires_at' => now()->addDay()])));
        $this->assertNull(UserAccess::denial(User::factory()->create(['promo_expires_at' => null])));
    }

    public function test_an_unverified_app_signup_is_told_to_verify_at_the_app(): void
    {
        $denial = UserAccess::denial(User::factory()->unverified()->create(['signup_source' => 'app']));

        $this->assertSame(UserAccess::EMAIL_UNVERIFIED, $denial->code);
        $this->assertSame('https://app.example.com/auth', $denial->url);
        $this->assertStringContainsString('Verify your email address', $denial->message);
        $this->assertStringContainsString($denial->url, $denial->message);
    }

    public function test_an_unverified_agent_signup_is_told_to_verify_on_this_host(): void
    {
        $denial = UserAccess::denial(User::factory()->unverified()->create(['signup_source' => 'agent']));

        $this->assertSame(UserAccess::EMAIL_UNVERIFIED, $denial->code);
        $this->assertSame(route('login'), $denial->url);
    }

    public function test_an_expired_trial_is_told_to_get_a_plan(): void
    {
        $denial = UserAccess::denial(User::factory()->create(['promo_expires_at' => now()->subDay()]));

        $this->assertSame(UserAccess::ACCESS_EXPIRED, $denial->code);
        $this->assertSame('https://app.example.com/dashboard/billing', $denial->url);
        $this->assertStringContainsString('free trial has ended', $denial->message);
        $this->assertStringContainsString($denial->url, $denial->message);
    }

    public function test_a_paid_plan_reopens_an_expired_window(): void
    {
        $plan = Plan::create([
            'name' => 'pro', 'display_name' => 'Pro', 'price' => 99.00, 'currency' => 'USD',
            'billing_cycle' => 'monthly', 'is_active' => true, 'stripe_price_id' => 'price_pro',
        ]);
        $user = User::factory()->create(['promo_expires_at' => now()->subDay()]);
        $user->plans()->attach($plan->id, ['status' => 'active', 'starts_at' => now()]);

        $this->assertNull(UserAccess::denial($user));
    }

    public function test_verifying_comes_before_paying(): void
    {
        $denial = UserAccess::denial(User::factory()->unverified()->create(['promo_expires_at' => now()->subDay()]));

        $this->assertSame(UserAccess::EMAIL_UNVERIFIED, $denial->code);
    }

    public function test_the_denial_serializes_as_an_api_error(): void
    {
        $denial = UserAccess::denial(User::factory()->create(['promo_expires_at' => now()->subDay()]));

        $this->assertSame(
            ['success' => false, 'code' => 'access_expired', 'message' => $denial->message, 'url' => $denial->url],
            $denial->toArray()
        );
    }

    /** REST delivery: EnsureAccessActive answers 403 with the denial, like it does for access_expired. */
    public function test_rest_api_refuses_an_unverified_users_token_with_the_verify_message(): void
    {
        $user = User::factory()->unverified()->create(['signup_source' => 'app']);
        $token = $user->createToken('mobile-app')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer '.$token])->getJson('/api/posts')
            ->assertForbidden()
            ->assertJsonPath('code', 'email_unverified')
            ->assertJsonPath('url', 'https://app.example.com/auth');

        // Session endpoints stay open so the app can show the resend prompt and sign out.
        $this->withHeaders(['Authorization' => 'Bearer '.$token])->getJson('/api/profile')->assertOk();
    }

    // --- The card step -------------------------------------------------------

    private function notOnboarded(array $attrs = []): User
    {
        return User::factory()->create($attrs + ['onboarding_completed_at' => null, 'promo_expires_at' => now()->addDays(6)]);
    }

    public function test_a_signup_that_has_not_added_a_card_is_told_to_add_one(): void
    {
        $denial = UserAccess::denial($this->notOnboarded(['signup_source' => 'app']));

        $this->assertSame(UserAccess::PAYMENT_REQUIRED, $denial->code);
        $this->assertSame('https://app.example.com/onboarding', $denial->url);
        $this->assertStringContainsString('Add a card', $denial->message);
        $this->assertStringContainsString($denial->url, $denial->message);

        // Agent signups add the card on this host, mid OAuth flow.
        $this->assertSame(route('register.billing'), UserAccess::denial($this->notOnboarded(['signup_source' => 'agent']))->url);
    }

    public function test_a_card_or_a_promotional_window_clears_the_card_step(): void
    {
        $plan = Plan::create(['name' => 'starter', 'display_name' => 'Starter', 'price' => 29, 'currency' => 'USD', 'is_active' => true, 'stripe_price_id' => 'price_starter']);
        $paid = $this->notOnboarded();
        $paid->plans()->attach($plan->id, ['status' => 'trialing', 'starts_at' => now(), 'stripe_subscription_id' => 'sub_1']);
        $this->assertNull(UserAccess::denial($paid->fresh()));

        $promo = $this->notOnboarded(['promo_expires_at' => null]);
        $promo->roles()->attach(Role::firstOrCreate(['name' => User::PROMO_ROLE], ['display_name' => 'Promotional customer'])->id);
        $this->assertNull(UserAccess::denial($promo->fresh()));
    }

    public function test_an_onboarded_user_without_a_card_keeps_access_until_the_window_closes(): void
    {
        // Signups from the card-free weeks finished onboarding with no card;
        // they are not locked out mid-window, the window's end handles them.
        $this->assertNull(UserAccess::denial(User::factory()->create(['onboarding_completed_at' => now(), 'promo_expires_at' => now()->addDay()])));
    }

    public function test_an_expired_window_outranks_the_card_step(): void
    {
        $this->assertSame(UserAccess::ACCESS_EXPIRED, UserAccess::denial($this->notOnboarded(['promo_expires_at' => now()->subDay()]))->code);
    }
}
