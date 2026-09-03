<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\KitService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Manually submit abandoned-cart users to Kit with the abandoned-cart tag. Unlike
 * the scheduled kit:tag-abandoned-carts, this has NO 1–4h window — use it to
 * recover users who aged past the window (the no-flag trade-off) or to backfill.
 * Kit tagging is idempotent, so re-running is safe.
 */
class SubmitAbandonedCarts extends Command
{
    protected $signature = 'kit:submit-abandoned-carts
        {--min-age-hours=1 : Ignore users younger than this (still registering)}
        {--days= : Only users who signed up within the last N days}
        {--email= : Only submit this one user}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Submit abandoned-cart users to Kit with the abandoned-cart tag (backfill; ignores the scheduled window).';

    public function handle(KitService $kitService): int
    {
        // Same production-only gate as the scheduled command; forceable with KIT_ENABLED.
        $enabled = config('services.kit.enabled');
        if ($enabled === null) {
            $enabled = app()->isProduction();
        }
        if (! $enabled) {
            $this->warn('Kit is disabled in '.app()->environment().'. Set KIT_ENABLED=true to submit. Nothing sent.');

            return self::SUCCESS;
        }

        $tag = config('services.kit.abandoned_cart_tag');
        $minAge = (int) $this->option('min-age-hours');
        $days = $this->option('days');
        $email = $this->option('email');

        $query = User::query()
            ->neverSubscribed()
            ->where('created_at', '<=', now()->subHours($minAge));

        if ($email) {
            $query->where('email', $email);
        }
        if ($days !== null && is_numeric($days)) {
            $query->where('created_at', '>=', now()->subDays((int) $days));
        }

        $users = $query->get();

        if ($users->isEmpty()) {
            $this->info('No matching abandoned-cart users.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Submit {$users->count()} user(s) to Kit with tag \"{$tag}\"?")) {
            $this->info('Aborted.');

            return self::SUCCESS;
        }

        $sent = 0;
        foreach ($users as $user) {
            if ($kitService->subscribe($user->email, $user->name, $tag)) {
                $this->line("  <info>✓</info> {$user->email}");
                $sent++;
            } else {
                $this->line("  <error>✗</error> {$user->email} (failed — see logs)");
            }
        }

        Log::info('Manual abandoned-cart Kit submit complete', [
            'candidates' => $users->count(),
            'sent' => $sent,
            'tag' => $tag,
        ]);
        $this->info("Submitted {$sent}/{$users->count()} to Kit with \"{$tag}\".");

        return self::SUCCESS;
    }
}
