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
}

/**
 * Charged the day a free trial starts, as a one-time Stripe price
 * (STRIPE_ACTIVATION_FEE_PRICE_ID on the backend). Used for the amounts shown
 * at checkout; the "$1" in the prose copy is written out, so grep for it too
 * if this changes.
 */
export const ACTIVATION_FEE_USD = 1;

/**
 * Onboarding's card step is skipped only for an admin-created promotional
 * customer whose window is open: they count as subscribed with no plan row.
 * Mirrors the backend's User::canCompleteOnboarding.
 */
export function skipsCardStep(user: AccessUser | null | undefined): boolean {
  return !!user?.is_promotional && !!user?.has_active_subscription && user?.has_active_plan === false;
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
