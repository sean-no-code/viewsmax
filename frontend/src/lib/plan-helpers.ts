import { Crown, Zap, Sparkles, Rocket, type LucideIcon } from "lucide-react";
import type { TFunction } from "i18next";
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

/**
 * What every paid tier includes, listed under each plan's limit lines on the
 * landing page, billing page, checkout and onboarding picker. Tiers differ only
 * by their numeric limits. Short lines: they sit in a four-column card. Keep
 * entries comma-free: Checkout receives features via a URL param split on ",".
 */
export const PLAN_INCLUDES: readonly string[] = [
  "Multiple accounts per platform",
  "Brands (account groups)",
  "Tracked links & revenue attribution",
  "Outlier research with AI breakdowns",
  "Boosts: auto-repost & auto-promo",
  "MCP server & API for AI agents",
  "Comment chains & X threads",
  "Beehiiv reach tracking",
  "Human support",
];

/** PLAN_INCLUDES in the UI language (same English; a test keeps the two in step). */
export const planIncludes = (t: TFunction): string[] => [
  t("billing.includes.multipleAccounts", "Multiple accounts per platform"),
  t("billing.includes.brands", "Brands (account groups)"),
  t("billing.includes.trackedLinks", "Tracked links & revenue attribution"),
  t("billing.includes.outliers", "Outlier research with AI breakdowns"),
  t("billing.includes.boosts", "Boosts: auto-repost & auto-promo"),
  t("billing.includes.mcp", "MCP server & API for AI agents"),
  t("billing.includes.commentChains", "Comment chains & X threads"),
  t("billing.includes.beehiiv", "Beehiiv reach tracking"),
  t("billing.includes.humanSupport", "Human support"),
];

/** Human label for a numeric limit; null = unlimited. */
export type LimitKind = "channel" | "offer" | "post";

// Literal keys per kind so the extractor sees them; plurals follow each language's forms.
export function formatLimit(t: TFunction, value: number | null, kind: LimitKind): string {
  switch (kind) {
    case "channel":
      return value === null
        ? t("billing.limits.unlimitedChannels", "Unlimited channels")
        : t("billing.limits.channels", { count: value, defaultValue_one: "{{count}} channel", defaultValue_other: "{{count}} channels" });
    case "offer":
      return value === null
        ? t("billing.limits.unlimitedOffers", "Unlimited offers")
        : t("billing.limits.offers", { count: value, defaultValue_one: "{{count}} offer", defaultValue_other: "{{count}} offers" });
    case "post":
      return value === null
        ? t("billing.limits.unlimitedPosts", "Unlimited posts")
        : t("billing.limits.posts", { count: value, defaultValue_one: "{{count}} post", defaultValue_other: "{{count}} posts" });
  }
}

/** Plans sorted cheapest → most expensive for display. */
export function sortPlansByPrice(plans: PlanTier[]): PlanTier[] {
  return [...plans].sort((a, b) => Number(a.price) - Number(b.price));
}
