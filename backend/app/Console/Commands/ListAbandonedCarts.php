<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Read-only report of abandoned-cart users (signed up, never started a
 * subscription). Unlike the scheduled kit:tag-abandoned-carts, this has no 1–4h
 * window — it lists ALL such users so you can see who's eligible for a backfill.
 */
class ListAbandonedCarts extends Command
{
    protected $signature = 'kit:list-abandoned-carts
        {--min-age-hours=1 : Ignore users younger than this (still registering)}
        {--days= : Only users who signed up within the last N days}';

    protected $description = 'List users who signed up but never started a subscription (abandoned cart).';

    public function handle(): int
    {
        $minAge = (int) $this->option('min-age-hours');
        $days = $this->option('days');

        $query = User::query()
            ->neverSubscribed()
            ->where('created_at', '<=', now()->subHours($minAge))
            ->orderByDesc('created_at');

        if ($days !== null && is_numeric($days)) {
            $query->where('created_at', '>=', now()->subDays((int) $days));
        }

        $users = $query->get(['id', 'name', 'email', 'created_at', 'marketing_consented_at']);

        if ($users->isEmpty()) {
            $this->info('No abandoned-cart users found.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Email', 'Name', 'Signed up', 'Age', 'Consented'],
            $users->map(fn (User $u) => [
                $u->id,
                $u->email,
                $u->name,
                $u->created_at->toDateTimeString(),
                $u->created_at->diffForHumans(now(), \Carbon\CarbonInterface::DIFF_ABSOLUTE),
                $u->marketing_consented_at ? 'yes' : 'no',
            ])->all()
        );
        $this->info("{$users->count()} abandoned-cart user(s). Submit them with: php artisan kit:submit-abandoned-carts");

        return self::SUCCESS;
    }
}
