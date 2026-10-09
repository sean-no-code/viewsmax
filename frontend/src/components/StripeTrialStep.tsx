import { useEffect, useState } from "react";
import { loadStripe } from "@stripe/stripe-js";
import {
  Elements,
  PaymentElement,
  useStripe,
  useElements,
} from "@stripe/react-stripe-js";
import { Loader2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { viewsMaxApi } from "@/lib/api-service";
import { isMockApi } from "@/lib/mock-api";
import { useAuth } from "@/hooks/useAuth";
import TrialBanner from "@/components/TrialBanner";
import { checkoutTerms, type CheckoutTerms } from "@/lib/access";
import { toast } from "sonner";

const PUBLISHABLE_KEY = import.meta.env.VITE_STRIPE_PUBLISHABLE_KEY;
// Created once at module scope. Null if the key is missing (guarded below).
// Exported so the onboarding Trial Checkout screen can reuse the same instance.
export const stripePromise = PUBLISHABLE_KEY ? loadStripe(PUBLISHABLE_KEY) : null;

interface StripeTrialStepProps {
  onSubscribed: (result: {
    stripe_subscription_id: string;
    status: string;
    current_period_end: string;
  }) => void;
  // Stripe price id of the tier the user selected; forwarded to the backend so
  // the subscription is created on the right plan (not the trial default).
  priceId?: string;
}

const fmtDate = (at: number) => new Date(at).toLocaleDateString([], { day: "numeric", month: "short" });

// Payment-step copy for each case of checkoutTerms: the card-backed trial, a
// card added during the free window ($0 until it closes), one added after it
// (charged today), or one added by a user on free credits (charged today).
export function checkoutCopy(terms: CheckoutTerms) {
  if (terms.kind === "free-credits") {
    return {
      cta: "Subscribe now",
      busy: "Subscribing…",
      lead: "Charged today.",
      note: "Your card is charged now and then monthly. Your plan's monthly credits replace any free credits left. Cancel anytime.",
      banner: null,
    };
  }
  if (terms.kind === "charge-now") {
    return {
      cta: "Subscribe now",
      busy: "Subscribing…",
      lead: "Charged today.",
      note: "Your free trial has ended, so your card is charged now and then monthly. Cancel anytime.",
      banner: null,
    };
  }
  if (terms.kind === "window") {
    const until = fmtDate(terms.chargeAt);
    return {
      cta: "Add card — $0 today",
      busy: "Saving card…",
      lead: "$0 today.",
      note: `Your free trial runs until ${until} — you won't be charged before then. Cancel anytime.`,
      banner: { title: `$0 today · free until ${until}`, text: `You won't be charged before ${until}. Cancel anytime.` },
    };
  }
  return {
    cta: "Start 7-day free trial",
    busy: "Starting trial…",
    lead: "$0 today.",
    note: "Your 7-day free trial starts now — you won't be charged until it ends. Cancel anytime.",
    banner: {},
  };
}

type CheckoutCopy = ReturnType<typeof checkoutCopy>;

// Shared: create the trial subscription, persist it in the shape useUserRole
// expects, and notify the parent. Returns true on success.
// Exported for reuse by the onboarding Trial Checkout screen.
export const activateSubscription = async (
  paymentMethodId: string,
  onSubscribed: StripeTrialStepProps["onSubscribed"],
  priceId?: string
): Promise<boolean> => {
  const result = await viewsMaxApi.createStripeSubscription(paymentMethodId, priceId);
  if (!result.success || !result.data) {
    toast.error(result.error || "Couldn't start your subscription. Please try again.");
    return false;
  }

  const sub = result.data;
  localStorage.setItem(
    "active_subscription",
    JSON.stringify({
      subscriptionId: sub.stripe_subscription_id,
      details: { status: sub.status, plan_id: sub.plan_id },
      cancelled_at: null,
      expires_at: sub.current_period_end,
      createdAt: Date.now(),
    })
  );
  window.dispatchEvent(new CustomEvent("subscriptionUpdated"));
  toast.success(sub.status === "trialing" ? "You're all set — your free trial has started!" : "You're all set — your subscription is active!");
  onSubscribed(sub);
  return true;
};

// Demo-mode card form: skips Stripe Elements entirely (no publishable key
// needed) and activates a mock subscription.
const MockCardForm = ({ onSubscribed, priceId, copy }: StripeTrialStepProps & { copy: CheckoutCopy }) => {
  const [submitting, setSubmitting] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    const ok = await activateSubscription("pm_mock", onSubscribed, priceId);
    if (!ok) setSubmitting(false);
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-6">
      <div className="rounded-md border bg-muted/40 p-4 text-sm text-muted-foreground">
        Demo mode — no real card is collected. Click below to simulate adding a
        card and starting the trial.
      </div>
      <Button type="submit" className="w-full" disabled={submitting}>
        {submitting ? (
          <>
            <Loader2 className="mr-2 h-4 w-4 animate-spin" /> {copy.busy}
          </>
        ) : (
          `${copy.cta} (mock)`
        )}
      </Button>
      <p className="text-center text-xs text-muted-foreground">
        <strong>{copy.lead}</strong> {copy.note}
      </p>
    </form>
  );
};

// Inner form — must be rendered inside <Elements> so the Stripe hooks resolve.
const CardForm = ({ onSubscribed, priceId, email, copy }: StripeTrialStepProps & { email?: string; copy: CheckoutCopy }) => {
  const stripe = useStripe();
  const elements = useElements();
  const [submitting, setSubmitting] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!stripe || !elements) return;

    setSubmitting(true);
    try {
      // Vault the card via the SetupIntent (no charge today). Stay in-app.
      const { error, setupIntent } = await stripe.confirmSetup({
        elements,
        redirect: "if_required",
      });

      if (error) {
        toast.error(error.message || "Card couldn't be saved. Please try again.");
        setSubmitting(false);
        return;
      }

      const paymentMethodId =
        typeof setupIntent?.payment_method === "string"
          ? setupIntent.payment_method
          : setupIntent?.payment_method?.id;

      if (!paymentMethodId) {
        toast.error("Couldn't read your payment method. Please try again.");
        setSubmitting(false);
        return;
      }

      // Create the trial subscription and persist it (shared with mock path).
      const ok = await activateSubscription(paymentMethodId, onSubscribed, priceId);
      if (!ok) setSubmitting(false);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Something went wrong.");
      setSubmitting(false);
    }
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-6">
      {/* wallets.link: 'auto' enables Stripe Link's one-click checkout in the
          Payment Element. Prefilling the customer email lets Link recognise
          returning customers and offer their saved card for true one-click.
          Link is controlled client-side, not by the SetupIntent's
          payment_method_types. */}
      <PaymentElement
        options={{
          layout: "tabs",
          wallets: { link: "auto" },
          ...(email ? { defaultValues: { billingDetails: { email } } } : {}),
        }}
      />
      <Button type="submit" className="w-full" disabled={!stripe || submitting}>
        {submitting ? (
          <>
            <Loader2 className="mr-2 h-4 w-4 animate-spin" /> {copy.busy}
          </>
        ) : (
          copy.cta
        )}
      </Button>
      <p className="text-center text-xs text-muted-foreground">
        <strong>{copy.lead}</strong> {copy.note}
      </p>
    </form>
  );
};

const StripeTrialStep = ({ onSubscribed, priceId }: StripeTrialStepProps) => {
  const { user } = useAuth();
  const [clientSecret, setClientSecret] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const copy = checkoutCopy(checkoutTerms(user));

  useEffect(() => {
    if (isMockApi()) return; // mock path doesn't need a SetupIntent
    if (!stripePromise) {
      setError("Payments are not configured. Please contact support.");
      return;
    }
    (async () => {
      const result = await viewsMaxApi.createStripeSetupIntent();
      if (result.success && result.data?.client_secret) {
        setClientSecret(result.data.client_secret);
      } else {
        setError(result.error || "Couldn't load the payment form. Please try again.");
      }
    })();
  }, []);

  return (
    <Card>
      <CardHeader>
        <CardTitle>Add your payment details</CardTitle>
        <CardDescription>
          {copy.lead} {copy.note}
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-6">
        {copy.banner && <TrialBanner {...copy.banner} />}
        {isMockApi() ? (
          <MockCardForm onSubscribed={onSubscribed} priceId={priceId} copy={copy} />
        ) : error ? (
          <p className="py-6 text-center text-sm text-destructive">{error}</p>
        ) : !clientSecret || !stripePromise ? (
          <div className="flex items-center justify-center py-10">
            <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
          </div>
        ) : (
          <Elements stripe={stripePromise} options={{ clientSecret }}>
            <CardForm onSubscribed={onSubscribed} priceId={priceId} email={user?.email} copy={copy} />
          </Elements>
        )}
      </CardContent>
    </Card>
  );
};

export default StripeTrialStep;
