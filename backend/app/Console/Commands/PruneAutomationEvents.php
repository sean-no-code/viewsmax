<?php

namespace App\Console\Commands;

use App\Models\AutomationEvent;
use App\Services\Automations\AutomationLog;
use Illuminate\Console\Command;

/**
 * Trim the inbound webhook ledger. Runs keep working after their event is
 * pruned (automation_runs.automation_event_id is nulled by the FK).
 */
class PruneAutomationEvents extends Command
{
    protected $signature = 'automations:prune-events {--days=30 : Delete events received more than this many days ago}';

    protected $description = 'Delete old rows from the automation_events webhook ledger';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $deleted = AutomationEvent::where('received_at', '<', now()->subDays($days))->delete();

        AutomationLog::info('prune-events finished', ['days' => $days, 'deleted' => $deleted]);
        $this->info("Deleted {$deleted} automation event(s) older than {$days} day(s).");

        return self::SUCCESS;
    }
}
