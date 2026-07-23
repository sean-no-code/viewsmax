<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\GoalTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The conversion goal/event types are a seeded reference list owned by the
 * database (not hardcoded in the SPA). They are exposed read-only so the
 * Analytics "Conversion events" dropdown can build itself from the API.
 */
class GoalTypeTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(): array
    {
        $user = User::factory()->create();
        $login = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        return [
            'Authorization' => 'Bearer ' . $login->json('data.token'),
            'Accept' => 'application/json',
        ];
    }

    public function test_authenticated_user_can_list_seeded_goal_types(): void
    {
        $this->seed(GoalTypeSeeder::class);

        $response = $this->withHeaders($this->authHeaders())->getJson('/api/goal-types');

        $response->assertOk()->assertJsonPath('success', true);

        $data = collect($response->json('data'));
        // The five built-in types now live in the DB.
        foreach (['conversion', 'call booked', 'email-signup', 'newsletter', 'trial'] as $value) {
            $this->assertTrue($data->contains('value', $value), "missing goal type: {$value}");
        }

        // Labels are stored in the DB too — "conversion" is presented as "Purchase".
        $this->assertSame('Purchase', $data->firstWhere('value', 'conversion')['label']);
    }

    public function test_goal_types_endpoint_requires_authentication(): void
    {
        $this->seed(GoalTypeSeeder::class);

        $this->getJson('/api/goal-types')->assertStatus(401);
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(GoalTypeSeeder::class);
        $this->seed(GoalTypeSeeder::class);

        $this->assertSame(1, \App\Models\GoalType::where('value', 'conversion')->count());
    }
}
