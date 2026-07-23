<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CreditService;
use App\Services\StripeService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessExpiredSubscriptions extends Command
{
    protected $signature = 'subscriptions:process-expired';

    protected $description = 'Process expired subscriptions and move users to free plan';

    public function handle(CreditService $creditService, StripeService $stripeService)
    {
        $processStartedAt = now();

        // env('X', 24) only falls back when the var is ABSENT — set-but-empty
        // yields '' and Carbon math on it ('' * 3600) TypeErrors, killing the
        // whole scheduled run (so expiries silently stop). Never trust
        // env-sourced config for arithmetic.
        $configured = config('services.stripe.subscription_grace_period_hours', 24);
        $gracePeriodHours = is_numeric($configured) ? (int) $configured : 24;
        $cutoffTime = now()->subHours($gracePeriodHours);

        Log::info('Expired subscription cleanup started', [
            'started_at' => $processStartedAt->toDateTimeString(),
            'grace_period_hours' => $gracePeriodHours,
            'cutoff_time' => $cutoffTime->toDateTimeString(),
        ]);

        $users = User::whereHas('plans', function ($query) use ($cutoffTime) {
            $query->whereIn('status', ['active', 'trialing', 'cancelled'])
                  ->where('expires_at', '<', $cutoffTime);
        })->with(['plans' => function ($query) use ($cutoffTime) {
            $query->whereIn('status', ['active', 'trialing', 'cancelled'])
                  ->where('expires_at', '<', $cutoffTime);
        }])->get();

        Log::info('Expired subscription candidates fetched', ['candidate_count' => $users->count()]);

        $count = 0;
        $processedUsers = [];

        foreach ($users as $user) {
            foreach ($user->plans as $expiredPlan) {
                try {
                    $stripeSubId = $expiredPlan->pivot->stripe_subscription_id;

                    Log::info('Processing expired plan', [
                        'user_id' => $user->id,
                        'user_email' => $user->email,
                        'expired_plan_id' => $expiredPlan->id,
                        'expires_at' => $expiredPlan->expires_at,
                        'current_status' => $expiredPlan->pivot->status ?? 'unknown',
                        'stripe_sub_id' => $stripeSubId ?? 'N/A',
                    ]);

                    $stripeStatus = null;
                    $nextBillingTime = null;

                    if ($stripeSubId) {
                        try {
                            $subDetails = $stripeService->getSubscription($stripeSubId);
                            $stripeStatus = $subDetails['status'] ?? null;

                            $endTs = $subDetails['current_period_end']
                                ?? $subDetails['items']['data'][0]['current_period_end']
                                ?? null;
                            $nextBillingTime = $endTs ? Carbon::createFromTimestamp($endTs) : null;

                            Log::info('Stripe subscription verification', [
                                'user_id' => $user->id,
                                'stripe_sub_id' => $stripeSubId,
                                'stripe_status' => $stripeStatus,
                                'next_billing_time' => $nextBillingTime?->toDateTimeString(),
                            ]);
                        } catch (\Exception $e) {
                            Log::warning('Failed to verify Stripe subscription status', [
                                'user_id' => $user->id,
                                'stripe_sub_id' => $stripeSubId,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }

                    $localStatus = 'expired';
                    $shouldResetCredits = true;

                    if (in_array($stripeStatus, ['active', 'trialing'])) {
                        $localStatus = 'active';
                        $shouldResetCredits = false;

                        if ($nextBillingTime) {
                            $user->plans()->updateExistingPivot($expiredPlan->id, [
                                'expires_at' => $nextBillingTime,
                            ]);
                        }

                        Log::info('Stripe subscription is still active - corrected webhook lag', [
                            'user_id' => $user->id,
                            'stripe_sub_id' => $stripeSubId,
                            'new_expires_at' => $nextBillingTime?->toDateTimeString(),
                        ]);
                    } elseif (in_array($stripeStatus, ['past_due', 'unpaid', 'incomplete'])) {
                        $localStatus = 'expired';
                        $shouldResetCredits = true;

                        Log::info('Stripe subscription unpaid/past_due - treating as expired', [
                            'user_id' => $user->id,
                            'stripe_sub_id' => $stripeSubId,
                            'stripe_status' => $stripeStatus,
                        ]);
                    } elseif (in_array($stripeStatus, ['canceled', 'incomplete_expired'])) {
                        $localStatus = 'expired';
                        $shouldResetCredits = true;

                        Log::info('Stripe subscription confirmed terminated', [
                            'user_id' => $user->id,
                            'stripe_sub_id' => $stripeSubId,
                            'stripe_status' => $stripeStatus,
                        ]);
                    }

                    $user->plans()->updateExistingPivot($expiredPlan->id, [
                        'status' => $localStatus,
                    ]);

                    $targetCredits = 0;
                    if ($shouldResetCredits) {
                        $resetSuccess = $creditService->resetBalance($user, $targetCredits);
                        if (!$resetSuccess) {
                            Log::error('Failed to reset balance for expired/suspended user', [
                                'user_id' => $user->id,
                                'target_credits' => $targetCredits,
                                'stripe_status' => $stripeStatus,
                            ]);
                            continue;
                        }
                    }

                    Log::info('Cleanup: Expired subscription processed', [
                        'user_id' => $user->id,
                        'user_email' => $user->email,
                        'expired_plan_id' => $expiredPlan->id,
                        'stripe_sub_id' => $stripeSubId ?? 'N/A',
                        'credits_reset_to' => $targetCredits,
                        'stripe_status' => $stripeStatus,
                        'local_status_set_to' => $localStatus,
                        'credits_reset' => $shouldResetCredits,
                        'processed_at' => now()->toDateTimeString(),
                    ]);

                    $processedUsers[] = [
                        'user_id' => $user->id,
                        'user_email' => $user->email,
                        'expired_plan_id' => $expiredPlan->id,
                        'stripe_sub_id' => $stripeSubId ?? 'N/A',
                        'stripe_status' => $stripeStatus,
                        'local_status' => $localStatus,
                        'processed_at' => now()->toDateTimeString(),
                    ];

                    $count++;

                } catch (\Exception $e) {
                    Log::error('Cleanup Expired Subscription Error', [
                        'user_id' => $user->id,
                        'user_email' => $user->email,
                        'expired_plan_id' => $expiredPlan->id ?? 'unknown',
                        'error' => $e->getMessage(),
                        'trace_id' => (string) Str::uuid(),
                    ]);
                }
            }
        }

        $processEndedAt = now();

        Log::info('Expired subscription cleanup completed', [
            'processed_count' => $count,
            'started_at' => $processStartedAt->toDateTimeString(),
            'ended_at' => $processEndedAt->toDateTimeString(),
            'duration_seconds' => $processStartedAt->diffInSeconds($processEndedAt),
            'processed_users' => $processedUsers,
        ]);

        $this->info("Processed {$count} expired subscriptions at {$processEndedAt->toDateTimeString()}");

        if (!empty($processedUsers)) {
            $this->info('Processed users: ' . json_encode($processedUsers));
        } else {
            $this->info('Processed users: none');
        }
    }
}
