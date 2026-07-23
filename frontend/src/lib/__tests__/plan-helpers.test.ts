import { describe, it, expect } from "vitest";
import type { PlanTier } from "@/lib/api-service";
import {
  findCurrentPlan,
  decidePlanCta,
  formatLimit,
  sortPlansByPrice,
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
    expect(formatLimit(null, "offer")).toBe("Unlimited offers");
    expect(formatLimit(null, "channel")).toBe("Unlimited channels");
  });

  it("pluralizes correctly", () => {
    expect(formatLimit(1, "offer")).toBe("1 offer");
    expect(formatLimit(5, "offer")).toBe("5 offers");
    expect(formatLimit(0, "post")).toBe("0 posts");
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
