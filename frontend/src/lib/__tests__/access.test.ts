import { describe, expect, it } from "vitest";
import { canUseAnalytics, cardOptional, checkoutTerms, inLimitedReleaseAudience, isAccessExpired, promoWindowLabel, skipsCardStep } from "../access";

const NOW = Date.parse("2026-09-28T10:00:00Z");
const past = "2026-09-27T10:00:00Z";
const future = "2026-10-12T10:00:00Z";

describe("isAccessExpired", () => {
  it("is false for no user or a plain customer", () => {
    expect(isAccessExpired(null, NOW)).toBe(false);
    expect(isAccessExpired({}, NOW)).toBe(false);
    expect(isAccessExpired({ has_active_plan: true }, NOW)).toBe(false);
  });

  it("trusts the server flag", () => {
    expect(isAccessExpired({ access_expired: true }, NOW)).toBe(true);
    expect(isAccessExpired({ access_expired: false, promo_expires_at: future }, NOW)).toBe(false);
  });

  it("catches a window that closed while the session was cached", () => {
    expect(isAccessExpired({ access_expired: false, promo_expires_at: past }, NOW)).toBe(true);
    expect(isAccessExpired({ promo_expires_at: future }, NOW)).toBe(false);
  });

  it("never locks an unlimited promo or a user with a card-backed plan", () => {
    expect(isAccessExpired({ promo_expires_at: null }, NOW)).toBe(false);
    expect(isAccessExpired({ promo_expires_at: past, has_active_plan: true }, NOW)).toBe(false);
  });
});

describe("checkoutTerms", () => {
  it("charges users with no free window today (no Stripe trial period)", () => {
    expect(checkoutTerms(null, NOW)).toEqual({ kind: "charge-now" });
    expect(checkoutTerms({ promo_expires_at: null }, NOW)).toEqual({ kind: "charge-now" });
  });

  it("defers the charge to the end of an open window", () => {
    expect(checkoutTerms({ promo_expires_at: future }, NOW)).toEqual({ kind: "window", chargeAt: Date.parse(future) });
  });

  it("charges today once the window has closed", () => {
    expect(checkoutTerms({ promo_expires_at: past }, NOW)).toEqual({ kind: "charge-now" });
  });
});

describe("promoWindowLabel", () => {
  it("describes unlimited, open, and closed windows", () => {
    expect(promoWindowLabel(null, NOW)).toBe("unlimited");
    expect(promoWindowLabel(future, NOW)).toMatch(/^until /);
    expect(promoWindowLabel(past, NOW)).toMatch(/^expired /);
  });
});

describe("inLimitedReleaseAudience", () => {
  it("includes admins and promotional customers only", () => {
    expect(inLimitedReleaseAudience({ is_admin: true })).toBe(true);
    expect(inLimitedReleaseAudience({ is_promotional: true })).toBe(true);
    expect(inLimitedReleaseAudience({ is_admin: false, is_promotional: false })).toBe(false);
    expect(inLimitedReleaseAudience({})).toBe(false);
    expect(inLimitedReleaseAudience(null)).toBe(false);
  });
});

describe("skipsCardStep", () => {
  it("skips the card step only for a promotional customer inside their window", () => {
    expect(skipsCardStep({ is_promotional: true, has_active_subscription: true, has_active_plan: false })).toBe(true);
  });

  it("asks a self signup for a card even while their free window is open", () => {
    expect(skipsCardStep({ has_active_subscription: true, has_active_plan: false, promo_expires_at: future })).toBe(false);
  });

  it("does not skip for a promotional customer who already has a plan, an expired one, or no user", () => {
    expect(skipsCardStep({ is_promotional: true, has_active_subscription: true, has_active_plan: true })).toBe(false);
    expect(skipsCardStep({ is_promotional: true, has_active_subscription: false, has_active_plan: false })).toBe(false);
    expect(skipsCardStep(null)).toBe(false);
  });
});

describe("canUseAnalytics", () => {
  it("trusts the backend flag", () => {
    expect(canUseAnalytics({ can_use_analytics: true })).toBe(true);
    expect(canUseAnalytics({ can_use_analytics: false })).toBe(false);
  });

  it("admits admins and promotional customers whatever their plan says", () => {
    expect(canUseAnalytics({ is_admin: true, can_use_analytics: false })).toBe(true);
    expect(canUseAnalytics({ is_promotional: true, can_use_analytics: false })).toBe(true);
  });

  it("falls back to the plan name for a session cached before the flag existed", () => {
    expect(canUseAnalytics({ active_plan: { name: "pro" } })).toBe(true);
    expect(canUseAnalytics({ active_plan: { name: "Agency" } })).toBe(true);
    expect(canUseAnalytics({ active_plan: { name: "creator_elite" } })).toBe(true);
    expect(canUseAnalytics({ active_plan: { name: "creator" } })).toBe(false);
    expect(canUseAnalytics({ active_plan: { name: "starter" } })).toBe(false);
    expect(canUseAnalytics({ active_plan: { name: "free" } })).toBe(false);
    expect(canUseAnalytics({ active_plan: null })).toBe(false);
  });

  it("is false without a user", () => {
    expect(canUseAnalytics(null)).toBe(false);
    expect(canUseAnalytics(undefined)).toBe(false);
    expect(canUseAnalytics({})).toBe(false);
  });
});

describe("cardOptional (SIGNUP_CARD_REQUIRED=false)", () => {
  const now = Date.parse("2026-10-05T00:00:00Z");
  const open = { promo_expires_at: "2026-10-09T10:00:00Z", has_active_plan: false };

  it("lets a signup in its window skip the card when the backend says a card is optional", () => {
    expect(cardOptional({ ...open, card_required: false }, now)).toBe(true);
  });

  it("keeps the card step when the backend requires a card, or doesn't say", () => {
    expect(cardOptional({ ...open, card_required: true }, now)).toBe(false);
    expect(cardOptional(open, now)).toBe(false);
  });

  it("is off once the window has closed, or with no window at all", () => {
    expect(cardOptional({ ...open, card_required: false }, Date.parse("2026-10-10T00:00:00Z"))).toBe(false);
    expect(cardOptional({ card_required: false, promo_expires_at: null }, now)).toBe(false);
    expect(cardOptional(null, now)).toBe(false);
  });
});

describe("skipsCardStep with a card-free trial (SIGNUP_CARD_REQUIRED=false)", () => {
  const now = Date.parse("2026-10-05T00:00:00Z");
  const signup = { promo_expires_at: "2026-10-09T10:00:00Z", has_active_plan: false, has_active_subscription: true, is_promotional: false };

  it("drops the card step while the window is open", () => {
    expect(skipsCardStep({ ...signup, card_required: false }, now)).toBe(true);
  });

  it("keeps it when a card is required, or once the window has closed", () => {
    expect(skipsCardStep({ ...signup, card_required: true }, now)).toBe(false);
    expect(skipsCardStep(signup, now)).toBe(false);
    expect(skipsCardStep({ ...signup, card_required: false }, Date.parse("2026-10-10T00:00:00Z"))).toBe(false);
  });
});
