<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FreeToolTranscriptTest extends TestCase
{
    use RefreshDatabase;

    private const YT = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.captapi.api_key' => 'capt_live_test',
            'services.captapi.base_url' => 'https://api.captapi.com/v1',
        ]);
    }

    private function fakeSuccess(): void
    {
        Http::fake([
            'api.captapi.com/*' => Http::response([
                'success' => true,
                'data' => [
                    'platform' => 'youtube',
                    'url' => self::YT,
                    'text' => 'Never gonna give you up.',
                    'segments' => [['text' => 'Never gonna give you up.', 'startMs' => 0, 'endMs' => 2000]],
                    'language' => 'en',
                    'fetchedAt' => '2026-08-17T12:14:27.902Z',
                ],
                'cached' => false,
                'creditsUsed' => 2,
                'requestId' => 'req-1',
            ], 200),
        ]);
    }

    public function test_fetches_and_stores_a_transcript(): void
    {
        $this->fakeSuccess();

        $this->postJson('/api/free-tools/transcript', ['platform' => 'youtube', 'url' => self::YT])
            ->assertOk()
            ->assertJsonPath('data.text', 'Never gonna give you up.')
            ->assertJsonPath('data.cached', false)
            ->assertJsonPath('data.segments.0.startMs', 0);

        $this->assertDatabaseHas('transcripts', ['platform' => 'youtube', 'source_url' => self::YT, 'language' => 'en'], 'outlier_db');
        Http::assertSentCount(1);
    }

    public function test_second_request_is_served_from_the_db_cache(): void
    {
        $this->fakeSuccess();

        $this->postJson('/api/free-tools/transcript', ['platform' => 'youtube', 'url' => self::YT])->assertOk();
        $this->postJson('/api/free-tools/transcript', ['platform' => 'youtube', 'url' => self::YT])
            ->assertOk()
            ->assertJsonPath('data.cached', true);

        Http::assertSentCount(1); // the API was hit only the first time
        $this->assertDatabaseCount('transcripts', 1, 'outlier_db');
    }

    public function test_swaps_platform_endpoint(): void
    {
        Http::fake([
            'api.captapi.com/v1/tiktok/transcript*' => Http::response([
                'success' => true,
                'data' => ['platform' => 'tiktok', 'url' => 'x', 'text' => 'hi', 'segments' => [], 'language' => 'en', 'fetchedAt' => '2026-08-17T12:14:27.902Z'],
                'cached' => false, 'creditsUsed' => 2, 'requestId' => 'r',
            ], 200),
        ]);

        $this->postJson('/api/free-tools/transcript', ['platform' => 'tiktok', 'url' => 'https://www.tiktok.com/@promakeuppro/video/7652684315989462290'])
            ->assertOk();

        Http::assertSent(fn ($req) => str_contains($req->url(), '/v1/tiktok/transcript'));
    }

    public function test_rejects_a_url_that_does_not_match_the_platform(): void
    {
        Http::fake();

        $this->postJson('/api/free-tools/transcript', ['platform' => 'youtube', 'url' => 'https://www.instagram.com/p/DZFsjH9E3gK/'])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_validates_the_platform(): void
    {
        $this->postJson('/api/free-tools/transcript', ['platform' => 'facebook', 'url' => self::YT])
            ->assertStatus(422);
    }

    public function test_returns_502_on_provider_failure(): void
    {
        Http::fake(['api.captapi.com/*' => Http::response(['success' => false, 'error' => 'Not found'], 404)]);

        $this->postJson('/api/free-tools/transcript', ['platform' => 'youtube', 'url' => self::YT])
            ->assertStatus(502);

        $this->assertDatabaseCount('transcripts', 0, 'outlier_db');
    }
}
