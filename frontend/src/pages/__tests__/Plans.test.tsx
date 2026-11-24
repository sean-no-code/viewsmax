import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter } from "react-router-dom";

// The billing page's recovery paths: a past-due customer can cancel from the
// banner (the backend ends it now), and anyone with a Stripe customer, even
// with no live subscription, gets a link to invoices and their card.
const authState = vi.hoisted(() => ({ user: null as null | Record<string, unknown> }));
const api = vi.hoisted(() => ({
  getPlans: vi.fn(),
  getCurrentPlan: vi.fn(),
  getBillingStatus: vi.fn(),
  cancelSubscription: vi.fn(),
  createBillingPortalSession: vi.fn(),
  changePlan: vi.fn(),
}));

vi.mock("@/hooks/useAuth", () => ({ useAuth: () => ({ user: authState.user, loading: false }) }));
vi.mock("@/lib/api-service", async (original) => ({
  ...(await original<typeof import("@/lib/api-service")>()),
  viewsMaxApi: api,
}));

import Plans from "@/pages/Plans";

const renderPlans = () => render(<MemoryRouter><Plans /></MemoryRouter>);

describe("Plans billing recovery", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    localStorage.clear();
    authState.user = { id: 1, email: "jane@example.com", promo_expires_at: new Date(Date.now() - 86_400_000).toISOString() };
    api.getPlans.mockResolvedValue({ success: true, data: [] });
    api.getCurrentPlan.mockResolvedValue({ success: false, error: "No active plan found" });
  });

  it("lets a past-due customer cancel from the banner", async () => {
    api.getBillingStatus.mockResolvedValue({
      success: true,
      data: { has_subscription: true, status: "past_due", subscription_id: "sub_pd", has_billing_account: true },
    });
    api.cancelSubscription.mockResolvedValue({ success: true, data: { message: "ok" } });
    const user = userEvent.setup();
    renderPlans();

    await user.click(await screen.findByRole("button", { name: "Cancel subscription" }));
    expect(screen.getByText(/there's no paid time left/)).toBeInTheDocument();
    await user.click(screen.getByRole("radio", { name: "It's too expensive" }));
    await user.click(screen.getByRole("button", { name: "Yes, Cancel" }));

    await waitFor(() => expect(api.cancelSubscription).toHaveBeenCalledWith("sub_pd", "too_expensive", ""));
    // The banner has its own portal button; no second billing link.
    expect(screen.queryByRole("button", { name: /Billing history/ })).toBeNull();
  });

  it("links an expired customer to billing history", async () => {
    api.getBillingStatus.mockResolvedValue({ success: true, data: { has_subscription: false, has_billing_account: true } });
    api.createBillingPortalSession.mockResolvedValue({ success: false, error: "nope" });
    const user = userEvent.setup();
    renderPlans();

    await user.click(await screen.findByRole("button", { name: /Billing history & payment method/ }));

    expect(api.createBillingPortalSession).toHaveBeenCalledTimes(1);
    expect(screen.queryByRole("button", { name: "Cancel subscription" })).toBeNull();
  });

  it("says the plan price is charged when the trial ends", async () => {
    const creator = { id: 1, name: "creator", display_name: "Creator", price: "29", currency: "USD", billing_cycle: "monthly", is_active: true, stripe_price_id: "price_creator", max_channels: 5, max_posts_per_month: 100 };
    api.getPlans.mockResolvedValue({ success: true, data: [creator] });
    api.getCurrentPlan.mockResolvedValue({
      success: true,
      data: { plan: creator, pivot: { stripe_subscription_id: "sub_t", status: "trialing", stripe_price_id: "price_creator", expires_at: "2030-01-15T12:00:00Z", cancelled_at: null } },
    });
    api.getBillingStatus.mockResolvedValue({ success: true, data: { has_subscription: true, status: "trialing", has_billing_account: true } });
    renderPlans();

    expect(await screen.findByText(/then \$29\/mo is charged/)).toBeInTheDocument();
  });

  it("shows no billing link without a Stripe customer", async () => {
    api.getBillingStatus.mockResolvedValue({ success: true, data: { has_subscription: false, has_billing_account: false } });
    renderPlans();

    await waitFor(() => expect(api.getBillingStatus).toHaveBeenCalled());
    expect(screen.queryByRole("button", { name: /Billing history/ })).toBeNull();
  });
});
