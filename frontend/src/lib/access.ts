// Free access with no card on file: every new signup gets free credits with no
// time limit (`on_free_credits`) and is sent to Billing once they are used up;
// admin-created promotional customers get a window of any length (or forever)
// and are sent to Billing when it closes. The backend is the source of truth
// (`access_expired`), but a window can close while a cached session is open, so
// its date is also checked here. A card-backed plan always wins.

export interface AccessUser {
  has_active_plan?: boolean;
  promo_expires_at?: string | null;
  on_free_credits?: boolean;
  access_expired?: boolean;
}

export const PROMO_ROLE = "promotional_customer";

/**
 * Fired when free access may have just ended: the next action after a charge
 * left 0 credits, or a request that came back 403 `access_expired`. AuthProvider re-reads the profile,
 * so the lock (Billing only) applies at once instead of on the next click,
 * and the open page stops making requests that would all be refused.
 */
export const ACCESS_CHECK_EVENT = "viewsmax:access-check";

export function requestAccessCheck(): void {
  if (typeof window !== "undefined") window.dispatchEvent(new Event(ACCESS_CHECK_EVENT));
}

let checkPending = false;

/**
 * A successful charge spent the last credits: the user paid for that result,
 * so it stays on screen and the check runs on their next click or Enter.
 */
export function requestAccessCheckOnNextAction(): void {
  if (typeof window === "undefined" || checkPending) return;
  checkPending = true;
  const onAction = (e: Event) => {
    if (e instanceof KeyboardEvent && e.key !== "Enter") return;
    window.removeEventListener("pointerdown", onAction, true);
    window.removeEventListener("keydown", onAction, true);
    checkPending = false;
    requestAccessCheck();
  };
  window.addEventListener("pointerdown", onAction, true);
  window.addEventListener("keydown", onAction, true);
}

export function isAccessExpired(user: AccessUser | null | undefined, now: number = Date.now()): boolean {
  if (!user) return false;
  if (user.access_expired) return true;
  if (!user.promo_expires_at || user.has_active_plan) return false;
  return Date.parse(user.promo_expires_at) <= now;
}

/**
 * What adding a card costs today. Free credits are the trial for a self-signup,
 * so they are charged straight away. A promo window is the trial too: a card
 * added while it is open isn't charged until it closes, and one added
 * afterwards is charged straight away. Users with neither (accounts from before
 * they existed) still get the card-backed trial. Mirrors the backend's
 * User::subscriptionTrialTerms.
 */
export type CheckoutTerms =
  | { kind: "card-trial" }
  | { kind: "window"; chargeAt: number }
  | { kind: "charge-now" }
  | { kind: "free-credits" };

export function checkoutTerms(user: AccessUser | null | undefined, now: number = Date.now()): CheckoutTerms {
  if (user?.on_free_credits) return { kind: "free-credits" };
  const end = user?.promo_expires_at ? Date.parse(user.promo_expires_at) : NaN;
  if (Number.isNaN(end)) return { kind: "card-trial" };
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
