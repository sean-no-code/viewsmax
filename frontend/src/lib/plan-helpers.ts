import { Crown, Zap, Sparkles, Rocket, type LucideIcon } from "lucide-react";
import type { TFunction } from "i18next";
import type { PlanTier } from "@/lib/api-service";
import { ANALYTICS_PLANS, MONETIZATION_PLANS } from "@/lib/access";

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
 * landing page, billing page, checkout and onboarding picker. Short lines: they
 * sit in a four-column card. Keep entries comma-free: Checkout receives
 * features via a URL param split on ",".
 */
export const PLAN_INCLUDES: readonly string[] = [
  "Multiple accounts per platform",
  "Brands (account groups)",
  "Outlier research with AI breakdowns",
  "Boosts: auto-repost & auto-promo",
  "MCP server & API for AI agents",
  "Comment chains & X threads",
  "Beehiiv reach tracking",
  "Human support",
];

/**
 * Lines only some tiers include, in the order planIncludes() inserts them
 * (right after the shared lines a card always shows). Analytics is the Pro
 * upsell, monetization the Agency one, so a Starter card must not promise
 * either. The landing page keeps the same lines in its own copy files, per
 * tier; a test in src/i18n/__tests__ holds the two together.
 */
export const PLAN_TIER_INCLUDES = [
  { line: "Channel & post analytics", plans: ANALYTICS_PLANS },
  { line: "Tracked links & revenue attribution", plans: MONETIZATION_PLANS },
] as const;

/** Whether a card for this plan shows a tier-specific line. */
const showsTierLine = (plans: readonly string[], planName?: string | null): boolean =>
  !!planName && plans.includes(planName.toLowerCase());

/**
 * The English inclusions a given tier's card shows: the shared lines plus
 * whatever that tier adds. Pass the plan's `name` ("pro") or display name.
 */
export function planIncludesFor(planName?: string | null): string[] {
  return [
    ...PLAN_INCLUDES,
    ...PLAN_TIER_INCLUDES.filter((e) => showsTierLine(e.plans, planName)).map((e) => e.line),
  ];
}

/** planIncludesFor in the UI language (same English; a test keeps the two in step). */
export const planIncludes = (t: TFunction, planName?: string | null): string[] => [
  t("billing.includes.multipleAccounts", "Multiple accounts per platform"),
  t("billing.includes.brands", "Brands (account groups)"),
  t("billing.includes.outliers", "Outlier research with AI breakdowns"),
  t("billing.includes.boosts", "Boosts: auto-repost & auto-promo"),
  t("billing.includes.mcp", "MCP server & API for AI agents"),
  t("billing.includes.commentChains", "Comment chains & X threads"),
  t("billing.includes.beehiiv", "Beehiiv reach tracking"),
  t("billing.includes.humanSupport", "Human support"),
  ...(showsTierLine(ANALYTICS_PLANS, planName)
    ? [t("billing.includes.analytics", "Channel & post analytics")]
    : []),
  ...(showsTierLine(MONETIZATION_PLANS, planName)
    ? [t("billing.includes.trackedLinks", "Tracked links & revenue attribution")]
    : []),
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

/**
 * The limit bullets on a plan card: channels and posts, never offers. Offers
 * are monetization, which is the Agency tier's feature (PLAN_TIER_INCLUDES
 * carries that line), so a per-tier offer count on every card promised the
 * wrong thing. Derived from the numeric limits rather than the seeded
 * `features` column so the lines are in the UI language.
 */
export function planLimitLines(t: TFunction, plan: PlanTier): string[] {
  return [
    formatLimit(t, plan.max_channels, "channel"),
    t("trial.checkout.postsPerMonth", "{{limit}}/mo", { limit: formatLimit(t, plan.max_posts_per_month, "post") }),
  ];
}

/** Plans sorted cheapest → most expensive for display. */
export function sortPlansByPrice(plans: PlanTier[]): PlanTier[] {
  return [...plans].sort((a, b) => Number(a.price) - Number(b.price));
}
