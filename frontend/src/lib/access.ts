// The free window: every new signup gets 7 days, and admin-created promotional
// customers get one of any length (or forever). For a self signup the window is
// the trial of the plan they pick in onboarding (card required there); when it
// closes without a card the user is sent to Billing. The backend is the source
// of truth (`access_expired`), but the window can close while a cached session
// is open, so the date is also checked here. A card-backed plan always wins.

export interface AccessUser {
  has_active_plan?: boolean;
  has_active_subscription?: boolean;
  promo_expires_at?: string | null;
  access_expired?: boolean;
  is_promotional?: boolean;
  /** Backend SIGNUP_CARD_REQUIRED. false = card-free trial (see cardOptional). */
  card_required?: boolean;
}

/**
 * Charged the day a free trial starts, as a one-time Stripe price
 * (STRIPE_ACTIVATION_FEE_PRICE_ID on the backend). 0 since 2026-10-25: a card
 * is required to start the trial but nothing is charged until it ends. Used
 * for the amount shown at checkout; the prose copy in StripeTrialStep,
 * Plans.tsx and the landing copy is written out, so revisit it if this changes.
 */
export const ACTIVATION_FEE_USD = 0;

/**
 * Onboarding's card step is skipped for an admin-created promotional customer
 * whose window is open (they count as subscribed with no plan row), and — in a
 * card-free trial (SIGNUP_CARD_REQUIRED=false) — for any signup whose 7-day
 * window is still open: the card is asked for when the window closes and the
 * account is sent to Billing. Mirrors the backend's User::canCompleteOnboarding.
 */
export function skipsCardStep(user: AccessUser | null | undefined, now: number = Date.now()): boolean {
  if (!!user?.is_promotional && !!user?.has_active_subscription && user?.has_active_plan === false) return true;
  return cardOptional(user, now);
}

/**
 * The card step is optional (backend SIGNUP_CARD_REQUIRED=false): a signup can
 * finish onboarding without a card and use its 7-day window as a card-free
 * trial; when the window closes it goes to Billing as usual. Mirrors the
 * window branch of the backend's User::canCompleteOnboarding.
 */
export function cardOptional(user: AccessUser | null | undefined, now: number = Date.now()): boolean {
  return user?.card_required === false && !!user?.promo_expires_at && !isAccessExpired(user, now);
}

export const PROMO_ROLE = "promotional_customer";

/**
 * The limited-release audience: promotional customers and admins. Features
 * still being finished open to them before paying customers. Mirrors the
 * backend's SocialProviderManager::inLimitedReleaseAudience.
 */
export function inLimitedReleaseAudience(user: { is_admin?: boolean; is_promotional?: boolean } | null | undefined): boolean {
  return !!(user?.is_admin || user?.is_promotional);
}

/** The plan names whose tier includes analytics. Mirrors App\Support\AnalyticsAccess::PLANS. */
export const ANALYTICS_PLANS = ["pro", "agency", "creator_pro", "creator_elite", "creator_agency"];

/**
 * The plan names whose tier includes monetization: offers, tracked links and
 * revenue attribution. Only the pricing cards read this today — the feature
 * itself is not gated yet (decided 2026-10-25, still to be built), so don't
 * take this as the gate.
 */
export const MONETIZATION_PLANS = ["agency", "creator_agency"];

/**
 * Channel + post analytics is the Pro tier upsell: Profile Performance, Post
 * Performance and the YouTube channel analytics page. The backend answers with
 * `can_use_analytics` (App\Support\AnalyticsAccess); the plan-name fallback is
 * for a session cached before that field existed, so a paying user never loses
 * the nav on the first paint after a deploy. Admins and promotional customers
 * are exempt, as everywhere else.
 */
export function canUseAnalytics(
  user: { is_admin?: boolean; is_promotional?: boolean; can_use_analytics?: boolean; active_plan?: { name?: string } | null } | null | undefined,
): boolean {
  if (!user) return false;
  if (inLimitedReleaseAudience(user)) return true;
  if (typeof user.can_use_analytics === "boolean") return user.can_use_analytics;
  const plan = user.active_plan?.name?.toLowerCase();
  return !!plan && ANALYTICS_PLANS.includes(plan);
}

export function isAccessExpired(user: AccessUser | null | undefined, now: number = Date.now()): boolean {
  if (!user) return false;
  if (user.access_expired) return true;
  if (!user.promo_expires_at || user.has_active_plan) return false;
  return Date.parse(user.promo_expires_at) <= now;
}

/**
 * What adding a card costs today. The free window is the only trial: a card
 * added while it is open pays the activation fee now and the plan price when
 * the window closes; one added at any other time (window closed, or no window
 * at all) pays the plan price straight away. Mirrors the backend's
 * User::subscriptionTrialTerms.
 */
export type CheckoutTerms =
  | { kind: "window"; chargeAt: number }
  | { kind: "charge-now" };

export function checkoutTerms(user: AccessUser | null | undefined, now: number = Date.now()): CheckoutTerms {
  const end = user?.promo_expires_at ? Date.parse(user.promo_expires_at) : NaN;
  return end > now ? { kind: "window", chargeAt: end } : { kind: "charge-now" };
}

/** Free-access windows an admin can grant, in days. `null` = never expires. */
export const PROMO_WINDOWS: { days: number | null; label: string }[] = [
  { days: 7, label: "7 days" },
  { days: 14, label: "14 days" },
  { days: 30, label: "1 month" },
  { days: null, label: "Unlimited" },
];

/** Short admin label for a user's promo window, e.g. "until 12 Oct 2026". */
export function promoWindowLabel(promoExpiresAt: string | null | undefined, now: number = Date.now()): string {
  if (!promoExpiresAt) return "unlimited";
  const at = Date.parse(promoExpiresAt);
  if (Number.isNaN(at)) return "unlimited";
  const date = new Date(at).toLocaleDateString([], { day: "numeric", month: "short", year: "numeric" });
  return at <= now ? `expired ${date}` : `until ${date}`;
}
