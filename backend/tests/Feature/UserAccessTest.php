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
    }

    public function test_a_user_with_no_window_and_no_plan_is_expired(): void
    {
        // Pre-paywall signups and admin-created accounts have promo_expires_at
        // null; without a live plan or an exempt role they must subscribe.
        $this->assertSame(UserAccess::ACCESS_EXPIRED, UserAccess::denial(User::factory()->withoutWindow()->create())->code);
    }

    public function test_only_active_or_trialing_plans_grant_access(): void
    {
        foreach (['active', 'trialing'] as $status) {
            $this->assertNull(UserAccess::denial(User::factory()->withoutWindow()->withPlan($status)->create()), $status);
        }
        foreach (['cancelled', 'past_due', 'expired', 'inactive'] as $status) {
            $this->assertSame(UserAccess::SUBSCRIPTION_INACTIVE, UserAccess::denial(User::factory()->withoutWindow()->withPlan($status)->create())->code, $status);
        }
    }

    public function test_an_ended_subscription_is_told_to_reactivate(): void
    {
        // Held a plan once: it's a subscription that ended, not a trial.
        $denial = UserAccess::denial(User::factory()->expired()->withPlan('expired')->create());

        $this->assertSame(UserAccess::SUBSCRIPTION_INACTIVE, $denial->code);
        $this->assertSame('https://app.example.com/dashboard/billing', $denial->url);
        $this->assertSame(
            'Your ViewsMax subscription has ended. Reactivate it to continue using ViewsMax: https://app.example.com/dashboard/billing',
            $denial->message,
        );
    }

    public function test_an_ended_subscription_inside_an_open_window_keeps_access(): void
    {
        $this->assertNull(UserAccess::denial(User::factory()->withPlan('cancelled')->create()));
    }

    public function test_payment_denials_answer_402_and_an_unverified_email_403(): void
    {
        $unsubscribed = User::factory()->expired()->create();
        $lapsed = User::factory()->expired()->withPlan('cancelled')->create();
        $noCard = User::factory()->create(['onboarding_completed_at' => null, 'promo_expires_at' => now()->addDays(6)]);
        $unverified = User::factory()->unverified()->create();

        $this->assertSame([UserAccess::ACCESS_EXPIRED, 402], [UserAccess::denial($unsubscribed)->code, UserAccess::denial($unsubscribed)->status()]);
        $this->assertSame([UserAccess::SUBSCRIPTION_INACTIVE, 402], [UserAccess::denial($lapsed)->code, UserAccess::denial($lapsed)->status()]);
        $this->assertSame([UserAccess::PAYMENT_REQUIRED, 402], [UserAccess::denial($noCard)->code, UserAccess::denial($noCard)->status()]);
        $this->assertSame([UserAccess::EMAIL_UNVERIFIED, 403], [UserAccess::denial($unverified)->code, UserAccess::denial($unverified)->status()]);
    }

    public function test_admins_and_promotional_customers_are_exempt(): void
    {
        $this->assertNull(UserAccess::denial(User::factory()->admin()->withoutWindow()->create()));
        $this->assertNull(UserAccess::denial(User::factory()->admin()->expired()->create()));
        $this->assertNull(UserAccess::denial(User::factory()->promotional()->withoutWindow()->create()));
        $this->assertNull(UserAccess::denial(User::factory()->promotional()->expired()->create()));
        // …but still have to verify their email first.
        $this->assertSame(UserAccess::EMAIL_UNVERIFIED, UserAccess::denial(User::factory()->admin()->unverified()->create())->code);
    }

    public function test_allows_background_work_follows_denial(): void
    {
        $this->assertTrue(UserAccess::allowsBackgroundWork(User::factory()->create()));
        $this->assertFalse(UserAccess::allowsBackgroundWork(User::factory()->expired()->create()));
        $this->assertFalse(UserAccess::allowsBackgroundWork(User::factory()->unverified()->create()));
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
            [
                'success' => false, 'code' => 'access_expired', 'message' => $denial->message, 'url' => $denial->url,
                'error' => ['code' => 'access_expired', 'message' => $denial->message, 'upgrade_url' => $denial->url],
            ],
            $denial->toArray()
        );
    }

    public function test_an_unverified_denial_has_no_payment_error_object(): void
    {
        $denial = UserAccess::denial(User::factory()->unverified()->create());

        $this->assertSame(
            ['success' => false, 'code' => 'email_unverified', 'message' => $denial->message, 'url' => $denial->url],
            $denial->toArray()
        );
    }

    /** REST delivery: EnsureAccessActive answers 402 with the denial once a subscription has ended. */
    public function test_rest_api_answers_402_once_a_subscription_has_ended(): void
    {
        $user = User::factory()->expired()->withPlan('expired')->create();
        $token = $user->createToken('mobile-app')->plainTextToken;

        $this->withHeaders(['Authorization' => 'Bearer '.$token])->getJson('/api/posts')
            ->assertStatus(402)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'subscription_inactive')
            ->assertJsonPath('error.code', 'subscription_inactive')
            ->assertJsonPath('error.message', 'Your ViewsMax subscription has ended. Reactivate it to continue using ViewsMax: https://app.example.com/dashboard/billing')
            ->assertJsonPath('error.upgrade_url', 'https://app.example.com/dashboard/billing');

        // Billing stays open so they can reactivate.
        $this->withHeaders(['Authorization' => 'Bearer '.$token])->getJson('/api/profile')->assertOk();
        $this->withHeaders(['Authorization' => 'Bearer '.$token])->getJson('/api/plans')->assertOk();
    }

    /** An unverified email is not a payment matter: still 403. */
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
