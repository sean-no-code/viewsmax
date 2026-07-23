<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Connection;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Brands group multiple connected accounts (both the social_accounts store and
 * the legacy connections store) so the composer can select them in one click.
 * Membership is many-to-many: one account may sit in several brands.
 */
class BrandFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test-token')->plainTextToken;
    }

    private function auth(?string $token = null): array
    {
        return ['Authorization' => 'Bearer '.($token ?? $this->token), 'Accept' => 'application/json'];
    }

    private function connectX(User $user, string $accountId): SocialAccount
    {
        return $user->socialAccounts()->create([
            'platform' => 'x',
            'platform_account_id' => $accountId,
            'name' => "Account {$accountId}",
            'username' => "user_{$accountId}",
            'access_token' => 'token-'.$accountId,
            'token_expires_at' => now()->addDay(),
            'scopes' => ['tweet.write'],
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
    }

    private function connectYouTube(User $user): Connection
    {
        return $user->connections()->create([
            'provider' => 'youtube',
            'account_name' => 'My Channel',
            'account_id' => 'UC123',
            'access_token' => 'yt-token',
        ]);
    }

    public function test_create_brand_with_members_from_both_stores(): void
    {
        $x = $this->connectX($this->user, 'x-1');
        $yt = $this->connectYouTube($this->user);

        $response = $this->withHeaders($this->auth())->postJson('/api/brands', [
            'name' => 'Acme',
            'social_account_ids' => [$x->id],
            'connection_ids' => [$yt->id],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Acme')
            ->assertJsonPath('data.social_accounts.0.id', $x->id)
            ->assertJsonPath('data.connections.0.id', $yt->id);

        $this->assertDatabaseHas('brand_accounts', ['social_account_id' => $x->id, 'connection_id' => null]);
        $this->assertDatabaseHas('brand_accounts', ['connection_id' => $yt->id, 'social_account_id' => null]);
    }

    public function test_index_lists_only_own_brands_with_members(): void
    {
        $x = $this->connectX($this->user, 'x-1');
        $brand = $this->user->brands()->create(['name' => 'Mine']);
        $brand->socialAccounts()->sync([$x->id]);

        $other = User::factory()->create();
        $other->brands()->create(['name' => 'Theirs']);

        $response = $this->withHeaders($this->auth())->getJson('/api/brands');

        $response->assertStatus(200)->assertJsonPath('success', true);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Mine', $response->json('data.0.name'));
        $this->assertSame($x->id, $response->json('data.0.social_accounts.0.id'));
    }

    public function test_brand_name_is_unique_per_user_but_not_globally(): void
    {
        $this->user->brands()->create(['name' => 'Acme']);

        $this->withHeaders($this->auth())->postJson('/api/brands', ['name' => 'Acme'])
            ->assertStatus(422);

        $other = User::factory()->create();
        $otherToken = $other->createToken('t')->plainTextToken;
        Auth::forgetGuards(); // RequestGuard caches the first user across in-test requests
        $this->withHeaders($this->auth($otherToken))->postJson('/api/brands', ['name' => 'Acme'])
            ->assertStatus(201);
    }

    public function test_foreign_account_ids_are_rejected(): void
    {
        $other = User::factory()->create();
        $foreignX = $this->connectX($other, 'x-9');
        $foreignYt = $this->connectYouTube($other);

        $this->withHeaders($this->auth())->postJson('/api/brands', [
            'name' => 'Sneaky',
            'social_account_ids' => [$foreignX->id],
        ])->assertStatus(422);

        $this->withHeaders($this->auth())->postJson('/api/brands', [
            'name' => 'Sneaky',
            'connection_ids' => [$foreignYt->id],
        ])->assertStatus(422);

        $this->assertSame(0, Brand::count());
    }

    public function test_rename_without_member_keys_keeps_members(): void
    {
        $x = $this->connectX($this->user, 'x-1');
        $brand = $this->user->brands()->create(['name' => 'Old']);
        $brand->socialAccounts()->sync([$x->id]);

        $this->withHeaders($this->auth())->putJson("/api/brands/{$brand->id}", ['name' => 'New'])
            ->assertStatus(200)
            ->assertJsonPath('data.name', 'New');

        $this->assertSame([$x->id], $brand->fresh()->socialAccounts()->pluck('social_accounts.id')->all());
    }

    public function test_update_replaces_members_when_keys_present(): void
    {
        $a = $this->connectX($this->user, 'x-1');
        $b = $this->connectX($this->user, 'x-2');
        $yt = $this->connectYouTube($this->user);
        $brand = $this->user->brands()->create(['name' => 'Acme']);
        $brand->socialAccounts()->sync([$a->id]);
        $brand->connections()->sync([$yt->id]);

        $this->withHeaders($this->auth())->putJson("/api/brands/{$brand->id}", [
            'name' => 'Acme',
            'social_account_ids' => [$b->id],
            'connection_ids' => [],
        ])->assertStatus(200);

        $brand->refresh();
        $this->assertSame([$b->id], $brand->socialAccounts()->pluck('social_accounts.id')->all());
        // Emptying one store's list must not touch the other store's rows,
        // and vice versa: the connection list was explicitly cleared.
        $this->assertCount(0, $brand->connections);
    }

    public function test_cross_user_update_and_delete_are_404(): void
    {
        $other = User::factory()->create();
        $foreign = $other->brands()->create(['name' => 'Theirs']);

        $this->withHeaders($this->auth())->putJson("/api/brands/{$foreign->id}", ['name' => 'Hijack'])
            ->assertStatus(404);
        $this->withHeaders($this->auth())->deleteJson("/api/brands/{$foreign->id}")
            ->assertStatus(404);
        $this->assertNotNull($foreign->fresh());
    }

    public function test_deleting_brand_cascades_pivot_and_nulls_posts(): void
    {
        $x = $this->connectX($this->user, 'x-1');
        $brand = $this->user->brands()->create(['name' => 'Acme']);
        $brand->socialAccounts()->sync([$x->id]);
        $post = Post::create([
            'user_id' => $this->user->id,
            'brand_id' => $brand->id,
            'caption' => 'hi',
            'media' => [],
            'status' => Post::STATUS_DRAFT,
        ]);

        $this->withHeaders($this->auth())->deleteJson("/api/brands/{$brand->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('brand_accounts', ['brand_id' => $brand->id]);
        $this->assertNull($post->fresh()->brand_id);
        $this->assertNotNull($x->fresh()); // members are never deleted with the brand
    }

    public function test_disconnecting_account_drops_it_from_brands(): void
    {
        $x = $this->connectX($this->user, 'x-1');
        $brand = $this->user->brands()->create(['name' => 'Acme']);
        $brand->socialAccounts()->sync([$x->id]);

        $x->delete();

        $this->assertDatabaseMissing('brand_accounts', ['brand_id' => $brand->id]);
        $this->assertNotNull($brand->fresh());
    }

    public function test_post_can_carry_brand_id(): void
    {
        $brand = $this->user->brands()->create(['name' => 'Acme']);
        $this->connectX($this->user, 'x-1');

        $response = $this->withHeaders($this->auth())->postJson('/api/posts', [
            'caption' => 'branded',
            'status' => 'draft',
            'platforms' => ['x'],
            'brand_id' => $brand->id,
        ]);

        $response->assertStatus(201);
        $this->assertSame($brand->id, Post::findOrFail($response->json('id'))->brand_id);
    }

    public function test_post_rejects_foreign_brand_id(): void
    {
        $other = User::factory()->create();
        $foreign = $other->brands()->create(['name' => 'Theirs']);

        $this->withHeaders($this->auth())->postJson('/api/posts', [
            'caption' => 'nope',
            'status' => 'draft',
            'platforms' => ['x'],
            'brand_id' => $foreign->id,
        ])->assertStatus(422);
    }

    public function test_post_update_can_set_and_clear_brand_id(): void
    {
        $brand = $this->user->brands()->create(['name' => 'Acme']);
        $post = Post::create([
            'user_id' => $this->user->id,
            'caption' => 'hi',
            'media' => [],
            'status' => Post::STATUS_DRAFT,
        ]);

        $this->withHeaders($this->auth())->putJson("/api/posts/{$post->id}", ['brand_id' => $brand->id])
            ->assertStatus(200);
        $this->assertSame($brand->id, $post->fresh()->brand_id);

        $this->withHeaders($this->auth())->putJson("/api/posts/{$post->id}", ['brand_id' => null])
            ->assertStatus(200);
        $this->assertNull($post->fresh()->brand_id);
    }
}
