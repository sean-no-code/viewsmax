import { describe, expect, it, vi } from "vitest";
import { ACCESS_CHECK_EVENT, checkoutTerms, isAccessExpired, promoWindowLabel, requestAccessCheckOnNextAction } from "../access";

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
  it("keeps the card-backed trial for users with no free window", () => {
    expect(checkoutTerms(null, NOW)).toEqual({ kind: "card-trial" });
    expect(checkoutTerms({ promo_expires_at: null }, NOW)).toEqual({ kind: "card-trial" });
  });

  it("defers the charge to the end of an open window", () => {
    expect(checkoutTerms({ promo_expires_at: future }, NOW)).toEqual({ kind: "window", chargeAt: Date.parse(future) });
  });

  it("charges today once the window has closed", () => {
    expect(checkoutTerms({ promo_expires_at: past }, NOW)).toEqual({ kind: "charge-now" });
  });

  it("charges today for a user on free credits: the credits were the trial", () => {
    expect(checkoutTerms({ on_free_credits: true, promo_expires_at: null }, NOW)).toEqual({ kind: "free-credits" });
  });
});

describe("isAccessExpired for free credits", () => {
  it("follows the server: open while credits remain, locked once used up", () => {
    expect(isAccessExpired({ on_free_credits: true, access_expired: false }, NOW)).toBe(false);
    expect(isAccessExpired({ on_free_credits: true, access_expired: true }, NOW)).toBe(true);
  });
});

describe("promoWindowLabel", () => {
  it("describes unlimited, open, and closed windows", () => {
    expect(promoWindowLabel(null, NOW)).toBe("unlimited");
    expect(promoWindowLabel(future, NOW)).toMatch(/^until /);
    expect(promoWindowLabel(past, NOW)).toMatch(/^expired /);
  });
});

describe("locking when the last free credits are spent", () => {
  it("waits for the next click, so what was paid for stays on screen", () => {
    const check = vi.fn();
    window.addEventListener(ACCESS_CHECK_EVENT, check);

    requestAccessCheckOnNextAction();
    requestAccessCheckOnNextAction(); // a second charge in the same moment doesn't double up
    expect(check).not.toHaveBeenCalled();

    window.dispatchEvent(new KeyboardEvent("keydown", { key: "a" }));
    expect(check).not.toHaveBeenCalled();

    window.dispatchEvent(new Event("pointerdown"));
    expect(check).toHaveBeenCalledTimes(1);

    window.dispatchEvent(new Event("pointerdown"));
    expect(check).toHaveBeenCalledTimes(1);
    window.removeEventListener(ACCESS_CHECK_EVENT, check);
  });

  it("Enter counts as the next action", () => {
    const check = vi.fn();
    window.addEventListener(ACCESS_CHECK_EVENT, check);

    requestAccessCheckOnNextAction();
    window.dispatchEvent(new KeyboardEvent("keydown", { key: "Enter" }));

    expect(check).toHaveBeenCalledTimes(1);
    window.removeEventListener(ACCESS_CHECK_EVENT, check);
  });
});
