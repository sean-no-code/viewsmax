import { describe, it, expect } from "vitest";
import i18n from "i18next";
import type { PlanTier } from "@/lib/api-service";
import {
  findCurrentPlan,
  decidePlanCta,
  formatLimit,
  sortPlansByPrice,
  PLAN_INCLUDES,
  PLAN_TIER_INCLUDES,
  planIncludes,
  planIncludesFor,
  planLimitLines,
} from "@/lib/plan-helpers";

const tier = (over: Partial<PlanTier>): PlanTier => ({
  id: 1,
  name: "starter",
  display_name: "Starter",
  description: null,
  price: "29.00",
  currency: "USD",
  billing_cycle: "monthly",
  features: [],
  max_channels: 5,
  max_offers: 1,
  max_posts_per_month: 400,
  stripe_price_id: "price_starter",
  is_active: true,
  ...over,
});

const starter = tier({ name: "starter", price: "29.00", stripe_price_id: "price_starter", max_offers: 1 });
const creator = tier({ name: "creator", price: "59.00", stripe_price_id: "price_creator", max_offers: 5 });
const pro = tier({ name: "pro", price: "99.00", stripe_price_id: "price_pro", max_offers: 10 });
const agency = tier({ name: "agency", price: "149.00", stripe_price_id: "price_agency", max_offers: null });
const plans = [pro, starter, agency, creator];

describe("findCurrentPlan", () => {
  it("returns null when there is no active price id", () => {
    expect(findCurrentPlan(plans, null)).toBeNull();
    expect(findCurrentPlan(plans, undefined)).toBeNull();
  });

  it("matches the plan by its Stripe price id", () => {
    expect(findCurrentPlan(plans, "price_creator")).toBe(creator);
  });
});

describe("decidePlanCta", () => {
  it("says subscribe when the user has no current plan", () => {
    expect(decidePlanCta(starter, null)).toBe("subscribe");
    expect(decidePlanCta(agency, null)).toBe("subscribe");
  });

  it("marks the current plan", () => {
    expect(decidePlanCta(creator, creator)).toBe("current");
  });

  it("treats a pricier plan as an upgrade", () => {
    expect(decidePlanCta(pro, creator)).toBe("upgrade");
    expect(decidePlanCta(agency, starter)).toBe("upgrade");
  });

  it("treats a cheaper plan as a downgrade", () => {
    expect(decidePlanCta(starter, creator)).toBe("downgrade");
    expect(decidePlanCta(creator, pro)).toBe("downgrade");
  });
});

describe("formatLimit", () => {
  it("renders unlimited for null", () => {
    expect(formatLimit(i18n.t, null, "offer")).toBe("Unlimited offers");
    expect(formatLimit(i18n.t, null, "channel")).toBe("Unlimited channels");
  });

  it("pluralizes correctly", () => {
    expect(formatLimit(i18n.t, 1, "offer")).toBe("1 offer");
    expect(formatLimit(i18n.t, 5, "offer")).toBe("5 offers");
    expect(formatLimit(i18n.t, 0, "post")).toBe("0 posts");
  });
});

describe("planLimitLines", () => {
  it("lists channels and posts, never offers", () => {
    expect(planLimitLines(i18n.t, starter)).toEqual(["5 channels", "400 posts/mo"]);
    expect(planLimitLines(i18n.t, tier({ max_channels: null, max_posts_per_month: null })))
      .toEqual(["Unlimited channels", "Unlimited posts/mo"]);
    for (const plan of plans) {
      for (const line of planLimitLines(i18n.t, plan)) expect(line).not.toMatch(/offer/i);
    }
  });
});

describe("sortPlansByPrice", () => {
  it("orders cheapest to most expensive", () => {
    expect(sortPlansByPrice(plans).map((p) => p.name)).toEqual([
      "starter",
      "creator",
      "pro",
      "agency",
    ]);
  });

  it("does not mutate the input", () => {
    const input = [pro, starter];
    sortPlansByPrice(input);
    expect(input[0]).toBe(pro);
  });
});

describe("PLAN_INCLUDES", () => {
  it("has no commas, because Checkout splits the features URL param on them", () => {
    expect(PLAN_INCLUDES.length).toBeGreaterThan(0);
    for (const line of [...PLAN_INCLUDES, ...PLAN_TIER_INCLUDES.map((e) => e.line)]) {
      expect(line).not.toContain(",");
    }
  });

  it("matches its translatable version in English, per tier", () => {
    for (const tier of ["starter", "creator", "pro", "agency", undefined]) {
      expect(planIncludes(i18n.t, tier)).toEqual(planIncludesFor(tier));
    }
  });
});

describe("planIncludesFor", () => {
  const analytics = "Channel & post analytics";
  const monetization = "Tracked links & revenue attribution";

  it("promises neither analytics nor monetization below Pro", () => {
    for (const tier of ["free", "starter", "creator", undefined]) {
      expect(planIncludesFor(tier)).toEqual([...PLAN_INCLUDES]);
    }
  });

  it("adds analytics from Pro up", () => {
    expect(planIncludesFor("pro")).toContain(analytics);
    expect(planIncludesFor("Pro")).toContain(analytics); // display name too
    expect(planIncludesFor("agency")).toContain(analytics);
  });

  it("keeps monetization on Agency only", () => {
    expect(planIncludesFor("agency")).toContain(monetization);
    expect(planIncludesFor("pro")).not.toContain(monetization);
    expect(planIncludesFor("creator")).not.toContain(monetization);
  });

  it("grandfathers the retired legacy tiers", () => {
    expect(planIncludesFor("creator_elite")).toContain(analytics);
    expect(planIncludesFor("creator_agency")).toContain(monetization);
  });
});
