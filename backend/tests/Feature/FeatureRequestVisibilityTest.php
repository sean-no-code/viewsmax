<?php

namespace Tests\Feature;

use App\Models\FeatureRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature requests start "in review" and are hidden from everyone except the
 * person who filed them (and admins) until an admin approves them. Admins move
 * them through in_review → approved → implemented.
 */
class FeatureRequestVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin'])->id);

        return $admin;
    }

    private function request(User $by, string $status, string $title = 'Idea'): FeatureRequest
    {
        return FeatureRequest::create(['user_id' => $by->id, 'title' => $title, 'description' => 'd', 'status' => $status]);
    }

    public function test_new_requests_start_in_review_and_are_only_visible_to_their_author(): void
    {
        $author = User::factory()->create();
        $other = User::factory()->create();

        $id = $this->postJson('/api/feature-requests', ['title' => 'Dark mode', 'description' => 'Please'], $this->headers($author))
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'in_review')
            ->assertJsonPath('data.is_mine', true)
            ->json('data.id');

        $this->getJson('/api/feature-requests', $this->headers($author))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.is_mine', true);

        $this->getJson('/api/feature-requests', $this->headers($other))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // A hidden request can't be voted on by someone who can't see it.
        $this->postJson("/api/feature-requests/{$id}/upvote", [], $this->headers($other))->assertStatus(404);
    }

    public function test_everyone_sees_approved_and_implemented_requests(): void
    {
        $author = User::factory()->create();
        $other = User::factory()->create();
        $this->request($author, 'in_review', 'Hidden');
        $this->request($author, 'approved', 'Approved one');
        $this->request($author, 'implemented', 'Shipped one');

        $titles = collect($this->getJson('/api/feature-requests?sort=new', $this->headers($other))->assertOk()->json('data'))
            ->pluck('title')->sort()->values()->all();

        $this->assertSame(['Approved one', 'Shipped one'], $titles);
        $this->assertFalse(collect($this->getJson('/api/feature-requests', $this->headers($other))->json('data'))->firstWhere('title', 'Approved one')['is_mine']);
    }

    public function test_admins_see_everything_with_the_requester_and_can_change_status(): void
    {
        $author = User::factory()->create(['name' => 'Ada']);
        $admin = $this->admin();
        $fr = $this->request($author, 'in_review');

        $list = $this->getJson('/api/feature-requests', $this->headers($admin))->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertSame('Ada', $list[0]['requested_by']);

        // Non-admins never get the requester's name.
        $this->assertArrayNotHasKey('requested_by', $this->getJson('/api/feature-requests', $this->headers($author))->json('data.0'));

        $this->patchJson("/api/feature-requests/{$fr->id}/status", ['status' => 'approved'], $this->headers($admin))
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
        $this->assertSame('approved', $fr->fresh()->status);

        $this->patchJson("/api/feature-requests/{$fr->id}/status", ['status' => 'bogus'], $this->headers($admin))->assertStatus(422);
        $this->patchJson('/api/feature-requests/999/status', ['status' => 'approved'], $this->headers($admin))->assertStatus(404);
    }

    public function test_only_admins_can_change_status(): void
    {
        $author = User::factory()->create();
        $fr = $this->request($author, 'in_review');

        $this->patchJson("/api/feature-requests/{$fr->id}/status", ['status' => 'approved'], $this->headers($author))->assertStatus(403);
        $this->assertSame('in_review', $fr->fresh()->status);
    }
}
