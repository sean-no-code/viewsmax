<?php

namespace App\Console\Commands;

use App\Jobs\ProcessBoostCheckJob;
use App\Models\BoostCheck;
use Illuminate\Console\Command;

/**
 * Dispatches every due Boost check to the queue. Scheduled every 10 minutes —
 * checks live on a 6-hour cadence, so this granularity is plenty and keeps X
 * API traffic smooth.
 */
class RunBoostChecks extends Command
{
    protected $signature = 'boosts:run';

    protected $description = 'Dispatch due Boost checks (auto repost / auto promo like-threshold scans).';

    public function handle(): int
    {
        $due = BoostCheck::where('status', BoostCheck::STATUS_PENDING)
            ->where('next_run_at', '<=', now())
            ->pluck('id');

        foreach ($due as $id) {
            ProcessBoostCheckJob::dispatch($id);
        }

        $this->info("Dispatched {$due->count()} boost check(s).");

        return self::SUCCESS;
    }
}
