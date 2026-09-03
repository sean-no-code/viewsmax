<?php

namespace Tests\Feature;

use App\Models\Role;
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

    public function test_admin_index_exposes_last_login_fields(): void
    {
        Http::fake(); // no stray network calls from the admin's own login

        $admin = User::factory()->create();
        $admin->roles()->attach(Role::create(['name' => 'admin', 'display_name' => 'Admin']));
        $token = $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'password'])->json('data.token');

        User::factory()->create([
            'created_at' => '2026-06-05 12:00:00',
            'email' => 'geo@example.com',
            'last_login_at' => '2026-06-06 09:00:00',
            'last_login_country' => 'United Kingdom',
            'last_login_country_code' => 'GB',
        ]);

        $rows = $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->getJson('/api/admin/users?from=2026-06-01&to=2026-06-10')
            ->assertOk()
            ->json('data.users');

        $row = collect($rows)->firstWhere('email', 'geo@example.com');
        $this->assertNotNull($row['last_login_at']);
        $this->assertSame('United Kingdom', $row['last_login_country']);
        $this->assertSame('GB', $row['last_login_country_code']);
    }
}
