<?php

namespace Tests\Feature;

use App\Models\BeehiivConnection;
use App\Models\User;
use App\Services\BeehiivService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BeehiivConnectionTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(User $user): array
    {
        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->json('data.token');

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    private function mockPublications(array $pubs): void
    {
        $this->mock(BeehiivService::class, function ($m) use ($pubs) {
            $m->shouldReceive('fetchPublications')->andReturn($pubs);
        });
    }

    public function test_connect_validates_stores_encrypted_and_never_returns_the_key(): void
    {
        $user = User::factory()->create();
        $this->mockPublications([['id' => 'pub_123', 'name' => 'My Newsletter']]);

        $res = $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/beehiiv/connection', ['api_key' => 'bh-secret-key-abcd'])
            ->assertOk();

        $res->assertJsonPath('data.connected', true)
            ->assertJsonPath('data.publication_name', 'My Newsletter')
            ->assertJsonPath('data.publication_id', 'pub_123');
        // The raw key must never be in the response.
        $this->assertStringNotContainsString('bh-secret-key-abcd', $res->getContent());
        $this->assertSame('••••abcd', $res->json('data.key_hint'));

        // Stored encrypted at rest, but decrypts through the model.
        $raw = DB::table('beehiiv_connections')->where('user_id', $user->id)->value('api_key');
        $this->assertNotSame('bh-secret-key-abcd', $raw);
        $this->assertSame('bh-secret-key-abcd', BeehiivConnection::where('user_id', $user->id)->first()->api_key);
    }

    public function test_connect_rejects_an_invalid_key(): void
    {
        $user = User::factory()->create();
        $this->mock(BeehiivService::class, function ($m) {
            $m->shouldReceive('fetchPublications')->andThrow(new \InvalidArgumentException('rejected'));
        });

        $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/beehiiv/connection', ['api_key' => 'bad-key-xxxx'])
            ->assertStatus(422);

        $this->assertDatabaseCount('beehiiv_connections', 0);
    }

    public function test_show_reports_status_without_the_key(): void
    {
        $user = User::factory()->create();
        $this->mockPublications([['id' => 'pub_9', 'name' => 'Weekly']]);
        $this->withHeaders($this->authHeaders($user))->postJson('/api/beehiiv/connection', ['api_key' => 'bh-key-1234']);

        $res = $this->withHeaders($this->authHeaders($user))->getJson('/api/beehiiv/connection')->assertOk();
        $res->assertJsonPath('data.connected', true)->assertJsonPath('data.publication_name', 'Weekly');
        $this->assertStringNotContainsString('bh-key-1234', $res->getContent());
    }

    public function test_show_is_not_connected_by_default(): void
    {
        $user = User::factory()->create();
        $this->withHeaders($this->authHeaders($user))->getJson('/api/beehiiv/connection')
            ->assertOk()->assertJsonPath('data.connected', false);
    }

    public function test_disconnect_removes_the_connection(): void
    {
        $user = User::factory()->create();
        $this->mockPublications([['id' => 'pub_9', 'name' => 'Weekly']]);
        $this->withHeaders($this->authHeaders($user))->postJson('/api/beehiiv/connection', ['api_key' => 'bh-key-1234']);
        $this->assertDatabaseCount('beehiiv_connections', 1);

        $this->withHeaders($this->authHeaders($user))->deleteJson('/api/beehiiv/connection')->assertOk();
        $this->assertDatabaseCount('beehiiv_connections', 0);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/beehiiv/connection')->assertUnauthorized();
        $this->postJson('/api/beehiiv/connection', ['api_key' => 'x'])->assertUnauthorized();
    }
}
