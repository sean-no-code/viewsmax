<?php

namespace App\Services;

use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Log;
use Stripe\BillingPortal\Session as BillingPortalSession;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Customer;
use Stripe\Invoice;
use Stripe\PaymentMethod;
use Stripe\SetupIntent;
use Stripe\Stripe;
use Stripe\Subscription;
use Stripe\Webhook;

class StripeService
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * Retrieve a Stripe subscription with expanded fields used downstream.
     */
    public function getSubscription(string $subscriptionId): array
    {
        try {
            $subscription = Subscription::retrieve([
                'id' => $subscriptionId,
                'expand' => ['latest_invoice', 'items.data.price'],
            ]);

            return $subscription->toArray();
        } catch (Exception $e) {
            Log::error('Stripe get subscription error', [
                'error' => $e->getMessage(),
                'subscription_id' => $subscriptionId,
            ]);
            throw new Exception('Stripe get subscription error: '.$e->getMessage());
        }
    }

    /**
     * Retrieve a Stripe invoice. Used to resolve the underlying invoice from an
     * `invoice_payment.paid` event (whose object is an InvoicePayment, not the
     * invoice itself).
     */
    public function getInvoice(string $invoiceId): array
    {
        try {
            $invoice = Invoice::retrieve($invoiceId);

            return $invoice->toArray();
        } catch (Exception $e) {
            Log::error('Stripe get invoice error', [
                'error' => $e->getMessage(),
                'invoice_id' => $invoiceId,
            ]);
            throw new Exception('Stripe get invoice error: '.$e->getMessage());
        }
    }

    /**
     * Cancel a subscription. Default cancels at period end (graceful);
     * set $immediately = true to cancel now.
     */
    public function cancelSubscription(string $subscriptionId, string $reason = 'User requested cancellation', bool $immediately = false): array
    {
        Log::info('Stripe cancel subscription request', [
            'subscription_id' => $subscriptionId,
            'reason' => $reason,
            'immediately' => $immediately,
        ]);

        try {
            if ($immediately) {
                $subscription = Subscription::retrieve($subscriptionId);
                $result = $subscription->cancel([
                    'cancellation_details' => ['comment' => $reason],
                ]);
            } else {
                $result = Subscription::update($subscriptionId, [
                    'cancel_at_period_end' => true,
                    'cancellation_details' => ['comment' => $reason],
                ]);
            }

            Log::info('Stripe cancel subscription response', [
                'subscription_id' => $subscriptionId,
                'status' => $result->status,
                'cancel_at_period_end' => $result->cancel_at_period_end ?? null,
            ]);

            return $result->toArray();
        } catch (Exception $e) {
            Log::error('Stripe cancel subscription error', [
                'error' => $e->getMessage(),
                'subscription_id' => $subscriptionId,
            ]);
            throw new Exception('Stripe cancel subscription error: '.$e->getMessage());
        }
    }

    /**
     * Update (upgrade/downgrade) a subscription to a new price.
     * Uses proration so charges/refunds are handled by Stripe automatically.
     */
    public function changeSubscriptionPrice(string $subscriptionId, string $newPriceId): array
    {
        try {
            $subscription = Subscription::retrieve($subscriptionId);
            $itemId = $subscription->items->data[0]->id ?? null;

            if (! $itemId) {
                throw new Exception("Subscription {$subscriptionId} has no items");
            }

            $updated = Subscription::update($subscriptionId, [
                'cancel_at_period_end' => false,
                'proration_behavior' => 'create_prorations',
                'items' => [[
                    'id' => $itemId,
                    'price' => $newPriceId,
                ]],
            ]);

            Log::info('Stripe subscription price changed', [
                'subscription_id' => $subscriptionId,
                'new_price_id' => $newPriceId,
                'status' => $updated->status,
            ]);

            return $updated->toArray();
        } catch (Exception $e) {
            Log::error('Stripe change subscription price error', [
                'error' => $e->getMessage(),
                'subscription_id' => $subscriptionId,
                'new_price_id' => $newPriceId,
            ]);
            throw new Exception('Stripe change subscription price error: '.$e->getMessage());
        }
    }

    /**
     * Find or create a Stripe Customer for the given user.
     */
    public function findOrCreateCustomer(User $user): Customer
    {
        if (! empty($user->stripe_customer_id)) {
            try {
                return Customer::retrieve($user->stripe_customer_id);
            } catch (Exception $e) {
                Log::warning('Stripe customer retrieve failed, recreating', [
                    'user_id' => $user->id,
                    'stripe_customer_id' => $user->stripe_customer_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $customer = Customer::create([
            'email' => $user->email,
            'metadata' => ['user_id' => (string) $user->id],
        ]);

        $user->stripe_customer_id = $customer->id;
        $user->save();

        return $customer;
    }

    /**
     * Create a Checkout Session for a new subscription (signup or resubscribe).
     */
    public function createCheckoutSession(User $user, string $priceId, ?string $successUrl = null, ?string $cancelUrl = null, ?string $referralId = null): CheckoutSession
    {
        $customer = $this->findOrCreateCustomer($user);

        // Rewardful affiliate attribution: their integration reads the visitor's
        // referral UUID from client_reference_id. User matching lives in
        // metadata.user_id (the webhook reads metadata first), so the field is
        // free to carry the referral when one exists.
        $session = CheckoutSession::create([
            'mode' => 'subscription',
            'customer' => $customer->id,
            'line_items' => [[
                'price' => $priceId,
                'quantity' => 1,
            ]],
            'success_url' => $successUrl ?? config('services.stripe.success_url').'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl ?? config('services.stripe.cancel_url'),
            'client_reference_id' => $referralId ?: (string) $user->id,
            'subscription_data' => [
                'metadata' => array_filter([
                    'user_id' => (string) $user->id,
                    'referral' => $referralId,
                ]),
            ],
            'metadata' => array_filter([
                'user_id' => (string) $user->id,
                'referral' => $referralId,
            ]),
        ]);

        Log::info('Stripe checkout session created', [
            'user_id' => $user->id,
            'session_id' => $session->id,
            'price_id' => $priceId,
        ]);

        return $session;
    }

    /**
     * Create a Stripe Billing Portal session so the customer can self-serve a
     * card update / pay an open invoice (the recovery path out of past_due).
     * Recovery itself is webhook-driven (invoice_payment.paid -> active).
     */
    public function createBillingPortalSession(User $user, string $returnUrl): BillingPortalSession
    {
        $customer = $this->findOrCreateCustomer($user);

        $session = BillingPortalSession::create([
            'customer' => $customer->id,
            'return_url' => $returnUrl,
        ]);

        Log::info('Stripe billing portal session created', [
            'user_id' => $user->id,
            'session_id' => $session->id,
        ]);

        return $session;
    }

    /**
     * Create a SetupIntent so the SPA can vault a card with no charge today.
     */
    public function createSetupIntent(User $user): SetupIntent
    {
        $customer = $this->findOrCreateCustomer($user);

        return SetupIntent::create([
            'customer' => $customer->id,
            'usage' => 'off_session',
            'payment_method_types' => ['card'],
        ]);
    }

    /**
     * Attach a PaymentMethod to the user's customer and make it the default.
     */
    public function attachPaymentMethod(User $user, string $paymentMethodId): void
    {
        $customer = $this->findOrCreateCustomer($user);

        $paymentMethod = PaymentMethod::retrieve($paymentMethodId);
        $paymentMethod->attach(['customer' => $customer->id]);

        Customer::update($customer->id, [
            'invoice_settings' => ['default_payment_method' => $paymentMethodId],
        ]);
    }

    /**
     * Create a subscription on the given price with a trial (no charge today).
     */
    public function createTrialSubscription(User $user, string $priceId, int $trialDays, ?string $referralId = null): array
    {
        $customer = $this->findOrCreateCustomer($user);

        // Rewardful affiliate attribution for server-side subscriptions: the
        // referral UUID must live in the CUSTOMER's metadata before the
        // subscription exists (that's where Rewardful looks for API-created
        // subscriptions, unlike Checkout's client_reference_id).
        if ($referralId) {
            Customer::update($customer->id, [
                'metadata' => ['referral' => $referralId],
            ]);
        }

        $subscription = Subscription::create([
            'customer' => $customer->id,
            'items' => [['price' => $priceId]],
            'trial_period_days' => $trialDays,
            'metadata' => array_filter([
                'user_id' => (string) $user->id,
                'referral' => $referralId,
            ]),
            'expand' => ['latest_invoice.payment_intent', 'items.data.price'],
        ]);

        Log::info('Stripe trial subscription created', [
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'status' => $subscription->status,
        ]);

        return $subscription->toArray();
    }

    /**
     * Determine whether a Stripe subscription is currently in a trial.
     */
    public function subscriptionHasTrial(array $subscription): bool
    {
        $status = $subscription['status'] ?? null;

        if ($status === 'trialing') {
            return true;
        }

        $trialEnd = $subscription['trial_end'] ?? null;
        if ($trialEnd && $trialEnd > time()) {
            return true;
        }

        return false;
    }

    /**
     * Verify and construct a Stripe webhook event from the raw payload.
     */
    public function constructWebhookEvent(string $payload, string $sigHeader)
    {
        $secret = config('services.stripe.webhook_secret');
        if (! $secret) {
            throw new Exception('Stripe webhook secret not configured');
        }

        return Webhook::constructEvent($payload, $sigHeader, $secret);
    }
}
