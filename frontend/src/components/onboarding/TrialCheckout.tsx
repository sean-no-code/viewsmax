import { useEffect, useMemo, useState } from "react";
import { Elements, PaymentElement, useStripe, useElements } from "@stripe/react-stripe-js";
import type { Appearance } from "@stripe/stripe-js";
import { Check, Loader2, Lock } from "lucide-react";
import { viewsMaxApi, type PlanTier } from "@/lib/api-service";
import { sortPlansByPrice, formatLimit } from "@/lib/plan-helpers";
import { isMockApi } from "@/lib/mock-api";
import { useAuth } from "@/hooks/useAuth";
import { activateSubscription, stripePromise } from "@/components/StripeTrialStep";
import { toast } from "sonner";

// Keep in sync with backend STRIPE_TRIAL_PERIOD_DAYS (default 7). The reminder
// email fires ~48h before the charge (see SendTrialEndingReminders).
const TRIAL_DAYS = 7;

type SubscribedResult = { stripe_subscription_id: string; status: string; current_period_end: string };

interface TrialCheckoutProps {
  onSubscribed: (result: SubscribedResult) => void;
}

/** Feature bullets for a plan: prefer the seeded list, else derive from limits. */
function featuresFor(plan: PlanTier): string[] {
  if (plan.features && plan.features.length) return plan.features;
  return [
    formatLimit(plan.max_channels, "channel"),
    formatLimit(plan.max_offers, "offer"),
    `${formatLimit(plan.max_posts_per_month, "post")}/mo`,
  ];
}

const fmtDate = (d: Date) => d.toLocaleDateString("en-US", { month: "short", day: "numeric" });

/**
 * Onboarding Step 2 — "Choose plan & start trial". Plan selector + what's-included
 * summary + Stripe payment, side by side (design: Trial Checkout.dc.html).
 */
export default function TrialCheckout({ onSubscribed }: TrialCheckoutProps) {
  const [plans, setPlans] = useState<PlanTier[]>([]);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      const res = await viewsMaxApi.getPlans();
      if (cancelled) return;
      if (res.success && res.data) {
        const paid = sortPlansByPrice(res.data.filter((p) => Number(p.price) > 0));
        setPlans(paid);
        setSelectedId((prev) => prev ?? paid[0]?.id ?? null);
      } else {
        toast.error(res.error || "Failed to load plans");
      }
      setLoading(false);
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  const selected = plans.find((p) => p.id === selectedId) ?? null;

  const { chargeDate, reminderDate } = useMemo(() => {
    const now = new Date();
    const charge = new Date(now);
    charge.setDate(now.getDate() + TRIAL_DAYS);
    const remind = new Date(now);
    remind.setDate(now.getDate() + TRIAL_DAYS - 2);
    return { chargeDate: fmtDate(charge), reminderDate: fmtDate(remind) };
  }, []);

  const selPrice = selected ? Math.round(Number(selected.price)) : 0;

  return (
    <div className="trial-checkout">
      <style>{`
        .trial-checkout .tc-grid { display:grid; grid-template-columns:320px 1fr 380px; gap:32px; align-items:stretch; }
        @media (max-width: 1024px){ .trial-checkout .tc-grid { grid-template-columns:1fr; } }
        .trial-checkout .tc-cta { transition: all 200ms var(--ease-out, cubic-bezier(.2,.7,.2,1)); }
        .trial-checkout .tc-cta:hover:not(:disabled) { background: var(--vm-red-hot); }
        .trial-checkout .tc-cta:active:not(:disabled) { background: var(--vm-red-deep); transform: translateY(1px); }
        .trial-checkout .tc-cta:disabled { opacity:.7; cursor:default; }
        .trial-checkout .tc-plan { transition: all 200ms var(--ease-out, cubic-bezier(.2,.7,.2,1)); }
      `}</style>

      {/* No-risk trial banner */}
      <div
        style={{
          background: "var(--volt-tint-l)",
          border: "1px solid var(--vm-volt)",
          borderRadius: "var(--r-lg)",
          padding: "14px 22px",
          display: "flex",
          alignItems: "center",
          justifyContent: "center",
          gap: 28,
          flexWrap: "wrap",
          marginBottom: 20,
        }}
      >
        <span style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 15, letterSpacing: "-.01em", color: "var(--ink-on-paper-1)" }}>
          100% no-risk free trial
        </span>
        <span style={{ fontSize: 14, color: "var(--ink-on-paper-2)" }}>
          Pay <strong style={{ color: "var(--ink-on-paper-1)" }}>nothing</strong> for the first {TRIAL_DAYS} days
        </span>
        <span style={{ fontSize: 14, color: "var(--ink-on-paper-2)" }}>
          Cancel anytime from <strong style={{ color: "var(--ink-on-paper-1)" }}>Settings</strong>
        </span>
        <span style={{ fontSize: 14, color: "var(--ink-on-paper-2)" }}>
          Email reminder <strong style={{ color: "var(--ink-on-paper-1)" }}>before you're charged</strong>
        </span>
      </div>

      <div
        className="tc-grid"
        style={{
          background: "var(--paper-0)",
          border: "1px solid var(--line-1)",
          borderRadius: "var(--r-lg)",
          padding: 32,
        }}
      >
        {/* LEFT — plan selector */}
        <div style={{ display: "flex", flexDirection: "column", gap: 12 }}>
          <div>
            <div style={{ fontFamily: "var(--font-display)", fontWeight: 700, fontSize: 19, letterSpacing: "-.01em", color: "var(--ink-on-paper-1)" }}>
              Your plan
            </div>
            <div style={{ fontSize: 13, color: "var(--ink-on-paper-3)", marginTop: 2 }}>Upgrade or downgrade anytime.</div>
          </div>

          {loading ? (
            <div style={{ display: "flex", justifyContent: "center", padding: "30px 0" }}>
              <Loader2 className="animate-spin" size={22} style={{ color: "var(--ink-on-paper-3)" }} />
            </div>
          ) : (
            plans.map((p) => {
              const on = p.id === selectedId;
              const popular = p.name?.toLowerCase() === "creator";
              return (
                <div
                  key={p.id}
                  className="tc-plan"
                  onClick={() => setSelectedId(p.id)}
                  role="button"
                  tabIndex={0}
                  onKeyDown={(e) => (e.key === "Enter" || e.key === " ") && setSelectedId(p.id)}
                  style={{
                    display: "flex",
                    alignItems: "center",
                    gap: 12,
                    padding: "14px 16px",
                    borderRadius: "var(--r-md)",
                    cursor: "pointer",
                    background: on ? "var(--paper-0)" : "var(--paper-1)",
                    border: on ? "2px solid var(--vm-red)" : "1px solid var(--line-1)",
                    boxShadow: on ? "0 0 0 3px rgba(255,31,61,.12)" : "none",
                    margin: on ? 0 : 1,
                  }}
                >
                  <div
                    style={{
                      width: 18,
                      height: 18,
                      borderRadius: 999,
                      flexShrink: 0,
                      boxSizing: "border-box",
                      background: "#fff",
                      border: on ? "6px solid var(--vm-red)" : "2px solid var(--line-2)",
                    }}
                  />
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
                      <span style={{ fontFamily: "var(--font-display)", fontWeight: 700, fontSize: 16, color: "var(--ink-on-paper-1)" }}>
                        {p.display_name}
                      </span>
                      {popular && (
                        <span
                          style={{
                            background: "var(--vm-red)",
                            color: "#fff",
                            fontFamily: "var(--font-mono)",
                            fontSize: 10,
                            fontWeight: 700,
                            letterSpacing: ".1em",
                            padding: "3px 8px",
                            borderRadius: 999,
                          }}
                        >
                          POPULAR
                        </span>
                      )}
                    </div>
                    {p.description && (
                      <div style={{ fontSize: 12.5, color: "var(--ink-on-paper-3)", marginTop: 2 }}>{p.description}</div>
                    )}
                  </div>
                  <div style={{ textAlign: "right", whiteSpace: "nowrap" }}>
                    <span style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 20, letterSpacing: "-.02em", color: "var(--ink-on-paper-1)" }}>
                      ${Math.round(Number(p.price))}
                    </span>
                    <span style={{ fontSize: 12, color: "var(--ink-on-paper-3)" }}>/mo</span>
                  </div>
                </div>
              );
            })
          )}
        </div>

        {/* MIDDLE — what's included */}
        <div
          style={{
            background: "var(--paper-1)",
            border: "1px solid var(--line-1)",
            borderRadius: "var(--r-lg)",
            padding: 26,
          }}
        >
          <div style={{ fontSize: 12, fontWeight: 700, letterSpacing: ".14em", textTransform: "uppercase", color: "var(--ink-on-paper-3)" }}>
            What's included
          </div>
          <div style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 26, letterSpacing: "-.02em", marginTop: 8, color: "var(--ink-on-paper-1)" }}>
            {selected?.display_name ?? "—"}
          </div>
          {selected?.description && (
            <div style={{ fontSize: 14, color: "var(--ink-on-paper-2)", marginTop: 4 }}>{selected.description}</div>
          )}
          <div style={{ display: "flex", flexDirection: "column", gap: 12, marginTop: 22 }}>
            {selected &&
              featuresFor(selected).map((f) => (
                <div key={f} style={{ display: "flex", alignItems: "center", gap: 10 }}>
                  <Check size={18} strokeWidth={2.5} style={{ color: "var(--vm-red)", flexShrink: 0 }} />
                  <span style={{ fontSize: 15, color: "var(--ink-on-paper-1)" }}>{f}</span>
                </div>
              ))}
          </div>
          <div style={{ borderTop: "1px solid var(--line-1)", marginTop: 24, paddingTop: 16, display: "flex", flexDirection: "column", gap: 8 }}>
            <div style={{ display: "flex", justifyContent: "space-between", fontSize: 14 }}>
              <span style={{ color: "var(--ink-on-paper-2)" }}>Due today</span>
              <span style={{ fontFamily: "var(--font-mono)", fontWeight: 700, fontSize: 15, color: "var(--ink-on-paper-1)" }}>$0.00</span>
            </div>
            <div style={{ display: "flex", justifyContent: "space-between", fontSize: 13, color: "var(--ink-on-paper-3)" }}>
              <span>Reminder email · {reminderDate}</span>
              <span>1–2 days before</span>
            </div>
            <div style={{ display: "flex", justifyContent: "space-between", fontSize: 13, color: "var(--ink-on-paper-3)" }}>
              <span>First charge · {chargeDate}</span>
              <span style={{ fontFamily: "var(--font-mono)" }}>${selPrice}/mo</span>
            </div>
          </div>
        </div>

        {/* RIGHT — payment */}
        <PaymentColumn priceId={selected?.stripe_price_id ?? undefined} chargeDate={chargeDate} onSubscribed={onSubscribed} />
      </div>
    </div>
  );
}

// ---- Payment column ------------------------------------------------------

const stripeAppearance: Appearance = {
  theme: "stripe",
  variables: {
    colorPrimary: "#FF1F3D",
    colorText: "#0A0A0C",
    colorTextPlaceholder: "#B3B3BE",
    colorDanger: "#D60B27",
    fontFamily: "'Hanken Grotesk', system-ui, sans-serif",
    borderRadius: "12px",
    spacingUnit: "4px",
  },
  rules: {
    ".Input": { border: "1px solid #D2D2CA", boxShadow: "none", padding: "11px 14px" },
    ".Input:focus": { border: "1px solid #FF1F3D", boxShadow: "0 0 0 3px rgba(255,31,61,.15)" },
    ".Label": { fontWeight: "600", color: "#45454D" },
  },
};

const paymentCardStyle: React.CSSProperties = {
  background: "var(--paper-0)",
  border: "1px solid var(--ink-on-paper-1)",
  borderRadius: "var(--r-lg)",
  boxShadow: "var(--hard)",
  padding: 26,
  display: "flex",
  flexDirection: "column",
  gap: 16,
};

interface PaymentColumnProps {
  priceId?: string;
  chargeDate: string;
  onSubscribed: (result: SubscribedResult) => void;
}

function PaymentColumn({ priceId, chargeDate, onSubscribed }: PaymentColumnProps) {
  const { user } = useAuth();
  const [clientSecret, setClientSecret] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (isMockApi()) return;
    if (!stripePromise) {
      setError("Payments are not configured. Please contact support.");
      return;
    }
    (async () => {
      const res = await viewsMaxApi.createStripeSetupIntent();
      if (res.success && res.data?.client_secret) setClientSecret(res.data.client_secret);
      else setError(res.error || "Couldn't load the payment form. Please try again.");
    })();
  }, []);

  return (
    <div style={paymentCardStyle}>
      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between" }}>
        <div style={{ fontFamily: "var(--font-display)", fontWeight: 700, fontSize: 19, letterSpacing: "-.01em", color: "var(--ink-on-paper-1)" }}>
          Payment details
        </div>
        <div style={{ display: "flex", alignItems: "center", gap: 6, fontSize: 12, color: "var(--ink-on-paper-3)" }}>
          <Lock size={13} /> Encrypted
        </div>
      </div>

      {isMockApi() ? (
        <MockPaymentForm priceId={priceId} chargeDate={chargeDate} onSubscribed={onSubscribed} />
      ) : error ? (
        <p style={{ padding: "24px 0", textAlign: "center", fontSize: 14, color: "var(--vm-red-deep)" }}>{error}</p>
      ) : !clientSecret || !stripePromise ? (
        <div style={{ display: "flex", justifyContent: "center", padding: "40px 0" }}>
          <Loader2 className="animate-spin" size={24} style={{ color: "var(--ink-on-paper-3)" }} />
        </div>
      ) : (
        <Elements stripe={stripePromise} options={{ clientSecret, appearance: stripeAppearance }}>
          <CardForm priceId={priceId} chargeDate={chargeDate} onSubscribed={onSubscribed} email={user?.email} />
        </Elements>
      )}
    </div>
  );
}

const ctaStyle: React.CSSProperties = {
  width: "100%",
  background: "var(--vm-red)",
  color: "#fff",
  border: "none",
  borderRadius: "var(--r-pill)",
  padding: "15px 24px",
  fontFamily: "var(--font-body)",
  fontWeight: 700,
  fontSize: 16,
  cursor: "pointer",
};

const reassurance = (chargeDate: string) => (
  <div style={{ fontSize: 12.5, color: "var(--ink-on-paper-3)", textAlign: "center", lineHeight: 1.5 }}>
    You won't be charged until {chargeDate}. Cancel in one click from Settings — no calls, no forms.
  </div>
);

function CardForm({ priceId, chargeDate, onSubscribed, email }: PaymentColumnProps & { email?: string }) {
  const stripe = useStripe();
  const elements = useElements();
  const [submitting, setSubmitting] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!stripe || !elements) return;
    setSubmitting(true);
    try {
      const { error, setupIntent } = await stripe.confirmSetup({ elements, redirect: "if_required" });
      if (error) {
        toast.error(error.message || "Card couldn't be saved. Please try again.");
        setSubmitting(false);
        return;
      }
      const paymentMethodId =
        typeof setupIntent?.payment_method === "string" ? setupIntent.payment_method : setupIntent?.payment_method?.id;
      if (!paymentMethodId) {
        toast.error("Couldn't read your payment method. Please try again.");
        setSubmitting(false);
        return;
      }
      const ok = await activateSubscription(paymentMethodId, onSubscribed, priceId);
      if (!ok) setSubmitting(false);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Something went wrong.");
      setSubmitting(false);
    }
  };

  return (
    <form onSubmit={handleSubmit} style={{ display: "flex", flexDirection: "column", gap: 16 }}>
      <PaymentElement
        options={{ layout: "tabs", wallets: { link: "auto" }, ...(email ? { defaultValues: { billingDetails: { email } } } : {}) }}
      />
      <button type="submit" className="tc-cta" style={ctaStyle} disabled={!stripe || submitting}>
        {submitting ? (
          <span style={{ display: "inline-flex", alignItems: "center", gap: 8, justifyContent: "center" }}>
            <Loader2 className="animate-spin" size={16} /> Starting trial…
          </span>
        ) : (
          "Start my free trial — $0 today"
        )}
      </button>
      {reassurance(chargeDate)}
    </form>
  );
}

function MockPaymentForm({ priceId, chargeDate, onSubscribed }: PaymentColumnProps) {
  const [submitting, setSubmitting] = useState(false);
  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitting(true);
    const ok = await activateSubscription("pm_mock", onSubscribed, priceId);
    if (!ok) setSubmitting(false);
  };
  return (
    <form onSubmit={handleSubmit} style={{ display: "flex", flexDirection: "column", gap: 16 }}>
      <div style={{ background: "var(--paper-1)", border: "1px solid var(--line-1)", borderRadius: "var(--r-md)", padding: 14, fontSize: 13, color: "var(--ink-on-paper-3)" }}>
        Demo mode — no real card is collected. Click below to simulate adding a card and starting the trial.
      </div>
      <button type="submit" className="tc-cta" style={ctaStyle} disabled={submitting}>
        {submitting ? (
          <span style={{ display: "inline-flex", alignItems: "center", gap: 8, justifyContent: "center" }}>
            <Loader2 className="animate-spin" size={16} /> Starting trial…
          </span>
        ) : (
          "Start my free trial — $0 today (mock)"
        )}
      </button>
      {reassurance(chargeDate)}
    </form>
  );
}
