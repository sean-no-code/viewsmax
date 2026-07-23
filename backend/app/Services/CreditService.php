<?php

namespace App\Services;

use App\Models\User;
use App\Models\Plan;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class CreditService
{
    /**
     * Check if the user has enough credits for the required amount.
     *
     * @param User $user
     * @param int $requiredCredits
     * @return bool
     */
    public function checkCredits(User $user, int $requiredCredits): bool
    {
        return $user->balanceInt >= $requiredCredits;
    }

    /**
     * Deduct credits for an operation.
     *
     * @param User $user
     * @param string $itemType  The type of operation (thumbnail, title, script, review)
     * @param int $amount
     * @return void
     */
    public function deductCreditsForOperation(User $user, string $itemType, int $amount = 1): void
    {
        $cost = $this->getCost($itemType) * $amount;
        $description = "Deducted credits for {$itemType} operation. Amount: {$amount}";

        $this->deductCredits($user, $cost, $description);
    }


    /**
     * Deduct credits from the user's wallet.
     *
     * @param User $user
     * @param int $amount
     * @param string $description
     * @param int|null $generatedItemId Optional ID of the generated item (thumbnail, script, etc.)
     * @return void
     * @throws \Bavix\Wallet\Exceptions\BalanceIsEmpty
     * @throws \Bavix\Wallet\Exceptions\InsufficientFunds
     */
    public function deductCredits(User $user, int $amount, string $description, ?int $generatedItemId = null): void
    {
        $fullDescription = $description;
        if ($generatedItemId) {
            $fullDescription .= " (ID: {$generatedItemId})";
        }

        Log::info("Deducting {$amount} credits from user {$user->id} for: {$fullDescription}");

        // Using withdraw force=false to ensure exception is thrown if insufficient funds
        $user->forceWithdraw($amount, ['description' => $fullDescription]);
    }

    /**
     * Add refund credits to the user's wallet.
     *
     * @param User $user
     * @param string $itemType
     * @return void
     */
    public function addRefundCreditsForOperation(User $user, string $itemType): void
    {
        $cost = $this->getCost($itemType);

        $this->addCredits($user, $cost, "Refund: {$itemType} job failed", true);
    }

     /**
     * Allocate credits with custom metadata (for backward compatibility and future extensions).
     *
     * @param User $user
     * @param int $amount
     * @param string $description
     * @param bool $isRefund
     * @param array $additionalMeta
     * @return void
     */
    public function addCredits(User $user, int $amount, string $description, ?bool $isRefund = false, array $additionalMeta = []): void
    {
        Log::info("Adding {$amount} credits to user {$user->id} for: {$description}");

        $meta = array_merge([
            'description' => $description,
            'type' => $isRefund ? 'refund' : 'credit'
        ], $additionalMeta);

        $user->deposit($amount, $meta);
    }

    /**
     * Get the remaining credits for the user.
     *
     * @param User $user
     * @return int
     */
    public function getRemainingCredits(User $user): int
    {
        // Refresh the user instance to get the latest balance if needed, 
        // but typically the balance attribute is up to date or lazy loaded.
        return $user->balanceInt;
    }

    /**
     * Check if a Stripe transaction (invoice/synthetic) ID was already processed for credit allocation.
     */
    public function isTransactionIdProcessed(string $stripeTransactionId): bool
    {
        return DB::table('transactions')
            ->where('payable_type', User::class)
            ->whereRaw("meta->>'stripe_transaction_id' = ?", [$stripeTransactionId])
            ->exists();
    }

    /**
     * STRICT RESET MODEL: Allocate or reset credits with database idempotency.
     * Before allocating new credits, wipes existing balance (Use it or Lose it).
     *
     * @param User $user
     * @param int $amount
     * @param string $uniqueId - Stripe invoice ID or synthetic ID
     * @param string $subscriptionId - Stripe subscription ID for reference
     * @param string $actionType - 'subscription_credit' or 'trial_start'
     * @return bool Returns true if credits were allocated, false if already processed
     */
    public function allocateOrResetCredits(User $user, int $amount, string $uniqueId, string $subscriptionId, string $actionType = 'subscription_credit'): bool
    {
        if ($this->isTransactionIdProcessed($uniqueId)) {
            Log::info("Transaction ID {$uniqueId} already processed, skipping credit allocation", [
                'user_id' => $user->id,
                'subscription_id' => $subscriptionId,
            ]);
            return false;
        }

        $currentBalance = $user->balanceInt;

        try {
            return DB::transaction(function () use ($user, $amount, $uniqueId, $subscriptionId, $actionType, $currentBalance) {
                if ($currentBalance > 0) {
                    $user->forceWithdraw($currentBalance, [
                        'description' => 'Monthly Reset - Unused credits cleared',
                        'type' => 'reset'
                    ]);
                    Log::info("Reset user {$user->id} balance from {$currentBalance} to 0");
                }

                $meta = [
                    'stripe_transaction_id' => $uniqueId,
                    'subscription_id' => $subscriptionId,
                    'action_type' => $actionType,
                    'timestamp' => now()->toISOString(),
                    'type' => $actionType
                ];

                $user->deposit($amount, $meta);
                Log::info("Allocated {$amount} credits to user {$user->id} after reset", [
                    'unique_id' => $uniqueId,
                    'subscription_id' => $subscriptionId,
                    'action_type' => $actionType,
                    'previous_balance' => $currentBalance
                ]);

                return true;
            });
        } catch (\Exception $e) {
            Log::error("Failed atomic credit reset/allocation for user {$user->id}", [
                'error' => $e->getMessage(),
                'amount' => $amount,
                'unique_id' => $uniqueId,
                'current_balance' => $currentBalance
            ]);
            return false;
        }
    }

    /**
     * Monthly credit allowance for a plan.
     *
     * Credits aren't differentiated per tier yet (per client) — every paid plan
     * gets the same uniform amount (config: subscription_credits.default), and
     * the trial gets its own flat amount. When per-tier credits are needed, this
     * is the single place to change.
     */
    public function getSubscriptionCredits(Plan $plan, bool $isTrial = false): int
    {
        return $isTrial
            ? (int) config('credits.subscription_credits.trial')
            : (int) config('credits.subscription_credits.default');
    }

    /**
     * Get the cost for a specific operation type from config.
     *
     * @param string $type
     * @return int
     */
    public function getCost(string $type): int
    {
        return config("credits.costs.{$type}", 0);
    }

    /**
     * Reset user balance to a target amount (default 0) with atomic transaction.
     * Used for subscription expiration to transition users to free plan credits.
     *
     * @param User $user
     * @param int $targetAmount - Target balance after reset (default 0)
     * @return bool
     */
    public function resetBalance(User $user, int $targetAmount = 0): bool
    {
        $currentBalance = $user->balanceInt;

        try {
            return DB::transaction(function () use ($user, $targetAmount, $currentBalance) {
                // 1. Withdraw entire current balance (to reach 0)
                if ($currentBalance > 0) {
                    $user->forceWithdraw($currentBalance, [
                        'description' => 'Premium subscription expired - balance reset',
                        'type' => 'reset'
                    ]);
                }

                // 2. If targetAmount > 0, deposit with reason 'Free Plan Allocation'
                if ($targetAmount > 0) {
                    $user->deposit($targetAmount, [
                        'description' => 'Free Plan Allocation',
                        'type' => 'free_plan_credit'
                    ]);
                }

                // 3. Log the transition
                Log::info('Balance reset completed', [
                    'user_id' => $user->id,
                    'from_balance' => $currentBalance,
                    'to_balance' => $targetAmount,
                    'reset_type' => $targetAmount > 0 ? 'free_plan_transition' : 'complete_wipe'
                ]);

                return true;
            });
        } catch (\Exception $e) {
            Log::error('Balance reset failed', [
                'user_id' => $user->id,
                'current_balance' => $currentBalance,
                'target_amount' => $targetAmount,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }


    /**
     * Ensure subscription is attached to user safely (race condition safe).
     * Creates subscription attachment if it doesn't exist, updates expiration date.
     */
    public function ensureSubscriptionAttached(User $user, string $subscriptionId, ?string $stripePriceId = null, ?string $nextBillingDate = null): void
    {
        if ($this->isSubscriptionAttached($user, $subscriptionId)) {
            Log::info("Subscription already attached, skipping", [
                'user_id' => $user->id,
                'subscription_id' => $subscriptionId
            ]);
            return;
        }

        $plan = Plan::where('name', Plan::getDefaultPlan())->first();

        if (!$plan) {
            Log::error("Plan not found for subscription attachment (DEFAULT_PLAN missing)", [
                'stripe_price_id' => $stripePriceId,
                'subscription_id' => $subscriptionId,
                'user_id' => $user->id
            ]);
            return;
        }

        try {
            DB::transaction(function () use ($user, $subscriptionId, $stripePriceId, $nextBillingDate, $plan) {
                $activeIds = $user->plans()
                    ->wherePivotIn('status', User::ACTIVE_SUBSCRIPTION_STATUSES)
                    ->pluck('plans.id')->toArray();
                if (! empty($activeIds)) {
                    $user->plans()->updateExistingPivot($activeIds, ['status' => 'inactive', 'cancelled_at' => now()]);
                }

                $expiresAt = $nextBillingDate
                    ? Carbon::parse($nextBillingDate)
                    : now()->addMonth();

                $user->plans()->attach($plan->id, [
                    'stripe_subscription_id' => $subscriptionId,
                    'stripe_price_id' => $stripePriceId,
                    'status' => 'active',
                    'starts_at' => now(),
                    'expires_at' => $expiresAt,
                ]);

                Log::info("Subscription attached successfully", [
                    'user_id' => $user->id,
                    'subscription_id' => $subscriptionId,
                    'stripe_price_id' => $stripePriceId,
                    'local_plan_id' => $plan->id,
                    'local_plan_name' => $plan->name,
                    'expires_at' => $expiresAt->toDateTimeString(),
                ]);
            });
        } catch (\Exception $e) {
            Log::error("Failed to attach subscription", [
                'user_id' => $user->id,
                'subscription_id' => $subscriptionId,
                'stripe_price_id' => $stripePriceId,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function isSubscriptionAttached(User $user, string $subscriptionId): bool
    {
        return $user->plans()
            ->wherePivot('stripe_subscription_id', $subscriptionId)
            ->exists();
    }

    public const THUMBNAIL_GENERATION_OPERATION = 'create_thumbnail';
    public const TITLE_GENERATION_OPERATION = 'title';
    public const SCRIPT_CREATE_OPERATION = 'script_create';
    public const SCRIPT_UPDATE_OPERATION = 'script_update';
    public const FACE_SWAP_COPY_THUMBNAIL_OPERATION = 'face_swap_copy_thumbnail';
    public const REVIEW_OPERATION = 'review';
    public const IMAGE_GENERATION_OPERATION = 'image_generation';
}

