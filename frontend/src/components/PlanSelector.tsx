import { useEffect, useState } from "react";
import { Check, Loader2 } from "lucide-react";
import { viewsMaxApi, type PlanTier } from "@/lib/api-service";
import { sortPlansByPrice, formatLimit } from "@/lib/plan-helpers";
import { toast } from "sonner";

interface PlanSelectorProps {
  /** id of the currently selected plan, or null. */
  value: number | null;
  /** Called with the chosen plan when a card is picked. */
  onChange: (plan: PlanTier) => void;
}

/** The feature bullets to show on a card: prefer the seeded list, else derive from limits. */
function featuresFor(plan: PlanTier): string[] {
  if (plan.features && plan.features.length) return plan.features;
  return [
    formatLimit(plan.max_channels, "channel"),
    formatLimit(plan.max_offers, "offer"),
    `${formatLimit(plan.max_posts_per_month, "post")}/mo`,
  ];
}

/**
 * Compact, selectable list of the paid tiers (cheapest → most expensive),
 * styled to match the landing page pricing cards. Used in the onboarding flow
 * so the user picks a plan before payment.
 */
export default function PlanSelector({ value, onChange }: PlanSelectorProps) {
  const [plans, setPlans] = useState<PlanTier[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      const res = await viewsMaxApi.getPlans();
      if (cancelled) return;
      if (res.success && res.data) {
        // Free ($0) isn't part of the paid selection.
        setPlans(sortPlansByPrice(res.data.filter((p) => Number(p.price) > 0)));
      } else {
        toast.error(res.error || "Failed to load plans");
      }
      setLoading(false);
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  if (loading) {
    return (
      <div className="flex justify-center py-10">
        <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
      </div>
    );
  }

  return (
    // auto-fit keeps every tier on a single row on a wide container and only
    // wraps when it genuinely runs out of width (e.g. mobile).
    <div
      style={{
        display: "grid",
        gap: 16,
        gridTemplateColumns: "repeat(auto-fit, minmax(180px, 1fr))",
        alignItems: "start",
      }}
    >
      {plans.map((plan) => {
        // Select by plan identity — not by stripe_price_id — so a tier whose
        // Stripe price isn't configured yet is still pickable (and we never get
        // the null === null false "selected" on every card).
        const selected = value === plan.id;
        const popular = plan.name?.toLowerCase() === "creator";
        return (
          <button
            type="button"
            key={plan.id}
            onClick={() => onChange(plan)}
            style={{
              position: "relative",
              textAlign: "left",
              background: "var(--paper-0)",
              border: selected ? "2px solid var(--vm-red)" : "1px solid var(--line-1)",
              borderRadius: 22,
              padding: 20,
              cursor: "pointer",
              boxShadow: selected ? "var(--shadow-lg)" : "none",
              transition: "border-color .15s, box-shadow .15s, transform .15s",
              transform: selected ? "translateY(-2px)" : "none",
            }}
          >
            {(selected || popular) && (
              <span
                style={{
                  position: "absolute",
                  top: 16,
                  right: 16,
                  display: "inline-flex",
                  alignItems: "center",
                  gap: 4,
                  background: "var(--vm-red)",
                  color: "#fff",
                  fontFamily: "var(--font-mono)",
                  fontSize: 10,
                  fontWeight: 700,
                  letterSpacing: ".04em",
                  padding: "4px 9px",
                  borderRadius: 999,
                }}
              >
                {selected ? <Check size={11} strokeWidth={3} /> : null}
                {selected ? "SELECTED" : "POPULAR"}
              </span>
            )}
            <div
              style={{
                fontFamily: "var(--font-display)",
                fontWeight: 800,
                fontSize: 18,
                color: "var(--ink-on-paper-1)",
              }}
            >
              {plan.display_name}
            </div>
            {plan.description && (
              <div
                style={{
                  fontFamily: "var(--font-body)",
                  fontSize: 13,
                  color: "var(--ink-on-paper-3)",
                  marginTop: 4,
                }}
              >
                {plan.description}
              </div>
            )}
            <div style={{ display: "flex", alignItems: "baseline", gap: 4, margin: "16px 0" }}>
              <span
                style={{
                  fontFamily: "var(--font-display)",
                  fontWeight: 900,
                  fontSize: 38,
                  letterSpacing: "-.03em",
                  color: "var(--ink-on-paper-1)",
                }}
              >
                ${Math.round(Number(plan.price))}
              </span>
              <span
                style={{
                  fontFamily: "var(--font-body)",
                  fontSize: 14,
                  color: "var(--ink-on-paper-3)",
                }}
              >
                /mo
              </span>
            </div>
            <div style={{ display: "flex", flexDirection: "column", gap: 11, marginTop: 2 }}>
              {featuresFor(plan).map((f) => (
                <div
                  key={f}
                  style={{
                    display: "flex",
                    gap: 10,
                    alignItems: "center",
                    fontFamily: "var(--font-body)",
                    fontSize: 14,
                    color: "var(--ink-on-paper-2)",
                  }}
                >
                  <Check size={16} style={{ color: "var(--vm-red)", flexShrink: 0 }} />
                  {f}
                </div>
              ))}
            </div>
          </button>
        );
      })}
    </div>
  );
}
