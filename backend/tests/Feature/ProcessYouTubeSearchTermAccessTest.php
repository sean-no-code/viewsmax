<?php

namespace Tests\Feature;

use App\Jobs\ProcessYouTubeSearchTermJob;
use App\Models\User;
use App\Services\YouTubeSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The search-term chain re-dispatches itself per word, so a user's access can
 * lapse between dispatch and run; the job must then stop spending quota.
 */
class ProcessYouTubeSearchTermAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_with_access_is_processed(): void
    {
        $user = User::factory()->create();
        $this->mock(YouTubeSearchService::class, fn ($m) => $m->shouldReceive('fetchAndProcess')->once());

        (new ProcessYouTubeSearchTermJob('golf tips', $user))->handle(app(YouTubeSearchService::class));
    }

    public function test_a_user_without_access_is_skipped_before_any_search(): void
    {
        $user = User::factory()->expired()->create();
        $this->mock(YouTubeSearchService::class, fn ($m) => $m->shouldNotReceive('fetchAndProcess'));

        (new ProcessYouTubeSearchTermJob('golf tips', $user))->handle(app(YouTubeSearchService::class));
    }
}
