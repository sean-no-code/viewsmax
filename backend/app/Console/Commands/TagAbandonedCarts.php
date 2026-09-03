<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\KitService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TagAbandonedCarts extends Command
{
    protected $signature = 'kit:tag-abandoned-carts';

    protected $description = 'Tag users who signed up but never started a subscription (abandoned cart) in Kit.';

    public function handle(KitService $kitService): int
    {
        // Kit is production-only by default; skip elsewhere (mirrors the listener gate).
        $enabled = config('services.kit.enabled');
        if ($enabled === null) {
            $enabled = app()->isProduction();
        }
        if (! $enabled) {
            $this->info('Kit disabled in '.app()->environment().'; skipping abandoned-cart tagging.');

            return self::SUCCESS;
        }

        $tag = config('services.kit.abandoned_cart_tag');
        $minAge = (int) config('services.kit.abandoned_cart_min_age_hours', 1);
        $window = (int) config('services.kit.abandoned_cart_window_hours', 3);

        // This command runs every 3h (bootstrap/app.php withSchedule). The created_at
        // slice below spans exactly ONE schedule interval, so consecutive runs tile the
        // timeline and each user is tagged once — no overlap / re-tagging, and no DB flag.
        // INVARIANT: abandoned_cart_window_hours MUST equal the schedule cadence
        // (everyThreeHours). No-flag trade-off: if a run is skipped (downtime /
        // withoutOverlapping lock), that slice's users are missed and are not retried later.
        // Consent is intentionally NOT checked — abandoned-cart emails go to non-consenters too.
        $users = User::query()
            ->neverSubscribed()                                              // never started a subscription
            ->where('created_at', '<=', now()->subHours($minAge))            // ≥ min age (not still registering)
            ->where('created_at', '>', now()->subHours($minAge + $window))   // within the one interval before that (→ 1–4h ago)
            ->get();

        $tagged = 0;
        foreach ($users as $user) {
            if ($kitService->subscribe($user->email, $user->name, $tag)) {
                $tagged++;
            }
        }

        Log::info('Abandoned-cart tagging run complete', [
            'candidates' => $users->count(),
            'tagged' => $tagged,
            'window_hours' => $window,
            'min_age_hours' => $minAge,
        ]);

        $this->info("Abandoned-cart: tagged {$tagged}/{$users->count()} users with \"{$tag}\".");

        return self::SUCCESS;
    }
}
