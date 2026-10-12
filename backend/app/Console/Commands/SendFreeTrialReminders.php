<?php

namespace App\Console\Commands;

use App\Mail\FreeTrialEndingMail;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Card-free signups get User::CARD_FREE_DAYS of access (promo_expires_at).
 * A day before that closes, tell them once so the lock-out isn't a surprise.
 * See config/free_trial.php.
 */
class SendFreeTrialReminders extends Command
{
    protected $signature = 'subscriptions:send-free-trial-reminders';

    protected $description = 'Email card-free users ~24h before their free trial window closes.';

    public function handle(): int
    {
        if (! config('free_trial.reminder.enabled')) {
            $this->info('Free trial reminders are disabled (FREE_TRIAL_REMINDER_ENABLED).');

            return self::SUCCESS;
        }

        $hoursBefore = (int) config('free_trial.reminder.hours_before', 24);
        $billingUrl = rtrim((string) config('app.frontend_url'), '/').'/dashboard/billing';

        // Verified users whose window closes within the reminder window, not
        // yet reminded, and with no live plan: a card added during the window
        // makes a trialing Stripe subscription, which gets the "card about to
        // be charged" reminder (SendTrialEndingReminders) instead.
        $users = User::query()
            ->whereNotNull('email_verified_at')
            ->whereNull('free_trial_reminder_sent_at')
            ->where('promo_expires_at', '>', now())
            ->where('promo_expires_at', '<=', now()->addHours($hoursBefore))
            ->whereDoesntHave('plans', fn ($q) => $q->whereIn('user_plans.status', User::ACTIVE_SUBSCRIPTION_STATUSES))
            ->get();

        $sent = 0;

        foreach ($users as $user) {
            try {
                Mail::to($user->email)->send(new FreeTrialEndingMail(
                    name: $user->name ?: 'there',
                    endsAt: $user->promo_expires_at->format('F j, Y \a\t g:ia T'),
                    billingUrl: $billingUrl,
                ));

                // Stamp only after a successful send so a failure is retried next run.
                $user->forceFill(['free_trial_reminder_sent_at' => now()])->save();
                $sent++;

                Log::channel('free_trial')->info('Free trial reminder sent', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'promo_expires_at' => $user->promo_expires_at->toIso8601String(),
                ]);
            } catch (\Throwable $e) {
                Log::channel('free_trial')->error('Free trial reminder failed to send', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'exception' => get_class($e),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::channel('free_trial')->info('Free trial reminder run complete', [
            'candidates' => $users->count(),
            'sent' => $sent,
            'hours_before' => $hoursBefore,
        ]);

        $this->info("Free trial reminders: sent {$sent}/{$users->count()}.");

        return self::SUCCESS;
    }
}
