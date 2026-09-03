<?php

namespace Tests\Feature;

use App\Jobs\GenerateOutlierBreakdownJob;
use App\Models\OutlierBreakdown;
use App\Models\OutlierChannel;
use App\Models\OutlierVideo;
use App\Models\Transcript;
use App\Models\User;
use App\Services\AnthropicService;
use App\Services\CaptApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OutlierBreakdownTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(): array
    {
        $user = User::factory()->create();
        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->json('data.token');

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    private function makeVideo(): OutlierVideo
    {
        $channel = OutlierChannel::create([
            'platform' => 'youtube',
            'youtube_channel_id' => 'UC123',
            'channel_name' => 'kaiexplains',
            'subscriber_count' => 250000,
            'average_views' => 140000,
        ]);

        return OutlierVideo::create([
            'platform' => 'youtube',
            'channel_id' => $channel->id,
            'youtube_video_id' => 'abc123xyz',
            'title' => 'Gas station sushi is a $2B business',
            'thumbnail_url' => 'https://example.com/t.jpg',
            'views' => 2100000,
            'like_count' => 150000,
            'comment_count' => 9000,
            'outlier_score' => 92,
            'duration' => 'PT38S',
            'published_at' => now()->subWeeks(2),
        ]);
    }

    // ---- Single-outlier show endpoint ----

    public function test_show_returns_video_with_channel(): void
    {
        $this->makeVideo();

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/outliers/youtube/abc123xyz')
            ->assertOk()
            ->assertJsonPath('data.title', 'Gas station sushi is a $2B business')
            ->assertJsonPath('data.channel.channel_name', 'kaiexplains');
    }

    public function test_show_unknown_video_is_404(): void
    {
        $this->withHeaders($this->authHeaders())
            ->getJson('/api/outliers/youtube/nope')
            ->assertNotFound();
    }

    public function test_show_does_not_shadow_literal_outlier_routes(): void
    {
        // /api/outliers/tags must still hit the tags endpoint, not show().
        $this->withHeaders($this->authHeaders())
            ->getJson('/api/outliers/tags')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    // ---- Breakdown lifecycle ----

    public function test_breakdown_status_is_none_before_generation(): void
    {
        $this->makeVideo();

        $this->withHeaders($this->authHeaders())
            ->getJson('/api/outliers/youtube/abc123xyz/breakdown')
            ->assertOk()
            ->assertJsonPath('data.status', 'none');
    }

    public function test_requesting_breakdown_queues_job_and_creates_pending_row(): void
    {
        Queue::fake();
        $this->makeVideo();

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/outliers/youtube/abc123xyz/breakdown')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        Queue::assertPushed(GenerateOutlierBreakdownJob::class, 1);
        $this->assertDatabaseHas('outlier_breakdowns', [
            'platform' => 'youtube', 'video_id' => 'abc123xyz', 'status' => 'pending',
        ], 'outlier_db');
    }

    public function test_requesting_breakdown_for_unknown_video_is_404(): void
    {
        Queue::fake();

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/outliers/youtube/nope/breakdown')
            ->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_completed_breakdown_is_returned_without_requeue(): void
    {
        Queue::fake();
        $this->makeVideo();
        OutlierBreakdown::create([
            'platform' => 'youtube', 'video_id' => 'abc123xyz',
            'status' => OutlierBreakdown::STATUS_COMPLETED,
            'payload' => ['idea' => ['topic' => 'Convenience-store food economics']],
        ]);

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/outliers/youtube/abc123xyz/breakdown')
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.payload.idea.topic', 'Convenience-store food economics');

        Queue::assertNothingPushed();
    }

    public function test_failed_breakdown_is_requeued_on_request(): void
    {
        Queue::fake();
        $this->makeVideo();
        OutlierBreakdown::create([
            'platform' => 'youtube', 'video_id' => 'abc123xyz',
            'status' => OutlierBreakdown::STATUS_FAILED,
            'error' => 'boom',
        ]);

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/outliers/youtube/abc123xyz/breakdown')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        Queue::assertPushed(GenerateOutlierBreakdownJob::class, 1);
    }

    public function test_job_generates_and_stores_payload(): void
    {
        $this->makeVideo();
        $breakdown = OutlierBreakdown::create([
            'platform' => 'youtube', 'video_id' => 'abc123xyz',
            'status' => OutlierBreakdown::STATUS_PENDING,
        ]);

        $transcript = new Transcript([
            'platform' => 'youtube',
            'text' => 'Gas station sushi is a two billion dollar business.',
            'segments' => [['start' => 0, 'text' => 'Gas station sushi is a two billion dollar business.']],
        ]);

        $this->mock(CaptApiService::class, function ($mock) use ($transcript) {
            $mock->shouldReceive('getTranscript')
                ->once()
                ->with('youtube', 'https://www.youtube.com/watch?v=abc123xyz')
                ->andReturn(['transcript' => $transcript, 'cached' => false]);
        });

        $payload = [
            'idea' => ['topic' => 'Convenience-store food economics', 'idea_seed' => 'seed', 'unique_angle' => 'angle'],
            'hook' => [['time' => '0.0s', 'line' => 'Gas station sushi…', 'note' => 'Stat shock.']],
            'structure' => ['summary' => '5 beats over 38s', 'beats' => []],
            'visual' => [],
            'transcript' => [['time' => '0:00', 'text' => 'Gas station sushi…', 'label' => 'hook']],
        ];
        $this->mock(AnthropicService::class, function ($mock) use ($payload) {
            $mock->shouldReceive('generateOutlierBreakdown')->once()->andReturn($payload);
        });

        app()->call([new GenerateOutlierBreakdownJob('youtube', 'abc123xyz'), 'handle']);

        $breakdown->refresh();
        $this->assertSame(OutlierBreakdown::STATUS_COMPLETED, $breakdown->status);
        $this->assertSame('Convenience-store food economics', $breakdown->payload['idea']['topic']);
    }

    public function test_job_marks_failed_when_transcript_unavailable(): void
    {
        $this->makeVideo();
        $breakdown = OutlierBreakdown::create([
            'platform' => 'youtube', 'video_id' => 'abc123xyz',
            'status' => OutlierBreakdown::STATUS_PENDING,
        ]);

        $this->mock(CaptApiService::class, function ($mock) {
            $mock->shouldReceive('getTranscript')
                ->once()
                ->andThrow(new \RuntimeException('Could not fetch a transcript for that link.'));
        });
        $this->mock(AnthropicService::class, function ($mock) {
            $mock->shouldNotReceive('generateOutlierBreakdown');
        });

        app()->call([new GenerateOutlierBreakdownJob('youtube', 'abc123xyz'), 'handle']);

        $breakdown->refresh();
        $this->assertSame(OutlierBreakdown::STATUS_FAILED, $breakdown->status);
        $this->assertStringContainsString('transcript', strtolower($breakdown->error));
    }
}
