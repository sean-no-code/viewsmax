<?php

namespace App\Listeners;

use App\Services\KlaviyoService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SubscribeToKlaviyo implements ShouldQueue
{
    use InteractsWithQueue;

    protected $klaviyoService;

    /**
     * Create the event listener.
     */
    public function __construct(KlaviyoService $klaviyoService)
    {
        $this->klaviyoService = $klaviyoService;
    }

    /**
     * Handle the event.
     */
    public function handle(Registered $event): void
    {
        if ($event->user && $event->user->marketing_consented_at) {
            Log::info("Handling Registered event for user: " . $event->user->email);
            $this->klaviyoService->subscribeProfile($event->user->email);
        } else {
            Log::info("User did not consent to marketing emails: " . ($event->user->email ?? 'unknown'));
        }
    }
}
