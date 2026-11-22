<?php

namespace App\Services;

use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Stripe\BillingPortal\Session as BillingPortalSession;
use Stripe\Checkout\Session as CheckoutSession;
use Stripe\Customer;
use Stripe\Invoice;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\Price;
use Stripe\SetupIntent;
use Stripe\Stripe;
use Stripe\Subscription;
use Stripe\Webhook;
use Stripe\WebhookEndpoint;

class StripeService
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * A recurring price's current amount (major units) and upper-case
     * currency, for syncing plans.price from Stripe. Throws on an unknown id.
     *
     * @return array{amount: float, currency: string}
     */
    public function retrievePrice(string $priceId): array
    {
        $price = Price::retrieve($priceId);

        return [
            'amount' => (float) (((int) $price->unit_amount) / 100),
            'currency' => strtoupper((string) $price->currency),
        ];
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
     * Stripe's fixed set for cancellation_details.feedback. Stripe shows it on the
     * subscription and groups churn by it in its reports.
     */
    public const CANCELLATION_FEEDBACK = [
        'customer_service', 'low_quality', 'missing_features', 'other',
        'switched_service', 'too_complex', 'too_expensive', 'unused',
    ];

    /**
     * Cancel a subscription. Default cancels at period end (graceful);
     * set $immediately = true to cancel now. $reason is the free-text comment,
     * $feedback one of CANCELLATION_FEEDBACK.
     */
    public function cancelSubscription(string $subscriptionId, ?string $reason = null, bool $immediately = false, ?string $feedback = null): array
    {
        Log::info('Stripe cancel subscription request', [
            'subscription_id' => $subscriptionId,
            'reason' => $reason,
            'feedback' => $feedback,
            'immediately' => $immediately,
        ]);

        $details = array_filter(['comment' => $reason, 'feedback' => $feedback], fn ($v) => $v !== null && $v !== '');

        try {
            if ($immediately) {
                $subscription = Subscription::retrieve($subscriptionId);
                // Already ended: nothing to stop (Stripe refuses a second cancel).
                $result = in_array($subscription->status, ['canceled', 'incomplete_expired'], true)
                    ? $subscription
                    : $subscription->cancel($details ? ['cancellation_details' => $details] : []);
            } else {
                $result = Subscription::update($subscriptionId, array_filter([
                    'cancel_at_period_end' => true,
                    'cancellation_details' => $details ?: null,
                ]));
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
            throw new Exception('Stripe cancel subscription error: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Void every open invoice of a subscription that has ended, so a late
     * payment can no longer be taken for a period we will not serve (and
     * Stripe stops retrying the card). Never throws: each failure is reported
     * and the rest are still attempted. Returns how many were voided.
     */
    public function voidOpenInvoices(string $subscriptionId): int
    {
        $voided = 0;

        try {
            $invoices = Invoice::all(['subscription' => $subscriptionId, 'status' => 'open', 'limit' => 100]);

            foreach ($invoices->autoPagingIterator() as $invoice) {
                try {
                    $invoice->voidInvoice();
                    $voided++;
                } catch (Exception $e) {
                    Context::add(['stripe_subscription_id' => $subscriptionId, 'stripe_invoice_id' => $invoice->id]);
                    report($e);
                }
            }
        } catch (Exception $e) {
            // Listing (or fetching a later page) failed.
            Context::add(['stripe_subscription_id' => $subscriptionId]);
            report($e);
        }

        Log::info('Stripe open invoices voided', [
            'subscription_id' => $subscriptionId,
            'voided' => $voided,
        ]);

        return $voided;
    }

    /**
     * Every subscription a customer has ever had, newest first, for the
     * late-payment checks (is another one live? was one already created for
     * this invoice?). Throws on a Stripe error.
     *
     * @return list<array<string, mixed>>
     */
    public function listSubscriptions(string $customerId): array
    {
        $subscriptions = [];
        foreach (Subscription::all(['customer' => $customerId, 'status' => 'all', 'limit' => 100])->autoPagingIterator() as $subscription) {
            $subscriptions[] = $subscription->toArray();
        }

        return $subscriptions;
    }

    /** Metadata key on a subscription created for a late payment: the invoice that was paid. */
    public const LATE_PAYMENT_INVOICE = 'late_payment_invoice';

    /**
     * A customer paid the invoice a cancelled subscription left unpaid. Stripe
     * cannot revive that subscription, so start a new one on the same price
     * whose first charge is one period after the payment: the paid invoice
     * covers the period that starts now, and nothing is charged today
     * (future billing_cycle_anchor + no proration = no invoice until then).
     *
     * The card that paid becomes the default when Stripe kept it on the
     * customer (see keepCardOfUnpaidInvoices); otherwise the customer's own
     * default applies. Idempotent per invoice for 24h through Stripe's
     * idempotency key; after that the caller finds the subscription by its
     * LATE_PAYMENT_INVOICE metadata. Throws on a Stripe error.
     *
     * @param  array<string, mixed>  $oldSubscription  the cancelled subscription (items.data.price expanded)
     * @return array<string, mixed> the new subscription
     */
    public function resubscribeAfterLatePayment(array $oldSubscription, string $invoiceId, int $paidAt): array
    {
        $item = $oldSubscription['items']['data'][0] ?? [];
        $price = $item['price'] ?? [];
        $customerId = (string) ($oldSubscription['customer'] ?? '');

        $params = [
            'customer' => $customerId,
            'items' => [['price' => $price['id'], 'quantity' => (int) ($item['quantity'] ?? 1)]],
            'metadata' => array_merge((array) ($oldSubscription['metadata'] ?? []), [
                self::LATE_PAYMENT_INVOICE => $invoiceId,
                'resubscribed_from' => (string) ($oldSubscription['id'] ?? ''),
            ]),
            'billing_cycle_anchor' => self::onePeriodAfter(
                $paidAt,
                (string) ($price['recurring']['interval'] ?? 'month'),
                (int) ($price['recurring']['interval_count'] ?? 1),
            ),
            'proration_behavior' => 'none',
            'expand' => ['items.data.price'],
        ];

        if ($card = $this->cardThatPaid($invoiceId, $customerId)) {
            $params['default_payment_method'] = $card;
        }

        $subscription = Subscription::create($params, ['idempotency_key' => "resubscribe_{$invoiceId}"]);

        Log::info('Stripe subscription created after a late payment', [
            'subscription_id' => $subscription->id,
            'replaces_subscription_id' => $oldSubscription['id'] ?? null,
            'invoice_id' => $invoiceId,
            'default_payment_method' => $card,
        ]);

        return $subscription->toArray();
    }

    /**
     * $from plus one billing period. Month and year steps clamp to the last
     * day of the month (Jan 31 + 1 month = Feb 28): Stripe rejects an anchor
     * later than one period from now, which an overflow into March would be.
     */
    public static function onePeriodAfter(int $from, string $interval, int $count): int
    {
        $at = \Carbon\Carbon::createFromTimestamp($from, 'UTC');
        $count = max(1, $count);

        $next = match ($interval) {
            'day' => $at->addDays($count),
            'week' => $at->addWeeks($count),
            'year' => $at->addYearsNoOverflow($count),
            default => $at->addMonthsNoOverflow($count),
        };

        return $next->getTimestamp();
    }

    /**
     * The payment intents behind an invoice's payments. Never throws.
     *
     * @return list<array<string, mixed>>
     */
    private function invoicePaymentIntents(string $invoiceId): array
    {
        try {
            $invoice = Invoice::retrieve(['id' => $invoiceId, 'expand' => ['payments.data.payment.payment_intent']])->toArray();
        } catch (Exception $e) {
            Context::add(['stripe_invoice_id' => $invoiceId]);
            report($e);

            return [];
        }

        $intents = [];
        foreach ($invoice['payments']['data'] ?? [] as $payment) {
            $intent = $payment['payment']['payment_intent'] ?? null;
            if (is_array($intent)) {
                $intents[] = $intent;
            }
        }

        return $intents;
    }

    /**
     * The payment method that paid an invoice, when it is saved on that
     * customer (only then can a subscription use it). Null otherwise. Never throws.
     */
    private function cardThatPaid(string $invoiceId, string $customerId): ?string
    {
        try {
            foreach ($this->invoicePaymentIntents($invoiceId) as $intent) {
                if (($intent['status'] ?? null) !== 'succeeded') {
                    continue;
                }

                $method = $intent['payment_method'] ?? null;
                $methodId = is_array($method) ? ($method['id'] ?? null) : $method;
                if (! $methodId) {
                    return null;
                }

                $owner = PaymentMethod::retrieve($methodId)->customer;
                $ownerId = is_object($owner) ? $owner->id : $owner;

                return $ownerId === $customerId ? $methodId : null;
            }
        } catch (Exception $e) {
            Context::add(['stripe_invoice_id' => $invoiceId]);
            report($e);
        }

        return null;
    }

    /**
     * A subscription Stripe ended for non-payment leaves its invoice payable
     * on the hosted invoice page. That page only saves the paying card when
     * the customer has no default one; flag each unpaid invoice's payment
     * intent so the card is always kept, and the subscription started by the
     * late payment (resubscribeAfterLatePayment) can renew on it. Never throws.
     */
    public function keepCardOfUnpaidInvoices(string $subscriptionId): void
    {
        try {
            $invoices = Invoice::all(['subscription' => $subscriptionId, 'status' => 'open', 'limit' => 100]);

            foreach ($invoices->autoPagingIterator() as $invoice) {
                foreach ($this->invoicePaymentIntents($invoice->id) as $intent) {
                    // The only statuses Stripe lets the flag be set in.
                    $settable = in_array($intent['status'] ?? null, ['requires_payment_method', 'requires_confirmation', 'requires_action'], true);
                    if (! $settable || ! empty($intent['setup_future_usage'])) {
                        continue;
                    }

                    try {
                        PaymentIntent::update($intent['id'], ['setup_future_usage' => 'off_session']);
                    } catch (Exception $e) {
                        Context::add(['stripe_subscription_id' => $subscriptionId, 'stripe_invoice_id' => $invoice->id]);
                        report($e);
                    }
                }
            }
        } catch (Exception $e) {
            Context::add(['stripe_subscription_id' => $subscriptionId]);
            report($e);
        }
    }

    /**
     * Update (upgrade/downgrade) a subscription to a new price.
     * Uses proration so charges/refunds are handled by Stripe automatically.
     * cancel_at_period_end is deliberately not sent: a pending cancellation
     * survives a plan change (resuming is a separate, explicit decision).
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
     * Delete a Stripe Customer (GDPR erasure, UserErasure): removes the card
     * and email from Stripe; invoices stay for tax records. A customer Stripe
     * no longer has counts as deleted.
     */
    public function deleteCustomer(string $customerId): bool
    {
        try {
            $customer = Customer::retrieve($customerId);
            $customer->delete();
            Log::info('Stripe customer deleted', ['stripe_customer_id' => $customerId]);

            return true;
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            if ($e->getHttpStatus() === 404) {
                return true;
            }
            throw new Exception('Stripe delete customer error: '.$e->getMessage(), 0, $e);
        } catch (Exception $e) {
            throw new Exception('Stripe delete customer error: '.$e->getMessage(), 0, $e);
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
     * Create a subscription on the given price. $trial is Stripe's
     * `trial_end` (nothing is charged today, the plan price when the free
     * window closes); empty charges the plan price now. Either way a
     * declined card fails the call instead of leaving an incomplete
     * subscription behind.
     *
     * @param  array{trial_end?: int}  $trial
     */
    public function createSubscription(User $user, string $priceId, array $trial, ?string $referralId = null): array
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

        $subscription = Subscription::create($this->subscriptionParams($user, $customer->id, $priceId, $trial, $referralId));

        Log::info('Stripe subscription created', [
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'status' => $subscription->status,
        ]);

        return $subscription->toArray();
    }

    /**
     * Parameters for Subscription::create. A trial (free window still open)
     * charges nothing today; without one, `error_if_incomplete` makes a
     * declined plan price fail the request.
     *
     * @param  array{trial_end?: int}  $trial
     */
    public function subscriptionParams(User $user, string $customerId, string $priceId, array $trial, ?string $referralId = null): array
    {
        return [
            'customer' => $customerId,
            'items' => [['price' => $priceId]],
            ...$trial,
            'payment_behavior' => 'error_if_incomplete',
            'metadata' => array_filter([
                'user_id' => (string) $user->id,
                'referral' => $referralId,
            ]),
            'expand' => ['latest_invoice.payment_intent', 'items.data.price'],
        ];
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
     * The account's webhook endpoints as the Webhook Endpoints API (v1) lists
     * them, for stripe:check-webhooks. Throws when Stripe refuses (e.g. a
     * restricted key without "Webhook Endpoints: Read").
     *
     * @return list<array{id: string, url: string, status: string, enabled_events: list<string>, payload: string}>
     */
    public function webhookEndpoints(): array
    {
        $endpoints = [];
        foreach (WebhookEndpoint::all(['limit' => 100])->autoPagingIterator() as $endpoint) {
            $endpoints[] = [
                'id' => (string) $endpoint->id,
                'url' => (string) $endpoint->url,
                'status' => (string) $endpoint->status,
                'enabled_events' => array_values((array) $endpoint->enabled_events),
                'payload' => 'snapshot',
            ];
        }

        return $endpoints;
    }

    /**
     * The account's webhook destinations as the Event Destinations API (v2)
     * lists them, in the same shape as webhookEndpoints(). A webhook created
     * in the current Stripe dashboard is an event destination, and the v1 list
     * above does not always include it. `payload` is `snapshot` (the full
     * object, what StripeWebhookController reads) or `thin`. EventBridge and
     * Event Grid destinations are left out. Throws when Stripe refuses.
     *
     * @return list<array{id: string, url: string, status: string, enabled_events: list<string>, payload: string}>
     */
    public function eventDestinations(): array
    {
        $client = new \Stripe\StripeClient(['api_key' => (string) config('services.stripe.secret')]);
        $destinations = [];

        foreach ($client->v2->core->eventDestinations->all(['include' => ['webhook_endpoint.url'], 'limit' => 100])->autoPagingIterator() as $destination) {
            if ($destination->type !== 'webhook_endpoint') {
                continue;
            }

            $destinations[] = [
                'id' => (string) $destination->id,
                'url' => (string) ($destination->webhook_endpoint->url ?? ''),
                'status' => (string) $destination->status,
                'enabled_events' => array_values((array) $destination->enabled_events),
                'payload' => (string) ($destination->event_payload ?? 'snapshot'),
            ];
        }

        return $destinations;
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
