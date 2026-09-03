<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\PostTarget;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPostMonitorTest extends TestCase
{
    use RefreshDatabase;

    private function adminHeaders(): array
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::create(['name' => 'admin', 'display_name' => 'Admin']));
        $token = $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'password'])
            ->json('data.token');

        return ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
    }

    public function test_index_includes_target_delivery_mode_without_full_meta(): void
    {
        $headers = $this->adminHeaders();

        $user = User::factory()->create();
        $post = $user->posts()->create([
            'caption' => null,
            'media' => [],
            'status' => Post::STATUS_POSTED,
        ]);
        $post->targets()->create([
            'platform' => 'tiktok',
            'caption_override' => 'TikTok-only caption',
            'status' => PostTarget::STATUS_PUBLISHED,
            'meta' => ['mode' => 'inbox', 'publish_id' => 'x', 'log' => [['step' => 'init:inbox']]],
        ]);

        $response = $this->getJson('/api/admin/posts', $headers)->assertOk();

        $target = collect($response->json('data'))
            ->firstWhere('id', $post->id)['targets'][0];

        $this->assertSame('inbox', $target['delivery_mode']);
        $this->assertSame('TikTok-only caption', $target['caption_override']);
        // The heavy meta audit log stays detail-only.
        $this->assertArrayNotHasKey('meta', $target);
    }
}
