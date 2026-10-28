<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Registering opens a 7-day free window (the same `promo_expires_at` window
 * promotional customers use, without the role). The window is the trial, but
 * onboarding still asks for a card: subscribing during the window starts a
 * trialing subscription that charges the activation fee today and the plan
 * price when the window closes. Once the window closes without a card the
 * user is locked to the billing endpoints, and subscribing then charges the
 * plan price straight away.
 */
class CardFreeSignupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-02 10:00:00');
        Mail::fake();
        Role::firstOrCreate(['name' => 'customer'], ['display_name' => 'Customer']);
    }

    private function register(): User
    {
        $this->postJson('/api/register', [
            'name' => 'New User',
            'email' => 'new@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertCreated();

        $user = User::where('email', 'new@example.com')->firstOrFail();
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function authHeaders(User $user): array
    {
        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->json('data.token');

        return ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
    }

    private function plan(): Plan
    {
        return Plan::create([
            'name' => 'starter', 'display_name' => 'Starter', 'price' => 29, 'currency' => 'USD',
            'is_active' => true, 'stripe_price_id' => 'price_starter',
        ]);
    }

    /** Mock Stripe and capture the trial terms the subscription is created with. */
    private function expectSubscription(?array &$trial, string $status): void
    {
        $this->mock(StripeService::class, function ($mock) use (&$trial, $status) {
            $mock->shouldReceive('attachPaymentMethod')->once();
            $mock->shouldReceive('createSubscription')->once()
                ->andReturnUsing(function ($user, $priceId, $terms) use (&$trial, $status) {
                    $trial = $terms;

                    return ['id' => 'sub_new', 'status' => $status, 'current_period_end' => now()->addMonth()->getTimestamp()];
                });
        });
    }

    public function test_registering_opens_a_seven_day_window_with_no_card(): void
    {
        $user = $this->register();

        $this->assertSame('2026-10-09 10:00:00', $user->promo_expires_at->toDateTimeString());
        $this->assertSame(['customer'], $user->roles()->pluck('name')->all());

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.user.has_active_subscription', true)
            ->assertJsonPath('data.user.has_active_plan', false)
            ->assertJsonPath('data.user.access_expired', false);
    }

    public function test_onboarding_needs_a_card_even_during_the_window(): void
    {
        $user = $this->register();
        $headers = $this->authHeaders($user);

        $this->withHeaders($headers)->postJson('/api/onboarding/complete')
            ->assertStatus(422)
            ->assertJsonPath('message', 'Add payment to finish setting up your account.');
        $this->assertNull($user->fresh()->onboarding_completed_at);

        $this->plan();
        $this->expectSubscription($trial, 'trialing');
        $this->withHeaders($headers)->postJson('/api/billing/stripe/subscribe', [
            'payment_method_id' => 'pm_card', 'price_id' => 'price_starter',
        ])->assertOk();

        $this->withHeaders($headers)->postJson('/api/onboarding/complete')
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertNotNull($user->fresh()->onboarding_completed_at);
        $this->withHeaders($headers)->getJson('/api/posts')->assertOk();
    }

    public function test_without_a_card_only_the_onboarding_endpoints_answer_and_the_window_end_sends_them_to_billing(): void
    {
        $user = $this->register();
        $headers = $this->authHeaders($user);

        // Inside the window but no card yet: what onboarding needs works,
        // everything else says to add a card.
        Carbon::setTestNow('2026-10-09 09:59:00');
        $this->withHeaders($headers)->getJson('/api/connections')->assertOk();
        $this->withHeaders($headers)->getJson('/api/social/platforms')->assertOk();
        $this->withHeaders($headers)->getJson('/api/social/accounts')->assertOk();
        $this->withHeaders($headers)->getJson('/api/plans')->assertOk();
        $this->withHeaders($headers)->getJson('/api/posts')
            ->assertForbidden()
            ->assertJsonPath('code', 'payment_required');
        $this->withHeaders($headers)->getJson('/api/social/posts')->assertForbidden();

        Carbon::setTestNow('2026-10-09 10:01:00');
        $this->withHeaders($headers)->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.user.has_active_subscription', false)
            ->assertJsonPath('data.user.access_expired', true);
        $this->withHeaders($headers)->getJson('/api/posts')
            ->assertForbidden()
            ->assertJsonPath('code', 'access_expired');
        $this->withHeaders($headers)->getJson('/api/plans')->assertOk();
    }

    // ---- SIGNUP_CARD_REQUIRED=false: the card-free trial ----------------------
    // The window is a trial without a card: onboarding finishes without one and
    // the whole product works until the window closes, then the account is sent
    // to Billing exactly as before. Flipping the switch back restores the card
    // step for anyone who hasn't finished onboarding.

    public function test_with_the_switch_off_a_signup_uses_the_product_without_a_card(): void
    {
        config(['free_trial.card_required' => false]);
        $user = $this->register();
        $headers = $this->authHeaders($user);

        $this->withHeaders($headers)->getJson('/api/posts')->assertOk();
        $this->withHeaders($headers)->getJson('/api/social/posts')->assertOk();
        $this->withHeaders($headers)->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.user.card_required', false);
    }

    public function test_with_the_switch_off_onboarding_finishes_without_a_card(): void
    {
        config(['free_trial.card_required' => false]);
        $user = $this->register();

        $this->withHeaders($this->authHeaders($user))->postJson('/api/onboarding/complete')
            ->assertOk()
            ->assertJsonPath('success', true);
        $this->assertNotNull($user->fresh()->onboarding_completed_at);
    }

    public function test_with_the_switch_off_the_window_still_ends_in_billing(): void
    {
        config(['free_trial.card_required' => false]);
        $user = $this->register();
        $headers = $this->authHeaders($user);
        $this->withHeaders($headers)->postJson('/api/onboarding/complete')->assertOk();

        Carbon::setTestNow('2026-10-09 10:01:00');

        $this->withHeaders($headers)->getJson('/api/posts')
            ->assertForbidden()
            ->assertJsonPath('code', 'access_expired');
    }

    public function test_background_work_follows_the_switch(): void
    {
        $user = $this->register(); // verified, window open, not onboarded, no card

        config(['free_trial.card_required' => true]);
        $this->assertFalse(User::withAccess()->whereKey($user->id)->exists(), 'paywalled: no syncs or publishing');

        config(['free_trial.card_required' => false]);
        $this->assertTrue(User::withAccess()->whereKey($user->id)->exists(), 'card-free trial: same as the access rule');

        Carbon::setTestNow('2026-10-09 10:01:00');
        $this->assertFalse(User::withAccess()->whereKey($user->id)->exists(), 'window closed');
    }

    public function test_the_agent_card_page_is_skipped_with_the_switch_off(): void
    {
        config(['free_trial.card_required' => false]);
        $user = $this->register();

        $this->actingAs($user, 'web')->get('/register/billing')->assertRedirect(route('register.continue'));
    }

    /**
     * The end of a card-free trial, end to end: the account is onboarded and
     * has no plan, the window has just closed. Every surface tells the user to
     * upgrade and where — REST as a 403, MCP as the tool answer (HTTP 200).
     */
    private function expiredCardFreeUser(): User
    {
        // The Billing link is built from FRONTEND_URL; pin it so a dev .env can't change the message.
        config(['free_trial.card_required' => false, 'app.frontend_url' => 'https://app.example.com']);
        $user = $this->register();
        $this->withHeaders($this->authHeaders($user))->postJson('/api/onboarding/complete')->assertOk();
        Carbon::setTestNow('2026-10-09 11:00:00'); // the window closed at 10:00

        return $user;
    }

    public function test_after_a_card_free_trial_the_api_says_to_upgrade(): void
    {
        $user = $this->expiredCardFreeUser();
        $headers = $this->authHeaders($user);

        foreach (['/api/posts', '/api/outliers', '/api/social/accounts'] as $path) {
            $this->withHeaders($headers)->getJson($path)
                ->assertForbidden()
                ->assertJsonPath('success', false)
                ->assertJsonPath('code', 'access_expired')
                ->assertJsonPath('url', 'https://app.example.com/dashboard/billing')
                ->assertJsonPath('message', 'Your ViewsMax free trial has ended. Please upgrade to a paid subscription to continue using ViewsMax: https://app.example.com/dashboard/billing');
        }
        // What the app needs to show the user and take a card stays open.
        $this->withHeaders($headers)->getJson('/api/profile')->assertOk()->assertJsonPath('data.user.access_expired', true);
        $this->withHeaders($headers)->getJson('/api/plans')->assertOk();
    }

    public function test_after_a_card_free_trial_mcp_tools_say_to_upgrade(): void
    {
        $user = $this->expiredCardFreeUser();
        // An MCP key minted while the account still had access.
        $key = 'vmx_'.$user->generateTokenString();
        $user->tokens()->create(['name' => 'mcp', 'token' => hash('sha256', $key), 'abilities' => ['mcp']]);

        foreach (['list_posts' => [], 'search_outliers' => ['term' => 'ai agents'], 'list_connected_accounts' => []] as $tool => $arguments) {
            $response = $this->withHeaders(['Authorization' => 'Bearer '.$key, 'Accept' => 'application/json'])
                ->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments]]);

            $response->assertOk(); // never an HTTP error over MCP
            $this->assertSame(
                'Your ViewsMax free trial has ended. Please upgrade to a paid subscription to continue using ViewsMax: https://app.example.com/dashboard/billing',
                $response->json('result.content.0.text'),
                $tool
            );
        }
    }

    public function test_the_switch_defaults_to_requiring_a_card(): void
    {
        $user = $this->register();

        $this->withHeaders($this->authHeaders($user))->getJson('/api/profile')
            ->assertJsonPath('data.user.card_required', true);
    }

    public function test_subscribing_after_the_window_charges_immediately(): void
    {
        $user = $this->register();
        $headers = $this->authHeaders($user);
        $this->plan();
        Carbon::setTestNow('2026-10-12 10:00:00');
        $this->expectSubscription($trial, 'active');

        $this->withHeaders($headers)->postJson('/api/billing/stripe/subscribe', [
            'payment_method_id' => 'pm_card', 'price_id' => 'price_starter',
        ])->assertOk()->assertJsonPath('data.status', 'active');

        $this->assertSame([], $trial);
        $this->withHeaders($headers)->getJson('/api/profile')
            ->assertJsonPath('data.user.has_active_plan', true)
            ->assertJsonPath('data.user.access_expired', false);
    }

    public function test_subscribing_during_the_window_defers_the_charge_to_its_end(): void
    {
        $user = $this->register();
        $headers = $this->authHeaders($user);
        $this->plan();
        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->expectSubscription($trial, 'trialing');

        $this->withHeaders($headers)->postJson('/api/billing/stripe/subscribe', [
            'payment_method_id' => 'pm_card', 'price_id' => 'price_starter',
        ])->assertOk()->assertJsonPath('data.status', 'trialing');

        $this->assertSame(['trial_end' => Carbon::parse('2026-10-09 10:00:00')->getTimestamp()], $trial);
    }

    public function test_a_user_with_no_window_is_charged_today(): void
    {
        // Accounts created before the card-free window (still at the card step):
        // no Stripe trial period, the card is charged on day one.
        $user = User::factory()->create(['promo_expires_at' => null]);
        $headers = $this->authHeaders($user);
        $this->plan();
        $this->expectSubscription($trial, 'active');

        $this->withHeaders($headers)->postJson('/api/billing/stripe/subscribe', [
            'payment_method_id' => 'pm_card', 'price_id' => 'price_starter',
        ])->assertOk()->assertJsonPath('data.status', 'active');

        $this->assertSame([], $trial);
    }
}
