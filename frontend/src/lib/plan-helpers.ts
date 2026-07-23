import { Crown, Zap, Sparkles, Rocket, type LucideIcon } from "lucide-react";
import type { PlanTier } from "@/lib/api-service";

// Per-tier icon, keyed by the plan's `name` (or display_name). Falls back to Crown.
// Shared by the pricing cards and the global role badge so they stay in sync.
const planIcons: Record<string, LucideIcon> = {
  starter: Zap,
  creator: Sparkles,
  pro: Crown,
  agency: Rocket,
};

export function iconForPlan(name?: string | null): LucideIcon {
  return planIcons[name?.toLowerCase() ?? ""] ?? Crown;
}

/**
 * What the call-to-action button on a pricing card should do, given the user's
 * current subscription:
 *  - 'subscribe'  no active paid plan yet → start checkout
 *  - 'current'    this is the plan they're on
 *  - 'upgrade'    this plan costs more than their current one
 *  - 'downgrade'  this plan costs less than their current one
 */
export type PlanCta = "subscribe" | "current" | "upgrade" | "downgrade";

/** Resolve the user's current plan from the live subscription's Stripe price id. */
export function findCurrentPlan(
  plans: PlanTier[],
  currentPriceId: string | null | undefined
): PlanTier | null {
  if (!currentPriceId) return null;
  return plans.find((p) => p.stripe_price_id === currentPriceId) ?? null;
}

/** Decide the CTA for a plan card relative to the user's current plan. */
export function decidePlanCta(plan: PlanTier, current: PlanTier | null): PlanCta {
  if (!current) return "subscribe";
  if (plan.stripe_price_id && plan.stripe_price_id === current.stripe_price_id) {
    return "current";
  }
  return Number(plan.price) > Number(current.price) ? "upgrade" : "downgrade";
}

/** Human label for a numeric limit; null = unlimited. */
export function formatLimit(value: number | null, noun: string): string {
  if (value === null) return `Unlimited ${noun}s`;
  const plural = value === 1 ? noun : `${noun}s`;
  return `${value} ${plural}`;
}

/** Plans sorted cheapest → most expensive for display. */
export function sortPlansByPrice(plans: PlanTier[]): PlanTier[] {
  return [...plans].sort((a, b) => Number(a.price) - Number(b.price));
}
