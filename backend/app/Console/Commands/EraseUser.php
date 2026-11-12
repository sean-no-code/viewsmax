<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\UserErasure;
use Illuminate\Console\Command;

/**
 * GDPR erasure for one account, by email. Prints what will go, asks, then
 * runs App\Services\UserErasure. `--dry-run` only prints. Local deletion is
 * all-or-nothing; a failed Stripe or Google step is listed and makes the exit
 * code non-zero so the request is followed up. Klaviyo/Kit refusals only show
 * as "failed" in the steps table and a line in storage/logs/user-erasure-YYYY-MM-DD.log.
 */
class EraseUser extends Command
{
    protected $signature = 'user:erase
                            {email : The account\'s email address}
                            {--dry-run : Show what would be erased and stop}
                            {--yes : Erase without asking}';

    protected $description = 'Erase a user for a GDPR request: revoke grants, delete their data, tombstone the account.';

    public function handle(UserErasure $erasure): int
    {
        $email = (string) $this->argument('email');
        $user = User::withTrashed()->byEmail($email)->first();

        if (! $user) {
            $this->error("User not found: {$email}");
            $this->printRecentErasures();

            return self::FAILURE;
        }

        if (UserErasure::isErased($user)) {
            $this->info("User {$user->id} was already erased.");

            return self::SUCCESS;
        }

        if ($user->isAdmin()) {
            $this->error("{$user->email} is an admin. Remove the admin role first.");

            return self::FAILURE;
        }

        $plan = $erasure->plan($user);
        $this->info("User: {$user->email} (id {$user->id}, signed up {$user->created_at?->toDateString()})");
        $this->printPlan($plan);

        if ($this->option('dry-run')) {
            $this->line('Dry run: nothing was changed.');

            return self::SUCCESS;
        }

        if (! $this->option('yes') && ! $this->confirm("Erase {$user->email} (id {$user->id})? This cannot be undone.", false)) {
            $this->line('Cancelled.');

            return self::SUCCESS;
        }

        $result = $erasure->erase($user);

        $this->table(['Step', 'Outcome'], collect($result['steps'])->map(fn ($o, $s) => [$s, $o])->values()->all());
        $this->info("Erased user {$user->id}: Google grants revoked {$result['google_revoked']}, "
            .array_sum($result['tables']).' rows deleted'
            .($result['local_only_platforms'] ? ', local tokens only for '.implode(', ', $result['local_only_platforms']) : '').'.');

        if ($result['failures']) {
            foreach ($result['failures'] as $failure) {
                $this->error($failure);
            }
            $this->warn('Local data is gone; finish the failed steps by hand and record it against the request.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Erasure scrubs the email, so a second run with the original address
     * finds nothing. Name the latest erasures so staff can tell "already done"
     * from "typo"; the per-step outcome is in the log by user id.
     */
    private function printRecentErasures(): void
    {
        $recent = User::onlyTrashed()
            ->where('email', 'like', 'erased+%@'.UserErasure::ERASED_EMAIL_DOMAIN)
            ->where('deleted_at', '>=', now()->subDays(7))
            ->latest('deleted_at')
            ->limit(5)
            ->get(['id', 'deleted_at']);

        if ($recent->isEmpty()) {
            return;
        }

        $this->line('An erased account no longer has its email. Erased in the last 7 days:');
        foreach ($recent as $erased) {
            $this->line("  user {$erased->id} at {$erased->deleted_at->toDateTimeString()}");
        }
        $this->line('Each one\'s step outcomes are in storage/logs/user-erasure-YYYY-MM-DD.log ("User erased", by user_id).');
    }

    private function printPlan(array $plan): void
    {
        $rows = [];
        foreach (array_filter($plan['tables']) as $table => $count) {
            $rows[] = ['rows', $table, $count];
        }
        foreach (array_filter($plan['outlier_tables']) as $table => $count) {
            $rows[] = ['rows (outlier_db)', $table, $count];
        }
        $rows[] = ['tokens', 'sanctum (login + API key)', $plan['tokens']['sanctum']];
        $rows[] = ['tokens', 'passport (Claude / ChatGPT)', $plan['tokens']['passport']];
        $rows[] = ['platforms', 'google revoke', in_array('youtube', $plan['platforms'], true) ? 'yes' : 'none'];
        if ($plan['local_only_platforms']) {
            $rows[] = ['platforms', 'local tokens only', implode(', ', $plan['local_only_platforms'])];
        }
        $rows[] = ['stripe', 'subscriptions to cancel', implode(', ', $plan['stripe_subscriptions']) ?: 'none'];
        $rows[] = ['stripe', 'customer to delete', $plan['stripe_customer_id'] ?: 'none'];
        foreach ($plan['remote'] as $service => $state) {
            $rows[] = ['remote', $service, $state];
        }
        foreach ($plan['files'] as $location) {
            $rows[] = ['files', $location, 'delete'];
        }

        $this->table(['What', 'Target', 'Count / action'], $rows);
    }
}
