<?php

namespace Tests\Feature;

use App\Services\CaptApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CaptApiServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.captapi.api_key', 'capt_test_key');
        config()->set('services.captapi.base_url', 'https://api.captapi.com/v1');
    }

    public function test_transcript_error_object_message_is_surfaced(): void
    {
        // CaptAPI errors are structured objects, not strings.
        Http::fake(['*/youtube/transcript*' => Http::response([
            'success' => false,
            'error' => ['code' => 'NOT_FOUND', 'message' => 'No transcript is available for this video'],
        ], 404)]);

        try {
            app(CaptApiService::class)->getTranscript('youtube', 'https://www.youtube.com/watch?v=abc123');
            $this->fail('Expected a RuntimeException.');
        } catch (\RuntimeException $e) {
            $this->assertSame('No transcript is available for this video', $e->getMessage());
        }
    }
}
