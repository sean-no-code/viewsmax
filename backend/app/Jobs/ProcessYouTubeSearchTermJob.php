<?php

namespace App\Jobs;

use App\Models\SearchTerm;
use App\Models\User;
use App\Support\UserAccess;
use App\Services\YouTubeSearchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessYouTubeSearchTermJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 300; // 5 minutes

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected string $term,
        protected User $user,
        protected bool $exactMatch = false
    ) {}

    /**
     * Execute the job.
     */
    public function handle(YouTubeSearchService $searchService): void
    {
        // The chain re-dispatches itself per word, so the user's access can lapse
        // between dispatch and run: stop spending YouTube quota for them.
        if (! UserAccess::allowsBackgroundWork($this->user)) {
            Log::info("ProcessYouTubeSearchTermJob skipped: user has no access", ['term' => $this->term, 'user_id' => $this->user->id]);

            return;
        }

        Log::info("ProcessYouTubeSearchTermJob started for term: {$this->term}");

        try {
            $searchTerm = SearchTerm::firstOrCreate(['term' => $this->term]);

            $searchService->fetchAndProcess($this->term, $searchTerm, $this->user, $this->exactMatch);

            Log::info("ProcessYouTubeSearchTermJob completed for term: {$this->term}");
        } catch (\Exception $e) {
            Log::error("ProcessYouTubeSearchTermJob failed for term: {$this->term}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw $e;
        }
    }
}
