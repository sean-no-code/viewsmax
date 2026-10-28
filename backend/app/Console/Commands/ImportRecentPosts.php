<?php

namespace App\Console\Commands;

use App\Jobs\ImportRecentPostsJob;
use App\Models\SocialAccount;
use App\Services\Social\SocialProviderManager;
use Illuminate\Console\Command;

/**
 * One-off backfill: queue ImportRecentPostsJob (the on-connect pull of an
 * account's latest posts into analytics) for accounts that were connected
 * before that existed. Only connected accounts on platforms with stats
 * support are queued; the job itself re-checks both.
 */
class ImportRecentPosts extends Command
{
    protected $signature = 'posts:import-recent {--account= : Only this social account id}';

    protected $description = 'Queue the recent-posts analytics import for connected accounts (all, or --account=id).';

    public function handle(SocialProviderManager $manager): int
    {
        // Same population as the nightly analytics commands: users who may use
        // ViewsMax right now and whose plan includes analytics.
        $accounts = SocialAccount::query()
            ->where('status', SocialAccount::STATUS_CONNECTED)
            ->whereHas('user', fn ($u) => $u->withAccess()->withAnalytics())
            ->when($this->option('account'), fn ($q, $id) => $q->whereKey($id))
            ->get()
            ->filter(fn (SocialAccount $a) => $manager->supports($a->platform) && $manager->supportsStats($a->platform));

        foreach ($accounts as $account) {
            ImportRecentPostsJob::dispatch($account->id);
        }

        $this->info("Queued recent-posts import for {$accounts->count()} account(s).");

        return self::SUCCESS;
    }
}
