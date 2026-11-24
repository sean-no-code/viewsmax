<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\BillingEmails;
use App\Services\Billing\BillingLog;
use App\Support\AnalyticsAccess;
use App\Services\CreditService;
use App\Services\StripeService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StripeWebhookController extends Controller
{
    /** Stripe subscription statuses that never bill again. */
    private const DEAD_SUBSCRIPTION_STATUSES = ['canceled', 'incomplete_expired'];

    /** Webhook path, as registered in routes/api.php and in the Stripe dashboard. */
    public const PATH = '/api/webhooks/stripe';

    /**
     * Every event type handleWebhook() routes to a handler. Add the type here
     * AND to the switch (StripeWebhookSetupCheckTest pins the two together).
     */
    public const HANDLED_EVENTS = [
        'checkout.session.completed',
        'invoice.paid',
        'invoice.payment_succeeded',
        'invoice_payment.paid',
        'invoice.payment_failed',
        'payment_intent.payment_failed',
        'charge.failed',
        'customer.subscription.updated',
        'customer.subscription.deleted',
    ];

    /**
     * What the Stripe endpoint must be subscribed to for billing to work: one
     * event from each group. `stripe:check-webhooks` compares this with the
     * endpoint's enabled events on deploy and daily. Without a payment-failed
     * event and subscription.updated a failed charge leaves the customer with
     * access.
     */
    public const REQUIRED_EVENTS = [
        ['invoice.paid', 'invoice.payment_succeeded', 'invoice_payment.paid'],
        ['invoice.payment_failed', 'payment_intent.payment_failed', 'charge.failed'],
        ['customer.subscription.updated'],
        ['customer.subscription.deleted'],
        ['checkout.session.completed'],
    ];

    public function __construct(
        protected StripeService $stripeService,
        protected CreditService $creditService,
        protected BillingEmails $billingEmails,
    ) {}

    /** What the handler did, for the "Stripe webhook handled" billing log line. */
    private ?string $outcome = null;

    /** Whether the handler changed anything; false = it returned without touching the database. */
    private bool $changed = false;

    /** Extra context for the result line (the user, mostly). */
    private array $outcomeContext = [];

    /**
     * An event older than this was redelivered or re-sent: the object in it
     * may describe a state that is no longer true.
     */
    private const LATE_AFTER_SECONDS = 300;

    /** When Stripe created the event being handled (not when it was delivered). */
    private ?int $eventCreated = null;

    public function handleWebhook(Request $request)
    {
        $this->startOutcome();

        $startedAt = microtime(true);
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');

        // STRIPE_SIGNATURE_CHECK=false is a local/testing convenience only: in
        // production an unsigned webhook could grant anyone a subscription.
        $skipSignatureCheck = config('services.stripe.signature_check') === false && ! app()->isProduction();

        try {
            if ($skipSignatureCheck) {
                Log::info('Stripe webhook: Signature verification SKIPPED for testing');
                $event = json_decode($payload, true);
                if (! $event) {
                    return response()->json(['error' => 'Invalid payload'], 400);
                }
            } else {
                $event = $this->stripeService->constructWebhookEvent($payload, $sigHeader ?? '')->toArray();
            }
        } catch (\UnexpectedValueException $e) {
            // The signature is checked before the payload is read, so this
            // came from Stripe. The SDK refuses a thin event here.
            $decoded = json_decode($payload, true);
            if (is_array($decoded) && ($decoded['object'] ?? null) === 'v2.core.event') {
                return $this->refuseThinEvent($decoded);
            }

            Log::error('Stripe webhook: Invalid payload', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Invalid payload'], 400);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            Log::warning('Stripe webhook: Signature verification failed', ['error' => $e->getMessage()]);

            // No header at all is a scanner or a stray request. A header that
            // does not verify is Stripe signing with another destination's
            // secret: every event is being dropped, so that one is an error.
            if (! $sigHeader) {
                BillingLog::warning('Stripe webhook rejected: no Stripe-Signature header', ['ip' => $request->ip()]);
            } else {
                $this->alert('Stripe webhook rejected: signature does not match STRIPE_WEBHOOK_SECRET', [
                    'error' => $e->getMessage(),
                    'hint' => 'Each Stripe destination has its own signing secret; the server must hold the one of the destination that sends to this URL.',
                ]);
            }

            return response()->json(['error' => 'Invalid signature'], 400);
        } catch (\Exception $e) {
            Log::error('Stripe webhook: Error', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Internal error'], 500);
        }

        $eventType = $event['type'] ?? null;
        $this->eventCreated = isset($event['created']) ? (int) $event['created'] : null;

        if (($event['object'] ?? null) === 'v2.core.event' || ! isset($event['data']['object'])) {
            return $this->refuseThinEvent($event);
        }

        $resource = (array) $event['data']['object'];

        Log::info('Stripe webhook received', [
            'event_type' => $eventType,
            'resource_id' => $resource['id'] ?? null,
        ]);

        // One line per delivery in storage/logs/billing-*.log, with every id a
        // person might search by; the matching result line follows below.
        $context = $this->eventContext($event, $resource);
        BillingLog::info('Stripe webhook received', $context);

        try {
            switch ($eventType) {
                case 'checkout.session.completed':
                    $this->handleCheckoutCompleted($resource);
                    break;

                case 'invoice.paid':
                case 'invoice.payment_succeeded':
                    $this->handleInvoicePaid($resource);
                    break;

                // Newer Stripe accounts report a paid invoice via this event
                // (object = InvoicePayment) and may NOT send legacy invoice.paid.
                // Resolve the invoice and run the same allocation (idempotent by
                // invoice id, so this is safe even when both events fire).
                case 'invoice_payment.paid':
                    $this->handleInvoicePaymentPaid($resource);
                    break;

                case 'customer.subscription.updated':
                    $this->handleSubscriptionUpdated($resource);
                    break;

                case 'customer.subscription.deleted':
                    $this->handleSubscriptionCancelled($resource);
                    break;

                case 'invoice.payment_failed':
                    $this->handleInvoicePaymentFailed($resource);
                    break;

                // Every failed charge sends these two, with or without
                // invoice.payment_failed beside them. Resolve the invoice and
                // run the same handling (safe when all three arrive).
                case 'payment_intent.payment_failed':
                    $error = $resource['last_payment_error'] ?? [];
                    $this->handleFailedPayment($resource['id'] ?? null, $error['decline_code'] ?? $error['code'] ?? null);
                    break;

                case 'charge.failed':
                    $this->handleFailedPayment(
                        $resource['payment_intent'] ?? null,
                        $resource['outcome']['reason'] ?? $resource['failure_code'] ?? null,
                        'charge has no payment intent',
                    );
                    break;

                default:
                    Log::info('Stripe webhook: Unhandled event type', ['event_type' => $eventType]);
                    BillingLog::info('Stripe webhook ignored', $context + ['outcome' => 'event type not handled']);

                    return response()->json(['status' => 'ok'], 200);
            }
        } catch (\Exception $e) {
            Log::error('Stripe webhook handler error', [
                'event_type' => $eventType,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Warning, not error: the line above already reaches Sentry.
            BillingLog::warning('Stripe webhook failed', $context + $this->outcomeContext + [
                'error' => $e->getMessage(),
                'ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            return response()->json(['error' => 'Internal server error'], 500);
        }

        $result = $context + $this->outcomeContext + [
            'outcome' => $this->outcome ?? 'done',
            'ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
        $this->changed
            ? BillingLog::info('Stripe webhook handled', $result)
            : BillingLog::warning('Stripe webhook changed nothing', $result);

        return response()->json(['status' => 'ok'], 200);
    }

    /**
     * A thin event (an Event Destination with payload style "thin") names the
     * object but does not carry it, and its type is "v1.invoice.paid", not
     * "invoice.paid". Nothing here can use it. Answer 200 so Stripe does not
     * retry and then disable the destination, and say what to fix.
     */
    private function refuseThinEvent(array $event)
    {
        $this->alert('Stripe webhook unusable: thin event', [
            'event_id' => $event['id'] ?? null,
            'event_type' => $event['type'] ?? null,
            'hint' => 'In Stripe, create a webhook destination with payload style "Snapshot" for this URL and put its signing secret in STRIPE_WEBHOOK_SECRET.',
        ]);

        return response()->json(['status' => 'ignored'], 200);
    }

    /**
     * The ids worth searching the billing log by, for one event.
     *
     * @return array<string, mixed>
     */
    private function eventContext(array $event, array $resource): array
    {
        $kind = $resource['object'] ?? null;
        $id = $resource['id'] ?? null;
        $ref = fn ($value) => is_array($value) ? ($value['id'] ?? null) : $value;

        return array_filter([
            'event_id' => $event['id'] ?? null,
            'event_type' => $event['type'] ?? null,
            'object_id' => $id,
            'livemode' => $event['livemode'] ?? null,
            'api_version' => $event['api_version'] ?? null,
            'stripe_customer_id' => $kind === 'customer' ? $id : $ref($resource['customer'] ?? null),
            'stripe_subscription_id' => $kind === 'subscription' ? $id : $ref(
                $resource['subscription'] ?? $resource['parent']['subscription_details']['subscription'] ?? null
            ),
            'stripe_invoice_id' => $kind === 'invoice' ? $id : $ref($resource['invoice'] ?? null),
        ], fn ($v) => $v !== null);
    }

    /** One controller can serve several deliveries (tests, a console command, a long-lived worker). */
    private function startOutcome(): void
    {
        $this->outcome = null;
        $this->changed = false;
        $this->outcomeContext = [];
        $this->eventCreated = null;
    }

    /** Record what a handler did. $changed = false when it returned without writing anything. */
    private function did(string $outcome, bool $changed = true, ?User $user = null): void
    {
        $this->outcome = $outcome;
        $this->changed = $changed;
        if ($user) {
            $this->outcomeContext['user_id'] = $user->id;
        }
    }

    /** Whether this delivery is a late one (a redelivery, or re-sent by hand). */
    private function deliveredLate(): bool
    {
        return $this->eventCreated !== null && now()->timestamp - $this->eventCreated > self::LATE_AFTER_SECONDS;
    }

    /**
     * Write to this user's rows of ONE subscription. A user can hold several
     * rows of the same plan (an ended subscription beside the one that
     * replaced it), so `updateExistingPivot($planId)` would write to them all.
     */
    private function updateSubscriptionRows(User $user, string $subscriptionId, array $updates): void
    {
        DB::table('user_plans')
            ->where('user_id', $user->id)
            ->where('stripe_subscription_id', $subscriptionId)
            ->update($updates + ['updated_at' => now()]);
    }

    /**
     * A delivery problem that drops every event: an error on the billing
     * channel (so Sentry sees it) at most once an hour per message, a warning
     * in the file for the repeats.
     */
    private function alert(string $message, array $context): void
    {
        $first = true;
        try {
            $first = Cache::add('stripe-webhook-alert:'.md5($message), 1, now()->addHour());
        } catch (\Throwable) {
            // No cache, no throttle.
        }

        $first ? BillingLog::error($message, $context) : BillingLog::warning($message, $context);
    }

    /**
     * Checkout session completed = brand new subscription signup.
     * The actual credit allocation happens via invoice.paid; here we attach the subscription
     * record to the user so subsequent invoice events have something to look up.
     */
    private function handleCheckoutCompleted(array $session): void
    {
        $subscriptionId = $session['subscription'] ?? null;
        // metadata.user_id is authoritative. client_reference_id now carries
        // the Rewardful referral UUID when the signup came through an
        // affiliate link — only trust it as a user id when it's numeric
        // (sessions created before the Rewardful integration).
        $clientRef = $session['client_reference_id'] ?? null;
        $userId = $session['metadata']['user_id']
            ?? (is_numeric($clientRef) ? $clientRef : null);
        $customerId = $session['customer'] ?? null;

        if (! $subscriptionId || ! $userId) {
            Log::error('Stripe webhook: checkout.session.completed missing subscription or user', [
                'session_id' => $session['id'] ?? null,
            ]);
            $this->did('checkout session has no subscription or user', false);

            return;
        }

        $user = User::find($userId);
        if (! $user) {
            Log::error("Stripe webhook: User {$userId} not found for checkout session");
            $this->did('user not found', false);

            return;
        }

        if ($customerId && empty($user->stripe_customer_id)) {
            $user->stripe_customer_id = $customerId;
            $user->save();
        }

        // Completed checkout = the card was actually entered. The Stripe
        // customer alone doesn't prove that (it's created before the card
        // screen), so admin reporting keys off this timestamp instead.
        if (! $user->card_added_at) {
            $user->forceFill(['card_added_at' => now()])->save();
        }

        if ($this->creditService->isSubscriptionAttached($user, $subscriptionId)) {
            Log::info('Stripe webhook: Subscription already attached', [
                'user_id' => $user->id,
                'subscription_id' => $subscriptionId,
            ]);
            $this->did('subscription already attached', true, $user);

            return;
        }

        $subDetails = $this->stripeService->getSubscription($subscriptionId);
        $priceId = $subDetails['items']['data'][0]['price']['id'] ?? null;
        $nextBilling = $this->extractNextBilling($subDetails);

        $this->creditService->ensureSubscriptionAttached($user, $subscriptionId, $priceId, $nextBilling);

        // Free trial ($0) - allocate credits now; paid signups will allocate on invoice.paid
        $hasTrial = $this->stripeService->subscriptionHasTrial($subDetails);
        $latestInvoiceAmount = (float) ($subDetails['latest_invoice']['amount_paid'] ?? 0);

        if ($hasTrial && $latestInvoiceAmount === 0.0) {
            $attachedPlan = $user->plans()->wherePivot('stripe_subscription_id', $subscriptionId)->first();
            if (! $attachedPlan) {
                return;
            }

            $syntheticId = 'ACTIVATION_'.$subscriptionId;
            $credits = $this->creditService->getSubscriptionCredits($attachedPlan, true);

            $this->creditService->allocateOrResetCredits(
                $user,
                $credits,
                $syntheticId,
                $subscriptionId,
                'trial_start'
            );
        }
    }

    /**
     * Invoice paid = initial paid signup OR recurring renewal.
     */
    private function handleInvoicePaid(array $invoice): void
    {
        $subscriptionId = $invoice['subscription'] ?? $invoice['parent']['subscription_details']['subscription'] ?? null;
        $invoiceId = $invoice['id'] ?? null;
        $billingReason = $invoice['billing_reason'] ?? null;

        if (! $subscriptionId || ! $invoiceId) {
            Log::warning('Stripe webhook: invoice.paid missing subscription or invoice id', [
                'invoice_id' => $invoiceId,
                'subscription_id' => $subscriptionId,
            ]);
            $this->did('invoice has no subscription (a one-off invoice?)', false);

            return;
        }

        // Returns [user, plan name] when this invoice was processed for the
        // first time, null otherwise (already processed, or not granted).
        $firstTime = DB::transaction(function () use ($subscriptionId, $invoiceId, $invoice, $billingReason) {
            $subDetails = $this->stripeService->getSubscription($subscriptionId);

            $priceId = $subDetails['items']['data'][0]['price']['id'] ?? null;
            $nextBilling = $this->extractNextBilling($subDetails);

            // Find user: by existing attachment, then by subscription metadata, then by customer
            $user = User::whereHas('plans', function ($q) use ($subscriptionId) {
                $q->where('stripe_subscription_id', $subscriptionId);
            })->first();

            if (! $user) {
                $metaUserId = $subDetails['metadata']['user_id'] ?? null;
                if ($metaUserId) {
                    $user = User::find($metaUserId);
                }
            }

            if (! $user && ! empty($subDetails['customer'])) {
                $user = User::where('stripe_customer_id', $subDetails['customer'])->first();
            }

            if (! $user) {
                Log::error('Stripe webhook: User not found for invoice', [
                    'subscription_id' => $subscriptionId,
                    'invoice_id' => $invoiceId,
                ]);
                throw new \Exception("User not found for subscription {$subscriptionId}");
            }

            // Stripe never bills a cancelled subscription again, so a late
            // payment of its invoice cannot revive it. It buys a new
            // subscription on the same plan instead (resubscribeAfterLatePayment).
            if (in_array($subDetails['status'] ?? null, self::DEAD_SUBSCRIPTION_STATUSES, true)) {
                return $this->resubscribeAfterLatePayment($user, $subDetails, $invoice);
            }

            $this->creditService->ensureSubscriptionAttached($user, $subscriptionId, $priceId, $nextBilling);

            // Safety net for a missed checkout.session.completed: a paid
            // subscription invoice means a card exists.
            if (! $user->card_added_at) {
                $user->forceFill(['card_added_at' => now()])->save();
            }

            $targetPlan = $user->plans()->wherePivot('stripe_subscription_id', $subscriptionId)->first();
            if (! $targetPlan) {
                throw new \Exception("Failed to find attached plan for subscription {$subscriptionId}");
            }

            // Trial logic: subscription_create with $0 amount is trial; renewals (cycle) are not.
            $amountPaid = (float) ($invoice['amount_paid'] ?? 0);
            $isTrial = ($billingReason === 'subscription_create' && $amountPaid === 0.0)
                || ($subDetails['status'] ?? null) === 'trialing';

            $credits = $this->creditService->getSubscriptionCredits($targetPlan, $isTrial);
            $actionType = $isTrial ? 'trial_start' : 'subscription_credit';

            $allocated = $this->creditService->allocateOrResetCredits(
                $user,
                $credits,
                $invoiceId,
                $subscriptionId,
                $actionType
            );

            if (! $allocated) {
                // false also means the allocation itself failed. Roll back and
                // answer 500 so Stripe redelivers, instead of a 200 that says
                // "already processed" for a payment that was never applied.
                if (! $this->creditService->isTransactionIdProcessed($invoiceId)) {
                    throw new \Exception("Failed to allocate credits for invoice {$invoiceId}");
                }

                Log::info('Stripe webhook: Invoice already processed', [
                    'user_id' => $user->id,
                    'invoice_id' => $invoiceId,
                ]);

                // Applied before. If the row no longer says what Stripe says
                // now (an event that was no longer true set it back), put it
                // right: this is what a redelivery or stripe:sync-invoice repairs.
                $stripeStatus = $subDetails['status'] ?? null;
                if (in_array($stripeStatus, ['active', 'trialing'], true) && $targetPlan->pivot->status !== $stripeStatus) {
                    $this->updateSubscriptionRows($user, $subscriptionId, array_filter([
                        'status' => $stripeStatus,
                        'expires_at' => $nextBilling ? Carbon::parse($nextBilling) : null,
                    ]));
                    $this->did("invoice already processed, plan set back to {$stripeStatus}", true, $user);

                    return null;
                }

                $this->did('invoice already processed', true, $user);

                return null;
            }

            // Update expiration + (re)activate the plan
            if (! $nextBilling) {
                throw new \Exception("Stripe subscription {$subscriptionId} missing current_period_end");
            }

            $newExpiresAt = Carbon::parse($nextBilling);

            // Preserve the real Stripe lifecycle status: a trial-start invoice
            // (the $0 invoice) must keep the row 'trialing', not flip it to
            // 'active'. Renewals report 'active' and set it accordingly.
            $stripeStatus = $subDetails['status'] ?? 'active';
            $localStatus = in_array($stripeStatus, ['active', 'trialing'], true) ? $stripeStatus : 'active';

            $user->plans()
                ->wherePivotIn('status', ['active', 'trialing'])
                ->each(function ($otherPlan) use ($user) {
                    if ($otherPlan->pivot->stripe_subscription_id) {
                        return; // leave the current sub alone
                    }
                    $user->plans()->updateExistingPivot($otherPlan->id, [
                        'status' => 'inactive',
                        'cancelled_at' => now(),
                    ]);
                });

            $user->plans()
                ->wherePivot('stripe_subscription_id', $subscriptionId)
                ->updateExistingPivot($targetPlan->id, [
                    'expires_at' => $newExpiresAt,
                    'cancelled_at' => null,
                    'status' => $localStatus,
                ]);

            Log::info('Stripe webhook: Credits allocated for invoice', [
                'user_id' => $user->id,
                'subscription_id' => $subscriptionId,
                'invoice_id' => $invoiceId,
                'credits' => $credits,
                'is_trial' => $isTrial,
                'expires_at' => $newExpiresAt->toDateTimeString(),
            ]);

            $this->did($isTrial ? 'trial credits allocated, plan '.$localStatus : 'credits allocated, plan '.$localStatus, true, $user);

            return [$user, $targetPlan->display_name ?: $targetPlan->name];
        });

        // After the commit, so a rollback can never leave an email behind for
        // an invoice that a redelivery will process again. The allocation
        // above is idempotent by invoice id, so this runs once per invoice
        // even though Stripe re-delivers and sends several paid events for
        // one invoice. Never throws.
        if ($firstTime) {
            [$user, $planName] = $firstTime;

            // A new subscription makes the invoices an earlier, ended one left
            // payable obsolete: void them so the customer cannot pay twice.
            if ($billingReason === 'subscription_create') {
                $this->voidInvoicesOfEndedSubscriptions($user, $subscriptionId);
            }

            $this->billingEmails->invoicePaid($user, $planName, $invoice);
        }
    }

    /**
     * Void the open invoices of every subscription of this user that has
     * ended, other than $currentSubscriptionId. Never throws.
     */
    private function voidInvoicesOfEndedSubscriptions(User $user, string $currentSubscriptionId): void
    {
        DB::table('user_plans')
            ->where('user_id', $user->id)
            ->whereNotNull('stripe_subscription_id')
            ->where('stripe_subscription_id', '!=', $currentSubscriptionId)
            ->whereIn('status', ['expired', 'cancelled', 'past_due'])
            ->distinct()
            ->pluck('stripe_subscription_id')
            ->each(fn (string $endedSubscriptionId) => $this->stripeService->voidOpenInvoices($endedSubscriptionId));
    }

    /**
     * The invoice a cancelled subscription left unpaid was paid afterwards
     * (Stripe ends a subscription when its retries run out but keeps the
     * invoice payable). The customer paid for a period, so they get one: a new
     * Stripe subscription on the same price, renewing one period after the
     * payment, attached here as their active plan with that period's credits.
     *
     * Only for a payment that is exactly that (latePaymentRefusal). Anything
     * else grants nothing and is logged at error level for a person to look
     * at and refund. Runs inside handleInvoicePaid's transaction.
     *
     * @return array{0: User, 1: string}|null [user, plan name] when the invoice email is due
     */
    private function resubscribeAfterLatePayment(User $user, array $oldSubscription, array $invoice): ?array
    {
        $invoiceId = (string) $invoice['id'];
        $oldSubscriptionId = (string) ($oldSubscription['id'] ?? $invoice['subscription'] ?? $invoice['parent']['subscription_details']['subscription'] ?? '');
        $oldSubscription['id'] = $oldSubscriptionId;

        // A redelivery of a payment that already bought its subscription.
        if ($this->creditService->isTransactionIdProcessed($invoiceId)) {
            Log::info('Stripe webhook: Invoice already processed', ['user_id' => $user->id, 'invoice_id' => $invoiceId]);
            $this->did('invoice already processed', true, $user);

            return null;
        }

        $raise = function (string $reason) use ($user, $oldSubscription, $oldSubscriptionId, $invoice, $invoiceId): null {
            Log::error('Stripe webhook: invoice paid for a cancelled subscription; no access granted, refund by hand', [
                'user_id' => $user->id,
                'subscription_id' => $oldSubscriptionId,
                'subscription_status' => $oldSubscription['status'] ?? null,
                'invoice_id' => $invoiceId,
                'amount_paid' => (int) ($invoice['amount_paid'] ?? 0),
                'currency' => $invoice['currency'] ?? null,
                'reason' => $reason,
            ]);
            $this->did("paid invoice of a cancelled subscription, no access granted ({$reason})", false, $user);

            return null;
        };

        $priceId = $oldSubscription['items']['data'][0]['price']['id'] ?? null;
        $plan = $priceId ? Plan::where('stripe_price_id', $priceId)->first() : null;

        if ($reason = $this->latePaymentRefusal($user, $oldSubscription, $invoice, $plan)) {
            return $raise($reason);
        }

        $paidAt = (int) $invoice['status_transitions']['paid_at'];

        try {
            // Created by an earlier delivery whose local half did not commit?
            // Stripe's idempotency key only covers 24h; redeliveries run for days.
            $existing = collect($this->stripeService->listSubscriptions((string) $oldSubscription['customer']))
                ->reject(fn (array $s) => ($s['id'] ?? null) === $oldSubscriptionId);
            $newSubscription = $existing->first(
                fn (array $s) => ($s['metadata'][StripeService::LATE_PAYMENT_INVOICE] ?? null) === $invoiceId
            );

            if (! $newSubscription) {
                $live = $existing->first(fn (array $s) => ! in_array($s['status'] ?? null, ['canceled', 'incomplete', 'incomplete_expired'], true));
                if ($live) {
                    return $raise('other_stripe_subscription');
                }

                $newSubscription = $this->stripeService->resubscribeAfterLatePayment($oldSubscription, $invoiceId, $paidAt);
            }
        } catch (\Throwable $e) {
            report($e);

            return $raise('stripe_error');
        }

        $newSubscriptionId = (string) $newSubscription['id'];
        $nextBilling = $this->extractNextBilling($newSubscription);
        $expiresAt = $nextBilling
            ? Carbon::parse($nextBilling)
            : Carbon::createFromTimestamp(StripeService::onePeriodAfter(
                $paidAt,
                (string) ($oldSubscription['items']['data'][0]['price']['recurring']['interval'] ?? 'month'),
                (int) ($oldSubscription['items']['data'][0]['price']['recurring']['interval_count'] ?? 1),
            ));

        if (! $this->creditService->isSubscriptionAttached($user, $newSubscriptionId)) {
            $user->plans()->attach($plan->id, [
                'stripe_subscription_id' => $newSubscriptionId,
                'stripe_price_id' => $priceId,
                'status' => 'active',
                'starts_at' => now(),
                'expires_at' => $expiresAt,
            ]);
        }

        if (! $user->card_added_at) {
            $user->forceFill(['card_added_at' => now()])->save();
        }

        $credits = $this->creditService->getSubscriptionCredits($plan, false);
        if (! $this->creditService->allocateOrResetCredits($user, $credits, $invoiceId, $newSubscriptionId, 'subscription_credit')) {
            throw new \Exception("Failed to allocate credits for late payment {$invoiceId}");
        }

        // Any other invoice the dead subscription left open is for a period
        // the new subscription now covers. Never throws.
        $this->stripeService->voidOpenInvoices($oldSubscriptionId);

        AnalyticsAccess::backfill($user);

        Log::info('Stripe webhook: Re-subscribed after a late payment', [
            'user_id' => $user->id,
            'invoice_id' => $invoiceId,
            'old_subscription_id' => $oldSubscriptionId,
            'subscription_id' => $newSubscriptionId,
            'plan' => $plan->name,
            'expires_at' => $expiresAt->toDateTimeString(),
        ]);

        $this->did('re-subscribed after a late payment', true, $user);

        return [$user, $plan->display_name ?: $plan->name];
    }

    /**
     * Why a paid invoice of a cancelled subscription must NOT buy a new
     * subscription, or null when it may. It may only when it is one full
     * period of the subscription's own plan, paid in full after Stripe ended
     * the subscription, by a customer who has no plan now.
     */
    private function latePaymentRefusal(User $user, array $subscription, array $invoice, ?Plan $plan): ?string
    {
        // Only a canceled subscription leaves a payable invoice behind;
        // incomplete_expired never had a paid period to continue.
        if (($subscription['status'] ?? null) !== 'canceled') {
            return 'subscription_not_canceled';
        }

        // A renewal (or a trial's first charge) bills one full period at the
        // plan's price; a proration invoice from an upgrade does not.
        if (($invoice['billing_reason'] ?? null) !== 'subscription_cycle') {
            return 'not_a_renewal_invoice';
        }

        if ((int) ($invoice['amount_paid'] ?? 0) <= 0 || (int) ($invoice['amount_remaining'] ?? 0) > 0) {
            return 'not_paid_in_full';
        }

        // Paid before the subscription ended = an ordinary payment whose event
        // arrives late, for a subscription that was cancelled since.
        $paidAt = (int) ($invoice['status_transitions']['paid_at'] ?? 0);
        $endedAt = (int) ($subscription['ended_at'] ?? $subscription['canceled_at'] ?? 0);
        if (! $paidAt || ! $endedAt || $paidAt <= $endedAt) {
            return 'not_paid_after_cancellation';
        }

        if (empty($subscription['customer'])) {
            return 'no_customer';
        }

        if (! $plan) {
            return 'no_plan_for_price';
        }

        // One line, for the subscription's own price and quantity. Webhook
        // payloads follow the endpoint's API version: `pricing` is the
        // 2025-03-31+ shape of a line, `price` the older one.
        $item = $subscription['items']['data'][0] ?? [];
        $lines = $invoice['lines']['data'] ?? [];
        $line = $lines[0] ?? null;
        $linePrice = $line['pricing']['price_details']['price'] ?? $line['price'] ?? null;
        $linePriceId = is_array($linePrice) ? ($linePrice['id'] ?? null) : $linePrice;
        if (count($lines) !== 1
            || $linePriceId !== ($item['price']['id'] ?? null)
            || (int) ($line['quantity'] ?? 1) !== (int) ($item['quantity'] ?? 1)) {
            return 'invoice_does_not_match_plan';
        }

        if ($user->hasActivePlan()) {
            return 'has_live_plan';
        }

        return null;
    }

    /**
     * Apply a paid invoice fetched from Stripe, outside a webhook delivery
     * (`php artisan stripe:sync-invoice`): the same logic as invoice.paid, so
     * it is idempotent by invoice id. `applied` is false when nothing was
     * done (not paid, no subscription, a cancelled subscription that does not
     * qualify for a re-subscribe). Throws what the webhook handler throws.
     *
     * @return array{outcome: string, applied: bool, user_id?: int}
     */
    public function syncPaidInvoice(string $invoiceId): array
    {
        $invoice = $this->stripeService->getInvoice($invoiceId);
        $status = (string) ($invoice['status'] ?? 'unknown');

        if ($status !== 'paid') {
            return ['outcome' => "is {$status} in Stripe, not paid", 'applied' => false];
        }

        $this->startOutcome();
        $this->handleInvoicePaid($invoice);

        return ['outcome' => $this->outcome ?? 'done', 'applied' => $this->changed] + $this->outcomeContext;
    }

    /**
     * Apply an unpaid invoice read from Stripe, outside a webhook delivery
     * (`php artisan stripe:sync-overdue`): the same logic as
     * invoice.payment_failed, so the row goes past_due and the customer is
     * emailed once per invoice. Nothing is done for an invoice that is paid
     * or void.
     *
     * @param  array<string, mixed>  $invoice  the Stripe invoice object, as it is now
     * @return array{outcome: string, applied: bool, user_id?: int}
     */
    public function syncFailedInvoice(array $invoice): array
    {
        $this->startOutcome();
        $this->applyFailedInvoice($invoice);

        return ['outcome' => $this->outcome ?? 'done', 'applied' => $this->changed] + $this->outcomeContext;
    }

    /**
     * `invoice_payment.paid` (object = InvoicePayment) — the current-API way Stripe
     * reports a paid invoice. Resolve the underlying invoice and defer to the same
     * paid-invoice logic; the invoice-id idempotency in handleInvoicePaid makes this
     * safe to run alongside a legacy invoice.paid for the same invoice.
     */
    private function handleInvoicePaymentPaid(array $invoicePayment): void
    {
        $invoiceId = $invoicePayment['invoice']
            ?? $invoicePayment['invoice']['id']
            ?? null;

        if (! $invoiceId || ! is_string($invoiceId)) {
            Log::warning('Stripe webhook: invoice_payment.paid missing invoice id', [
                'invoice_payment_id' => $invoicePayment['id'] ?? null,
            ]);
            $this->did('invoice payment has no invoice id', false);

            return;
        }

        $invoice = $this->stripeService->getInvoice($invoiceId);
        $this->handleInvoicePaid($invoice);
    }

    /**
     * Subscription updated - handle upgrades/downgrades by updating the stored price/plan.
     */
    private function handleSubscriptionUpdated(array $subscription): void
    {
        $subscriptionId = $subscription['id'] ?? null;
        if (! $subscriptionId) {
            return;
        }

        $user = User::whereHas('plans', function ($q) use ($subscriptionId) {
            $q->where('stripe_subscription_id', $subscriptionId);
        })->first();

        if (! $user) {
            Log::info('Stripe webhook: subscription.updated for unknown subscription', [
                'subscription_id' => $subscriptionId,
            ]);
            $this->did('no user has this subscription', false);

            return;
        }

        // A late delivery carries the subscription as it was then. Applying
        // it would undo what happened since (a `past_due` snapshot arriving
        // after the customer paid), so read the subscription as it is now.
        if ($this->deliveredLate()) {
            $subscription = $this->stripeService->getSubscription($subscriptionId);
        }

        $priceId = $subscription['items']['data'][0]['price']['id'] ?? null;
        $nextBilling = $this->extractNextBilling($subscription);
        $status = $subscription['status'] ?? null;
        $cancelAtPeriodEnd = ! empty($subscription['cancel_at_period_end']);

        $updates = [];
        if ($priceId) {
            $updates['stripe_price_id'] = $priceId;
        }
        if ($nextBilling) {
            $updates['expires_at'] = Carbon::parse($nextBilling);
        }
        if ($cancelAtPeriodEnd) {
            $updates['cancelled_at'] = now();
        } elseif (in_array($status, ['active', 'trialing'], true)) {
            $updates['cancelled_at'] = null;
            $updates['status'] = $status; // 'active' or 'trialing'
        } elseif (in_array($status, ['past_due', 'unpaid'], true)) {
            $updates['status'] = 'past_due';
        } elseif (in_array($status, ['canceled', 'incomplete_expired'], true)) {
            $updates['status'] = 'cancelled';
        }

        if ($updates) {
            $this->updateSubscriptionRows($user, $subscriptionId, $updates);
        }

        // On an up/downgrade the price changes — repoint the pivot's plan_id at
        // the plan that owns the new price so plan limits resolve correctly.
        if ($priceId) {
            $matchedPlan = Plan::where('stripe_price_id', $priceId)->first();
            if ($matchedPlan) {
                DB::table('user_plans')
                    ->where('user_id', $user->id)
                    ->where('stripe_subscription_id', $subscriptionId)
                    ->update(['plan_id' => $matchedPlan->id, 'updated_at' => now()]);
            }
        }

        // A plan that includes analytics backfills the imports the user missed
        // on a lower tier (the pivot fires no model events).
        AnalyticsAccess::backfill($user);

        Log::info('Stripe webhook: Subscription updated', [
            'user_id' => $user->id,
            'subscription_id' => $subscriptionId,
            'updates' => $updates,
            'stripe_status' => $status,
        ]);
        $this->did('subscription updated'.(isset($updates['status']) ? ", status {$updates['status']}" : ''), true, $user);
    }

    private function handleSubscriptionCancelled(array $subscription): void
    {
        $subscriptionId = $subscription['id'] ?? null;
        if (! $subscriptionId) {
            return;
        }

        $user = User::whereHas('plans', function ($q) use ($subscriptionId) {
            $q->where('stripe_subscription_id', $subscriptionId);
        })->first();

        if (! $user) {
            Log::error('Stripe webhook: User not found for cancellation', [
                'subscription_id' => $subscriptionId,
            ]);
            $this->did('no user has this subscription', false);

            return;
        }

        $pivotPlan = $user->plans()
            ->wherePivot('stripe_subscription_id', $subscriptionId)
            ->first();

        if (! $pivotPlan) {
            return;
        }

        // What happens to the invoices it left unpaid depends on who ended it.
        // Stripe, because the retries ran out: the invoice stays payable, and
        // paying it later buys a new subscription (resubscribeAfterLatePayment);
        // make sure the card that pays is kept for the renewals. Anyone else
        // (the customer, support, an erasure): void them, nobody should be
        // asked to pay for a plan that was cancelled on purpose. Never throws.
        if (($subscription['cancellation_details']['reason'] ?? null) === 'payment_failed') {
            $this->stripeService->keepCardOfUnpaidInvoices($subscriptionId);
        } else {
            $this->stripeService->voidOpenInvoices($subscriptionId);
        }

        $updates = [];
        if (! $pivotPlan->pivot->cancelled_at) {
            $updates['cancelled_at'] = now();
        }
        // A past-due row has no paid period left, and the daily expiry run
        // never selects past_due, so close it here. Active/trialing rows keep
        // the graceful cancel: access until expires_at passes.
        if ($pivotPlan->pivot->status === 'past_due') {
            $updates['status'] = 'expired';
        }

        if (! $updates) {
            Log::info('Stripe webhook: Subscription already cancelled (idempotent)', [
                'user_id' => $user->id,
                'subscription_id' => $subscriptionId,
            ]);
            $this->did('subscription already cancelled', true, $user);

            return;
        }

        $this->updateSubscriptionRows($user, $subscriptionId, $updates);

        Log::info('Stripe webhook: Subscription marked cancelled', [
            'user_id' => $user->id,
            'subscription_id' => $subscriptionId,
            'updates' => array_keys($updates),
        ]);
        $this->did('subscription cancelled'.(isset($updates['status']) ? ", status {$updates['status']}" : ''), true, $user);
    }

    /**
     * Invoice payment failed - mark the subscription past_due so has_active_subscription
     * reflects the lapse (e.g. when the card declines after the trial), and email
     * the customer how to fix it.
     */
    private function handleInvoicePaymentFailed(array $invoice): void
    {
        $subscriptionId = $invoice['subscription']
            ?? $invoice['parent']['subscription_details']['subscription']
            ?? null;

        if (! $subscriptionId) {
            $this->did('invoice has no subscription (a one-off invoice?)', false);

            return;
        }

        $user = User::whereHas('plans', function ($q) use ($subscriptionId) {
            $q->where('stripe_subscription_id', $subscriptionId);
        })->first();

        if (! $user) {
            $this->did('no user has this subscription', false);

            return;
        }

        // Redelivered or re-sent after the invoice was paid and applied here:
        // the failure is history, the customer has paid.
        $invoiceId = $invoice['id'] ?? null;
        if ($invoiceId && $this->creditService->isTransactionIdProcessed($invoiceId)) {
            $this->did('invoice was paid since', false, $user);

            return;
        }

        $plans = $user->plans()->wherePivot('stripe_subscription_id', $subscriptionId)->get();
        $this->updateSubscriptionRows($user, $subscriptionId, ['status' => 'past_due']);

        Log::info('Stripe webhook: Subscription marked past_due', [
            'user_id' => $user->id,
            'subscription_id' => $subscriptionId,
        ]);
        $this->did('subscription marked past_due', true, $user);

        // The account is locked from here, so tell the customer (once per
        // invoice; never throws).
        if ($plan = $plans->first()) {
            $this->billingEmails->paymentFailed($user, $plan->display_name ?: $plan->name, $subscriptionId, $invoice);
        }
    }

    /**
     * `payment_intent.payment_failed` and `charge.failed`: a charge was
     * declined. Neither object says what it was paying, so ask Stripe for the
     * invoice of the payment intent and defer to the invoice.payment_failed
     * logic. That logic repeats safely (the status write is the same, the
     * email is once per invoice), so all three events may arrive for one
     * failure.
     *
     * The invoice is read now, not at the time of the failure: when Stripe
     * redelivers after a retry or another card has paid it, nothing is done.
     *
     * @param  mixed  $paymentIntent  the payment intent id (or the expanded object)
     * @param  ?string  $reason  why the card failed, for the result line: "insufficient_funds" says more than "card_declined"
     */
    private function handleFailedPayment(mixed $paymentIntent, ?string $reason, string $missing = 'payment intent has no id'): void
    {
        $intentId = is_array($paymentIntent) ? ($paymentIntent['id'] ?? null) : $paymentIntent;
        if (! $intentId) {
            $this->did($missing, false);

            return;
        }

        if ($reason) {
            $this->outcomeContext['payment_error'] = $reason;
        }

        $invoice = $this->stripeService->invoiceOfPaymentIntent($intentId);
        if (! $invoice) {
            $this->did('payment was not for an invoice', false);

            return;
        }

        // The event itself carries neither id; add them so the result line
        // is found by a search for the invoice or the subscription.
        $this->outcomeContext += array_filter([
            'stripe_invoice_id' => $invoice['id'] ?? null,
            'stripe_subscription_id' => $invoice['subscription']
                ?? $invoice['parent']['subscription_details']['subscription']
                ?? null,
        ]);

        $this->applyFailedInvoice($invoice);
    }

    /**
     * The failed-payment handling for an invoice read from Stripe just now
     * (not an event's snapshot): nothing when it has been paid or voided since.
     */
    private function applyFailedInvoice(array $invoice): void
    {
        if (in_array($invoice['status'] ?? null, ['paid', 'void'], true)) {
            $this->did("invoice is {$invoice['status']} in Stripe now", false);

            return;
        }

        $this->handleInvoicePaymentFailed($invoice);
    }

    /**
     * Stripe puts the next-billing timestamp either on the subscription's current_period_end
     * (legacy) or on each subscription item (new schema). Try both and return ISO8601 string.
     */
    private function extractNextBilling(array $subscription): ?string
    {
        $candidates = [
            $subscription['current_period_end'] ?? null,
            $subscription['items']['data'][0]['current_period_end'] ?? null,
            $subscription['trial_end'] ?? null,
        ];

        foreach ($candidates as $ts) {
            if ($ts) {
                return Carbon::createFromTimestamp($ts)->toIso8601String();
            }
        }

        return null;
    }
}
