<?php

namespace Tests\Feature;

use App\Models\ApiKeyRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every REST call made with a vmx_ API key is audit-logged (api_key_requests):
 * who, method, path, input, status and response — the REST twin of the MCP
 * audit log. The web app's own login-session calls are not logged.
 */
class ApiKeyAuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function loginHeaders(User $user): array
    {
        return [
            'Authorization' => 'Bearer '.$user->createToken('mobile-app')->plainTextToken,
            'Accept' => 'application/json',
        ];
    }

    private function keyHeaders(User $user): array
    {
        $plain = 'vmx_'.$user->generateTokenString();
        $user->tokens()->create(['name' => 'mcp', 'token' => hash('sha256', $plain), 'abilities' => ['mcp']]);

        return ['Authorization' => 'Bearer '.$plain, 'Accept' => 'application/json'];
    }

    public function test_api_key_calls_are_logged_with_input_and_response(): void
    {
        $user = User::factory()->create();
        $offer = $user->offers()->create(['offer_url' => 'https://example.com/x']);
        $headers = $this->keyHeaders($user);

        $response = $this->withHeaders($headers)
            ->putJson("/api/tracking-events/{$offer->id}", ['name' => 'Audit me'])
            ->assertOk();

        $row = ApiKeyRequest::where('user_id', $user->id)->sole();
        $this->assertSame('PUT', $row->method);
        $this->assertSame("/api/tracking-events/{$offer->id}", $row->path);
        $this->assertSame(200, $row->status);
        $this->assertSame('Audit me', $row->request['name']);
        $this->assertSame($response->getContent(), $row->response);
        $this->assertStringContainsString('Audit me', $row->response);
    }

    public function test_get_calls_record_the_query_string_and_failures_keep_their_status(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $foreign = $other->offers()->create(['offer_url' => 'https://example.com/other']);
        $headers = $this->keyHeaders($user);

        $this->withHeaders($headers)->getJson('/api/posts?status=draft')->assertOk();
        $this->withHeaders($headers)->getJson("/api/tracking-events/{$foreign->id}")->assertNotFound();

        $rows = ApiKeyRequest::where('user_id', $user->id)->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['status' => 'draft'], $rows[0]->request);
        $this->assertSame(404, $rows[1]->status);
        $this->assertNotNull($rows[1]->response);
    }

    public function test_calls_refused_for_billing_are_logged(): void
    {
        $user = User::factory()->expired()->withPlan('cancelled')->create();
        $headers = $this->keyHeaders($user);

        $this->withHeaders($headers)->getJson('/api/posts')->assertStatus(402);

        $row = ApiKeyRequest::where('user_id', $user->id)->sole();
        $this->assertSame(402, $row->status);
        $this->assertSame('subscription_inactive', json_decode($row->response, true)['code']);
    }

    public function test_login_session_calls_are_not_logged(): void
    {
        $user = User::factory()->create();

        $this->withHeaders($this->loginHeaders($user))->getJson('/api/posts')->assertOk();

        $this->assertSame(0, ApiKeyRequest::count());
    }
}
