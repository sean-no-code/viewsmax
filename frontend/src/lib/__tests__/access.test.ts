import { describe, expect, it } from "vitest";
import { checkoutTerms, inLimitedReleaseAudience, isAccessExpired, promoWindowLabel, skipsCardStep } from "../access";

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
