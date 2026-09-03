<?php

namespace Tests\Feature;

use App\Models\OutlierChannel;
use App\Models\OutlierCompetitorChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutlierCompetitorTest extends TestCase
{
    use RefreshDatabase;

    // outlier_db is kept alive suite-wide via TestCase::$connectionsToTransact.

    private function authHeaders(User $user): array
    {
        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->json('data.token');

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    private function makeChannel(string $name = 'Creator'): OutlierChannel
    {
        return OutlierChannel::create([
            'platform' => 'youtube', 'youtube_channel_id' => 'UC-'.uniqid(), 'channel_name' => $name,
        ]);
    }

    public function test_user_can_add_and_list_a_competitor_channel(): void
    {
        $user = User::factory()->create();
        $channel = $this->makeChannel('Rival Channel');

        $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/outliers/competitors', ['channel_id' => $channel->id])
            ->assertOk()
            ->assertJsonPath('data.channel_id', $channel->id);

        $list = $this->withHeaders($this->authHeaders($user))
            ->getJson('/api/outliers/competitors')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $list);
        $this->assertSame($channel->id, $list[0]['channel_id']);
        $this->assertSame('Rival Channel', $list[0]['channel_name']);
    }

    public function test_adding_twice_is_idempotent(): void
    {
        $user = User::factory()->create();
        $channel = $this->makeChannel();
        $headers = $this->authHeaders($user);

        $this->withHeaders($headers)->postJson('/api/outliers/competitors', ['channel_id' => $channel->id])->assertOk();
        $this->withHeaders($headers)->postJson('/api/outliers/competitors', ['channel_id' => $channel->id])->assertOk();

        $this->assertSame(1, OutlierCompetitorChannel::where('user_id', $user->id)->count());
    }

    public function test_user_can_remove_a_competitor_channel(): void
    {
        $user = User::factory()->create();
        $channel = $this->makeChannel();
        OutlierCompetitorChannel::create(['user_id' => $user->id, 'channel_id' => $channel->id]);

        $this->withHeaders($this->authHeaders($user))
            ->deleteJson("/api/outliers/competitors/{$channel->id}")
            ->assertOk();

        $this->assertSame(0, OutlierCompetitorChannel::where('user_id', $user->id)->count());
    }

    public function test_competitors_are_scoped_per_user(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $channel = $this->makeChannel();
        OutlierCompetitorChannel::create(['user_id' => $a->id, 'channel_id' => $channel->id]);

        $this->assertCount(0, $this->withHeaders($this->authHeaders($b))
            ->getJson('/api/outliers/competitors')->json('data'));
    }

    public function test_unknown_channel_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->withHeaders($this->authHeaders($user))
            ->postJson('/api/outliers/competitors', ['channel_id' => 999999])
            ->assertStatus(422);
    }
}
