<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LastLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_records_timestamp_ip_and_country(): void
    {
        Http::fake([
            'ip-api.com/*' => Http::response(['status' => 'success', 'country' => 'United States', 'countryCode' => 'US'], 200),
        ]);

        $user = User::factory()->create();

        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
            ->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();

        $user->refresh();
        $this->assertNotNull($user->last_login_at);
        $this->assertSame('8.8.8.8', $user->last_login_ip);
        $this->assertSame('United States', $user->last_login_country);
        $this->assertSame('US', $user->last_login_country_code);
    }

    public function test_login_still_succeeds_when_geo_lookup_fails(): void
    {
        // A 500 / unreachable geo service must never break login.
        Http::fake(['ip-api.com/*' => Http::response('boom', 500)]);

        $user = User::factory()->create();

        $res = $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
            ->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk();

        $this->assertNotNull($res->json('data.token'));
        $user->refresh();
        $this->assertNotNull($user->last_login_at);
        $this->assertSame('8.8.8.8', $user->last_login_ip);
        $this->assertNull($user->last_login_country);
    }
}
