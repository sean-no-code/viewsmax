<?php

namespace Tests\Feature;

use App\Mail\PasswordResetMail;
use App\Mail\VerifyEmailMail;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ViewsMaxApiTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(User $user): array
    {
        return [
            'Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken,
            'Accept' => 'application/json',
        ];
    }

    private function trialPlan(): Plan
    {
        return Plan::create([
            'name' => 'creator_pro',
            'display_name' => 'Creator Pro',
            'price' => 29.00,
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'is_active' => true,
        ]);
    }

    // --- 1. Registration + email verification -----------------------------

    public function test_register_does_not_log_in_and_sends_magic_link(): void
    {
        Mail::fake();

        $response = $this->postJson('/api/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'marketing_consent' => true,
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true, 'message' => 'verification_sent', 'email' => 'jane@example.com'])
            ->assertJsonMissingPath('data.token');

        $user = User::where('email', 'jane@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->onboarding_completed_at);

        Mail::assertSent(VerifyEmailMail::class);
    }

    public function test_verify_email_logs_in_and_is_idempotent(): void
    {
        Mail::fake();

        $this->postJson('/api/register', [
            'name' => 'Jane',
            'email' => 'jane@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(201);

        $token = null;
        Mail::assertSent(VerifyEmailMail::class, function (VerifyEmailMail $mail) use (&$token) {
            $token = $mail->token;

            return true;
        });
        $this->assertNotNull($token);

        $first = $this->postJson('/api/auth/verify-email', ['token' => $token]);
        $first->assertStatus(200)
            ->assertJsonStructure(['success', 'data' => ['token', 'token_type', 'user' => [
                'id', 'name', 'email', 'email_verified_at', 'onboarding_completed_at',
                'connections_count', 'has_active_subscription',
            ]], 'user_credits'])
            ->assertJsonPath('data.user.onboarding_completed_at', null);

        $this->assertNotNull(User::where('email', 'jane@example.com')->first()->email_verified_at);

        // StrictMode double-call: still 200 with a fresh login payload.
        $this->postJson('/api/auth/verify-email', ['token' => $token])->assertStatus(200);
    }

    public function test_verify_email_rejects_invalid_token(): void
    {
        $this->postJson('/api/auth/verify-email', ['token' => 'not-a-real-token'])
            ->assertStatus(422)
            ->assertJson(['message' => 'This verification link is invalid or has expired.']);
    }

    public function test_login_blocks_unverified_users(): void
    {
        $user = User::factory()->unverified()->create([
            'email' => 'bob@example.com',
            'password' => bcrypt('password123'),
        ]);

        $this->postJson('/api/login', ['email' => 'bob@example.com', 'password' => 'password123'])
            ->assertStatus(403)
            ->assertJson(['success' => false, 'message' => 'email_not_verified', 'email' => 'bob@example.com']);

        $user->forceFill(['email_verified_at' => now()])->save();

        $this->postJson('/api/login', ['email' => 'bob@example.com', 'password' => 'password123'])
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['token', 'user' => [
                'id', 'name', 'email', 'email_verified_at', 'onboarding_completed_at',
                'connections_count', 'has_active_subscription',
            ]]]);
    }

    public function test_resend_verification_always_succeeds(): void
    {
        Mail::fake();
        $this->postJson('/api/auth/resend-verification', ['email' => 'nobody@example.com'])
            ->assertStatus(200)->assertJson(['success' => true]);
        Mail::assertNothingSent();
    }

    public function test_register_validation_returns_field_errors(): void
    {
        // An existing account makes the duplicate-email rule fire.
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->postJson('/api/register', [
            'name' => '',                 // required
            'email' => 'taken@example.com', // unique:users
            'password' => 'short',         // min:8 + unconfirmed
        ]);

        // The frontend relies on per-field detail, not just the generic message.
        $response->assertStatus(422)
            ->assertJson(['success' => false, 'message' => 'Validation failed'])
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_resend_verification_sends_to_unverified_user(): void
    {
        Mail::fake();

        $user = User::factory()->unverified()->create(['email' => 'pending@example.com']);

        $this->postJson('/api/auth/resend-verification', ['email' => $user->email])
            ->assertStatus(200)->assertJson(['success' => true]);

        Mail::assertSent(VerifyEmailMail::class, fn (VerifyEmailMail $mail) => $mail->hasTo($user->email));
    }

    public function test_resend_verification_skips_verified_user(): void
    {
        Mail::fake();

        $user = User::factory()->create(['email' => 'verified@example.com']); // verified by default

        $this->postJson('/api/auth/resend-verification', ['email' => $user->email])
            ->assertStatus(200)->assertJson(['success' => true]);

        Mail::assertNotSent(VerifyEmailMail::class);
    }

    // --- 1b. Forgot password ----------------------------------------------

    public function test_forgot_password_sends_reset_email(): void
    {
        Mail::fake();

        $user = User::factory()->create(['email' => 'reset@example.com']);

        $this->postJson('/api/forgot-password', ['email' => $user->email])
            ->assertStatus(200)->assertJson(['success' => true]);

        Mail::assertSent(PasswordResetMail::class, fn (PasswordResetMail $mail) => $mail->hasTo($user->email));
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_forgot_password_unknown_email_sends_nothing(): void
    {
        Mail::fake();

        $this->postJson('/api/forgot-password', ['email' => 'ghost@example.com'])
            ->assertStatus(200)->assertJson(['success' => true]);

        Mail::assertNotSent(PasswordResetMail::class);
    }

    // --- 2. Onboarding ----------------------------------------------------

    public function test_onboarding_requires_connection_and_subscription(): void
    {
        $user = User::factory()->create();

        // Missing both prereqs.
        $this->postJson('/api/onboarding/complete', [], $this->authHeaders($user))
            ->assertStatus(422)
            ->assertJson(['message' => 'Add payment to finish setting up your account.']);

        // Add a connection only — still blocked.
        $user->connections()->create([
            'provider' => 'youtube', 'account_name' => 'Chan', 'account_id' => 'UC1',
            'access_token' => 'tok',
        ]);
        $this->postJson('/api/onboarding/complete', [], $this->authHeaders($user))
            ->assertStatus(422);

        // Add a trialing subscription — now allowed.
        $plan = $this->trialPlan();
        $user->plans()->attach($plan->id, [
            'status' => 'trialing', 'starts_at' => now(), 'expires_at' => now()->addDays(3),
        ]);

        $this->postJson('/api/onboarding/complete', [], $this->authHeaders($user))
            ->assertStatus(200)
            ->assertJsonPath('data.user.has_active_subscription', true);

        $this->assertNotNull($user->fresh()->onboarding_completed_at);
    }

    // --- 3. Connections ---------------------------------------------------

    public function test_connections_index_and_count_and_delete(): void
    {
        $user = User::factory()->create();
        $c1 = $user->connections()->create([
            'provider' => 'youtube', 'account_name' => 'Chan', 'account_id' => 'UC1', 'access_token' => 't',
        ]);
        $user->connections()->create([
            'provider' => 'tiktok', 'account_name' => 'TT', 'account_id' => 'open1', 'access_token' => 't',
        ]);

        $this->getJson('/api/connections', $this->authHeaders($user))
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['data' => [['id', 'provider', 'account_name', 'account_id', 'avatar_url', 'connected_at']]]);

        $this->assertEquals(2, $user->fresh()->apiPayload()['connections_count']);

        // Caller-scoped delete.
        $other = User::factory()->create();
        $this->deleteJson("/api/connections/{$c1->id}", [], $this->authHeaders($other))->assertStatus(404);
        $this->deleteJson("/api/connections/{$c1->id}", [], $this->authHeaders($user))
            ->assertStatus(200)->assertJson(['success' => true]);
        $this->assertEquals(1, $user->fresh()->connections()->count());
    }

    // --- 5. Feature requests ---------------------------------------------

    public function test_feature_requests_create_list_and_toggle_upvote(): void
    {
        $user = User::factory()->create();

        $create = $this->postJson('/api/feature-requests', [
            'title' => 'Dark mode', 'description' => 'Please add it', 'category' => 'Feature',
        ], $this->authHeaders($user));

        $create->assertStatus(201)
            ->assertJsonPath('data.upvotes_count', 1)
            ->assertJsonPath('data.has_upvoted', true)
            ->assertJsonPath('data.status', null);

        $id = $create->json('data.id');

        // A second user sees has_upvoted=false.
        $other = User::factory()->create();
        $this->getJson('/api/feature-requests?sort=top', $this->authHeaders($other))
            ->assertStatus(200)
            ->assertJsonPath('data.0.upvotes_count', 1)
            ->assertJsonPath('data.0.has_upvoted', false);

        // Other user upvotes → count 2.
        $this->postJson("/api/feature-requests/{$id}/upvote", [], $this->authHeaders($other))
            ->assertStatus(200)
            ->assertJsonPath('data.upvotes_count', 2)
            ->assertJsonPath('data.has_upvoted', true);

        // Toggle off → back to 1.
        $this->postJson("/api/feature-requests/{$id}/upvote", [], $this->authHeaders($other))
            ->assertStatus(200)
            ->assertJsonPath('data.upvotes_count', 1)
            ->assertJsonPath('data.has_upvoted', false);
    }

    public function test_profile_returns_new_fields(): void
    {
        $user = User::factory()->create(['name' => 'Sam']);

        $this->getJson('/api/profile', $this->authHeaders($user))
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['user' => [
                'id', 'name', 'email', 'email_verified_at', 'onboarding_completed_at',
                'connections_count', 'has_active_subscription',
            ]], 'user_credits']);
    }
}
