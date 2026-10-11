// Free access with no card on file: every new signup gets a 7-day window, and
// admin-created promotional customers get one of any length (or forever). Once
// it closes the user is sent to Billing. The backend is the source of truth
// (`access_expired`), but the window can close while a cached session is open,
// so the date is also checked here. A card-backed plan always wins.

export interface AccessUser {
  has_active_plan?: boolean;
  promo_expires_at?: string | null;
  access_expired?: boolean;
}

export const PROMO_ROLE = "promotional_customer";

export function isAccessExpired(user: AccessUser | null | undefined, now: number = Date.now()): boolean {
  if (!user) return false;
  if (user.access_expired) return true;
  if (!user.promo_expires_at || user.has_active_plan) return false;
  return Date.parse(user.promo_expires_at) <= now;
}

/**
 * What adding a card costs today. The free window is the only trial: a card
 * added while it is open isn't charged until it closes, and one added at any
 * other time (window closed, or no window at all) is charged straight away.
 * Mirrors the backend's User::subscriptionTrialTerms.
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
