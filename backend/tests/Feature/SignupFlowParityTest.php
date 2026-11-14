<?php

namespace Tests\Feature;

use App\Mail\VerifyEmailMail;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Services\StripeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The two signup flows side by side with SIGNUP_CARD_REQUIRED=true: the SPA
 * (POST /api/register → onboarding wizard → card) and the agent flow on the
 * API host (/register → verify → setup → /register/billing → consent). Same
 * starting point, same card, so each pair should end in the same state.
 */
class SignupFlowParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-14 10:00:00');
        Mail::fake();
        Http::fake();
        Role::firstOrCreate(['name' => 'customer'], ['display_name' => 'Customer']);
        config(['free_trial.card_required' => true]);
        Plan::create([
            'name' => 'starter', 'display_name' => 'Starter', 'price' => 29, 'currency' => 'USD',
            'is_active' => true, 'stripe_price_id' => 'price_starter',
        ]);
    }

    private function appSignup(): User
    {
        $this->postJson('/api/register', [
            'name' => 'App User', 'email' => 'app@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertCreated();

        $user = User::where('email', 'app@example.com')->sole();
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user;
    }

    private function agentSignup(): User
    {
        $this->post('/register', [
            'name' => 'Agent User', 'email' => 'agent@example.com',
            'password' => 'password123', 'password_confirmation' => 'password123',
        ])->assertRedirect(route('register.verify'));

        $mail = Mail::sent(VerifyEmailMail::class, fn ($m) => $m->hasTo('agent@example.com'))->last();
        $this->get($mail->verifyUrl)->assertRedirect(route('register.setup'));

        return User::where('email', 'agent@example.com')->sole();
    }

    private function headers(User $user): array
    {
        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertOk()->json('data.token');

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    private function stripeTrialing(int $times = 1): void
    {
        $this->mock(StripeService::class, function ($mock) use ($times) {
            $mock->shouldReceive('attachPaymentMethod')->times($times);
            $mock->shouldReceive('createSubscription')->times($times)->andReturnUsing(fn ($user) => [
                'id' => 'sub_'.$user->id, 'status' => 'trialing',
                'trial_end' => $user->promo_expires_at->getTimestamp(),
                'current_period_end' => $user->promo_expires_at->getTimestamp(),
            ]);
        });
    }

    /** Everything the SPA does after the card: subscribe, then finish the wizard. */
    private function appAddsCard(User $user, array $headers): void
    {
        $this->withHeaders($headers)->postJson('/api/billing/stripe/subscribe', [
            'payment_method_id' => 'pm_card', 'price_id' => 'price_starter',
        ])->assertOk();
        $this->withHeaders($headers)->postJson('/api/onboarding/complete')->assertOk();
    }

    /** Everything the agent flow does after the card: the billing POST, then continue to consent. */
    private function agentAddsCard(User $user): void
    {
        $this->actingAs($user, 'web')
            ->post(route('register.billing'), ['payment_method_id' => 'pm_card', 'price_id' => 'price_starter'])
            ->assertRedirect(route('register.continue'));
        $this->actingAs($user, 'web')->get(route('register.continue'))->assertRedirect();
    }

    public function test_no_card_neither_flow_gets_in(): void
    {
        $app = $this->appSignup();
        $agent = $this->agentSignup();

        // Agent flow: continue (and so the consent screen) is held at the card step.
        $this->actingAs($agent, 'web')->get(route('register.continue'))->assertRedirect(route('register.billing'));

        foreach ([$app, $agent] as $user) {
            $headers = $this->headers($user);
            $this->withHeaders($headers)->postJson('/api/onboarding/complete')->assertStatus(422);
            $this->withHeaders($headers)->getJson('/api/posts')
                ->assertForbidden()->assertJsonPath('code', 'payment_required');
            $this->assertNull($user->fresh()->onboarding_completed_at, $user->signup_source);
            $this->assertFalse(User::withAccess()->whereKey($user->id)->exists(), $user->signup_source);
        }
    }

    public function test_with_a_card_both_flows_end_onboarded(): void
    {
        $this->stripeTrialing(2);
        $app = $this->appSignup();
        $agent = $this->agentSignup();

        $this->appAddsCard($app, $this->headers($app));
        $this->agentAddsCard($agent);

        $this->assertNotNull($app->fresh()->onboarding_completed_at, 'app signup is stamped');
        $this->assertNotNull($agent->fresh()->onboarding_completed_at, 'agent signup is stamped');
    }

    public function test_with_the_switch_off_both_flows_end_onboarded_without_a_card(): void
    {
        config(['free_trial.card_required' => false]);
        $app = $this->appSignup();
        $agent = $this->agentSignup();

        $this->withHeaders($this->headers($app))->postJson('/api/onboarding/complete')->assertOk();
        $this->actingAs($agent, 'web')->get(route('register.continue'))->assertRedirect();

        $this->assertNotNull($app->fresh()->onboarding_completed_at, 'app signup is stamped');
        $this->assertNotNull($agent->fresh()->onboarding_completed_at, 'agent signup is stamped');
    }

    public function test_after_the_card_the_spa_lets_both_into_the_dashboard(): void
    {
        $this->stripeTrialing(2);
        $app = $this->appSignup();
        $agent = $this->agentSignup();
        $this->appAddsCard($app, $this->headers($app));
        $this->agentAddsCard($agent);

        // OnboardingGate / Auth.tsx send anyone with a null onboarding_completed_at back to /onboarding.
        foreach ([$app, $agent] as $user) {
            $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password123'])
                ->assertOk()
                ->assertJsonPath('data.user.has_active_plan', true)
                ->assertJson(fn ($json) => $json->whereType('data.user.onboarding_completed_at', 'string')->etc());
        }
    }

    public function test_cancelling_the_trial_inside_the_window_ends_in_the_same_place(): void
    {
        $this->stripeTrialing(2);
        $app = $this->appSignup();
        $agent = $this->agentSignup();
        $this->appAddsCard($app, $this->headers($app));
        $this->agentAddsCard($agent);

        // The subscription is cancelled on day 2 (customer portal / webhook); the window runs to day 7.
        Carbon::setTestNow('2026-11-16 10:00:00');
        foreach ([$app, $agent] as $user) {
            $user->plans()->updateExistingPivot($user->plans()->first()->id, ['status' => 'canceled']);
        }

        $codes = collect([$app, $agent])->mapWithKeys(fn (User $u) => [
            $u->signup_source => $this->withHeaders($this->headers($u))->getJson('/api/posts')->json('code') ?? 'ok',
        ])->all();

        $this->assertSame($codes['app'], $codes['agent'], 'outcome per flow: '.json_encode($codes));
    }
}
