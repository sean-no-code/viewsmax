<?php

namespace App\Listeners;

use App\Events\SubscriptionStarted;
use App\Services\KitService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * When a user starts a subscription: apply the converted Kit tag (consented users
 * only) and always remove the abandoned-cart tag so a paying user can never keep
 * receiving abandoned-cart emails. Replaces the old registration-time subscribe.
 */
class SyncKitOnSubscription implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(protected KitService $kitService)
    {
    }

    public function handle(SubscriptionStarted $event): void
    {
        $user = $event->user;

        if (! $user) {
            return;
        }

        // Only touch the real Kit list where the integration is enabled. Defaults to
        // production so local/dev/staging don't pollute the live newsletter; KIT_ENABLED
        // overrides on/off in any environment.
        $enabled = config('services.kit.enabled');
        if ($enabled === null) {
            $enabled = app()->isProduction();
        }

        if (! $enabled) {
            Log::info('Kit sync skipped (disabled in '.app()->environment().'): '.$user->email);

            return;
        }

        // A converting user must never keep the abandoned-cart tag — remove it
        // unconditionally (even for non-consenters), so they can't keep getting
        // abandoned-cart emails after paying. No-op if they never had it.
        $abandonedTag = config('services.kit.abandoned_cart_tag');
        if ($abandonedTag) {
            $this->kitService->removeTag($user->email, $abandonedTag);
        }

        // The converted tag itself still requires marketing consent.
        if (! $user->marketing_consented_at) {
            Log::info('User did not consent to marketing emails; abandoned tag removed, converted tag skipped: '.$user->email);

            return;
        }

        // subscribe() defaults to the configured converted tag (services.kit.tag).
        if ($this->kitService->subscribe($user->email, $user->name)) {
            Log::info('Kit subscription succeeded for '.$user->email.' (user '.$user->id.').');
        } else {
            Log::error('Kit subscription failed for '.$user->email.' (user '.$user->id.').');
        }
    }
}
