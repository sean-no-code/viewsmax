<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutlierLibraryTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(): array
    {
        $user = User::factory()->create();
        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->json('data.token');

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    public function test_saved_filters_round_trip(): void
    {
        $headers = $this->authHeaders();

        $this->withHeaders($headers)->postJson('/api/outliers/saved-filters', [
            'name' => 'Shorts breakouts',
            'filters' => ['platform' => 'tiktok', 'duration_type' => 'shorts', 'min_score' => 40],
        ])->assertCreated()->assertJsonPath('data.name', 'Shorts breakouts');

        $list = $this->withHeaders($headers)->getJson('/api/outliers/saved-filters')
            ->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertSame('tiktok', $list[0]['filters']['platform']);

        $this->withHeaders($headers)->deleteJson('/api/outliers/saved-filters/'.$list[0]['id'])->assertOk();
        $this->assertCount(0, $this->withHeaders($headers)->getJson('/api/outliers/saved-filters')->json('data'));
    }

    public function test_saved_filters_are_scoped_per_user(): void
    {
        $a = $this->authHeaders();
        $b = $this->authHeaders();

        $this->withHeaders($a)->postJson('/api/outliers/saved-filters', [
            'name' => 'Mine', 'filters' => ['platform' => 'youtube'],
        ])->assertCreated();

        $this->assertCount(0, $this->withHeaders($b)->getJson('/api/outliers/saved-filters')->json('data'));
    }

    public function test_save_outlier_with_tags_then_filter_by_tag(): void
    {
        $headers = $this->authHeaders();

        $this->withHeaders($headers)->postJson('/api/outliers/library', [
            'platform' => 'youtube',
            'video_id' => 'vid123',
            'snapshot' => ['title' => 'Viral Hook', 'channel_name' => 'CreatorX', 'views' => 1000000, 'outlier_score' => 82],
            'tags' => ['inspiration', 'hooks'],
        ])->assertCreated()->assertJsonPath('data.snapshot.title', 'Viral Hook');

        // Second saved outlier with a different tag.
        $this->withHeaders($headers)->postJson('/api/outliers/library', [
            'platform' => 'tiktok',
            'video_id' => 'tt999',
            'snapshot' => ['title' => 'Other', 'channel_name' => 'CreatorY'],
            'tags' => ['misc'],
        ])->assertCreated();

        // Tags endpoint returns the per-user tags.
        $tags = collect($this->withHeaders($headers)->getJson('/api/outliers/tags')->json('data'))->pluck('name');
        $this->assertTrue($tags->contains('inspiration'));
        $this->assertTrue($tags->contains('misc'));

        // Filter library by tag → only the matching saved outlier.
        $filtered = $this->withHeaders($headers)->getJson('/api/outliers/library?tags[]=hooks')
            ->assertOk()->json('data');
        $this->assertCount(1, $filtered);
        $this->assertSame('vid123', $filtered[0]['video_id']);

        // Text search on the snapshot.
        $found = $this->withHeaders($headers)->getJson('/api/outliers/library?q=Viral')->json('data');
        $this->assertCount(1, $found);
    }

    public function test_saving_same_video_twice_updates_not_duplicates(): void
    {
        $headers = $this->authHeaders();
        $payload = [
            'platform' => 'youtube', 'video_id' => 'dup1',
            'snapshot' => ['title' => 'First'],
        ];
        $this->withHeaders($headers)->postJson('/api/outliers/library', $payload)->assertCreated();
        $this->withHeaders($headers)->postJson('/api/outliers/library', array_merge($payload, [
            'snapshot' => ['title' => 'Updated'], 'tags' => ['retag'],
        ]))->assertCreated();

        $lib = $this->withHeaders($headers)->getJson('/api/outliers/library')->json('data');
        $this->assertCount(1, $lib);
        $this->assertSame('Updated', $lib[0]['snapshot']['title']);
    }

    public function test_library_filters_by_platform_and_creator(): void
    {
        $headers = $this->authHeaders();

        $this->withHeaders($headers)->postJson('/api/outliers/library', [
            'platform' => 'youtube', 'video_id' => 'yt1',
            'snapshot' => ['title' => 'Long form', 'channel_name' => 'Alpha Creator'],
        ])->assertCreated();
        $this->withHeaders($headers)->postJson('/api/outliers/library', [
            'platform' => 'tiktok', 'video_id' => 'tt1',
            'snapshot' => ['title' => 'Short form', 'channel_name' => 'Beta Maker'],
        ])->assertCreated();

        // Platform filter (multi-value).
        $tiktokOnly = $this->withHeaders($headers)
            ->getJson('/api/outliers/library?platforms[]=tiktok')->assertOk()->json('data');
        $this->assertCount(1, $tiktokOnly);
        $this->assertSame('tt1', $tiktokOnly[0]['video_id']);

        $both = $this->withHeaders($headers)
            ->getJson('/api/outliers/library?platforms[]=tiktok&platforms[]=youtube')->json('data');
        $this->assertCount(2, $both);

        // Creator filter matches the snapshot channel name.
        $alpha = $this->withHeaders($headers)
            ->getJson('/api/outliers/library?creator='.urlencode('Alpha Creator'))->assertOk()->json('data');
        $this->assertCount(1, $alpha);
        $this->assertSame('yt1', $alpha[0]['video_id']);
    }

    public function test_update_tags_and_unsave(): void
    {
        $headers = $this->authHeaders();
        $saved = $this->withHeaders($headers)->postJson('/api/outliers/library', [
            'platform' => 'instagram', 'video_id' => 'ig1',
            'snapshot' => ['title' => 'Reel', 'comment_count' => 42],
            'tags' => ['old'],
        ])->json('data');

        $this->withHeaders($headers)->patchJson('/api/outliers/library/'.$saved['id'], [
            'tags' => ['fresh'],
        ])->assertOk()->assertJsonPath('data.tags.0.name', 'fresh');

        $this->withHeaders($headers)->deleteJson('/api/outliers/library/'.$saved['id'])->assertOk();
        $this->assertCount(0, $this->withHeaders($headers)->getJson('/api/outliers/library')->json('data'));
    }
}
