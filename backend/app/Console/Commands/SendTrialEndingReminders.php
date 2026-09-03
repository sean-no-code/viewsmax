<?php

namespace App\Console\Commands;

use App\Mail\TrialEndingMail;
use App\Models\UserPlan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendTrialEndingReminders extends Command
{
    protected $signature = 'subscriptions:send-trial-reminders';

    protected $description = 'Email trialing users ~48h before their card is charged (trial end).';

    public function handle(): int
    {
        $hoursBefore = (int) config('services.stripe.trial_reminder_hours_before', 48);
        $cutoff = now()->addHours($hoursBefore);

        // Trialing subscriptions whose end (expires_at) is within the reminder window,
        // not already ended/charged, and not yet reminded. The trial_reminder_sent_at
        // flag makes this exactly-once and resilient to a skipped scheduled run.
        $plans = UserPlan::query()
            ->where('status', 'trialing')
            ->whereNotNull('stripe_subscription_id')
            ->whereNull('trial_reminder_sent_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())        // not already ended / charged
            ->where('expires_at', '<=', $cutoff)     // within hoursBefore of ending
            ->with(['user', 'plan'])
            ->get();

        $manageUrl = rtrim((string) config('app.frontend_url'), '/').'/settings';
        $sent = 0;

        foreach ($plans as $plan) {
            $user = $plan->user;
            if (! $user || ! $user->email) {
                continue;
            }

            try {
                $amount = ($plan->plan && $plan->plan->price !== null)
                    ? $this->formatMoney((float) $plan->plan->price, $plan->plan->currency ?? 'usd')
                    : null;

                Mail::to($user->email)->send(new TrialEndingMail(
                    name: $user->name ?: 'there',
                    chargeDate: $plan->expires_at->format('F j, Y'),
                    amount: $amount,
                    manageUrl: $manageUrl,
                ));

                // Stamp only after a successful send so a failure is retried next run.
                $plan->forceFill(['trial_reminder_sent_at' => now()])->save();
                $sent++;
            } catch (\Throwable $e) {
                Log::error('Trial-ending reminder failed to send', [
                    'user_plan_id' => $plan->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Trial-ending reminder run complete', [
            'candidates' => $plans->count(),
            'sent' => $sent,
            'hours_before' => $hoursBefore,
        ]);

        $this->info("Trial reminders: sent {$sent}/{$plans->count()}.");

        return self::SUCCESS;
    }

    private function formatMoney(float $amount, string $currency): string
    {
        $symbols = ['usd' => '$', 'eur' => '€', 'gbp' => '£'];
        $symbol = $symbols[strtolower($currency)] ?? '';
        $formatted = number_format($amount, 2);

        return $symbol !== '' ? "{$symbol}{$formatted}" : strtoupper($currency)." {$formatted}";
    }
}
