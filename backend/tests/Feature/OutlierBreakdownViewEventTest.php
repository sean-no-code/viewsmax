<?php

namespace Tests\Feature;

use App\Models\OutlierBreakdown;
use App\Models\User;
use App\Models\UserEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutlierBreakdownViewEventTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private function authHeaders(): array
    {
        $this->user = User::factory()->create();
        $token = $this->postJson('/api/login', ['email' => $this->user->email, 'password' => 'password'])
            ->json('data.token');

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    private function makeBreakdown(string $status): void
    {
        OutlierBreakdown::create([
            'platform' => 'youtube',
            'video_id' => 'abc123xyz',
            'status' => $status,
            'payload' => $status === OutlierBreakdown::STATUS_COMPLETED ? ['hook' => 'x'] : null,
        ]);
    }

    public function test_viewing_a_completed_breakdown_records_one_event(): void
    {
        $headers = $this->authHeaders();
        $this->makeBreakdown(OutlierBreakdown::STATUS_COMPLETED);

        $this->withHeaders($headers)->getJson('/api/outliers/youtube/abc123xyz/breakdown')->assertOk();
        $this->withHeaders($headers)->getJson('/api/outliers/youtube/abc123xyz/breakdown')->assertOk();

        $events = UserEvent::where('user_id', $this->user->id)->get();

        $this->assertCount(1, $events);
        $this->assertSame(UserEvent::OUTLIER_BREAKDOWN_VIEWED, $events[0]->event_name);
        $this->assertSame(['platform' => 'youtube', 'video_id' => 'abc123xyz'], $events[0]->metadata);
    }

    public function test_a_later_visit_records_again(): void
    {
        $headers = $this->authHeaders();
        $this->makeBreakdown(OutlierBreakdown::STATUS_COMPLETED);

        $this->withHeaders($headers)->getJson('/api/outliers/youtube/abc123xyz/breakdown')->assertOk();
        $this->travel(11)->minutes();
        $this->withHeaders($headers)->getJson('/api/outliers/youtube/abc123xyz/breakdown')->assertOk();

        $this->assertSame(2, UserEvent::where('user_id', $this->user->id)->count());
    }

    public function test_pending_or_missing_breakdowns_record_nothing(): void
    {
        $headers = $this->authHeaders();

        $this->withHeaders($headers)->getJson('/api/outliers/youtube/abc123xyz/breakdown')->assertOk();
        $this->makeBreakdown(OutlierBreakdown::STATUS_PENDING);
        $this->withHeaders($headers)->getJson('/api/outliers/youtube/abc123xyz/breakdown')->assertOk();

        $this->assertSame(0, UserEvent::count());
    }
}
