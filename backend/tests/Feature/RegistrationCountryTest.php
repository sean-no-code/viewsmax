<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Registration records the signup country in the last-login columns, so a user
 * who signed up through an AI agent's OAuth flow (and never logged in to the
 * SPA) still shows a country in admin. Resolution is best-effort.
 */
class RegistrationCountryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_api_registration_uses_the_cdn_country_header_without_a_lookup(): void
    {
        Http::fake();

        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
            ->withHeaders(['CloudFront-Viewer-Country' => 'gb'])
            ->postJson('/api/register', $this->payload())
            ->assertStatus(201);

        $user = User::where('email', 'jane@example.com')->firstOrFail();
        $this->assertSame('GB', $user->last_login_country_code);
        $this->assertSame('United Kingdom', $user->last_login_country);
        Http::assertNothingSent();
    }

    public function test_agent_registration_on_the_api_host_records_the_country(): void
    {
        Http::fake();

        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
            ->withHeaders(['CF-IPCountry' => 'DE'])
            ->post('/register', $this->payload() + ['marketing_consent' => '0'])
            ->assertRedirect();

        $user = User::where('email', 'jane@example.com')->firstOrFail();
        $this->assertSame('agent', $user->signup_source);
        $this->assertSame('DE', $user->last_login_country_code);
        $this->assertSame('Germany', $user->last_login_country);
    }

    public function test_registration_falls_back_to_an_ip_lookup(): void
    {
        Http::fake([
            'ip-api.com/*' => Http::response(['status' => 'success', 'country' => 'Australia', 'countryCode' => 'AU']),
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '1.1.1.1'])
            ->postJson('/api/register', $this->payload())
            ->assertStatus(201);

        $user = User::where('email', 'jane@example.com')->firstOrFail();
        $this->assertSame('AU', $user->last_login_country_code);
        $this->assertSame('Australia', $user->last_login_country);
    }

    public function test_unknown_location_leaves_the_country_null_and_never_blocks_signup(): void
    {
        Http::fake(); // REMOTE_ADDR is 127.0.0.1 in tests: private, so no lookup happens.

        $this->postJson('/api/register', $this->payload())->assertStatus(201);

        $user = User::where('email', 'jane@example.com')->firstOrFail();
        $this->assertNull($user->last_login_country_code);
        $this->assertNull($user->last_login_country);
        Http::assertNothingSent();
    }

    private function payload(): array
    {
        return [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ];
    }
}
