import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";

// Onboarding's card step. The wording and the "due today" amount follow the
// user's free window: open = a trial that charges the $1 activation fee now
// and the plan price when it closes; closed = the plan price today.
const authState = vi.hoisted(() => ({ user: null as null | Record<string, unknown> }));
const api = vi.hoisted(() => ({ getPlans: vi.fn(), createStripeSetupIntent: vi.fn() }));

vi.mock("@/hooks/useAuth", () => ({ useAuth: () => ({ user: authState.user, loading: false }) }));
vi.mock("@/lib/api-service", async (original) => ({
  ...(await original<typeof import("@/lib/api-service")>()),
  viewsMaxApi: api,
}));
// Demo-mode payment form: no Stripe Elements, so the copy can be asserted.
vi.mock("@/lib/mock-api", async (original) => ({
  ...(await original<typeof import("@/lib/mock-api")>()),
  isMockApi: () => true,
}));

import TrialCheckout from "@/components/onboarding/TrialCheckout";

const plan = { id: 1, name: "creator", display_name: "Creator", price: "29", currency: "USD", is_active: true, stripe_price_id: "price_creator", features: ["5 channels"] };

describe("TrialCheckout", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    api.getPlans.mockResolvedValue({ success: true, data: [plan] });
  });

  it("charges the $1 activation fee today while the free window is open", async () => {
    authState.user = { id: 1, email: "jane@example.com", promo_expires_at: new Date(Date.now() + 5 * 86_400_000).toISOString() };
    render(<TrialCheckout onSubscribed={() => {}} />);

    expect((await screen.findAllByText("Creator")).length).toBeGreaterThan(0);
    expect(screen.getByText("$1.00")).toBeInTheDocument();
    expect(screen.getByText(/^\$1 today · free until/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Start free trial — \$1 today/ })).toBeInTheDocument();
    expect(screen.getByText(/A \$1 activation fee is charged now/)).toBeInTheDocument();
    expect(screen.queryByText("$29.00")).toBeNull();
  });

  it("charges the plan price today once the window has closed", async () => {
    authState.user = { id: 1, email: "jane@example.com", promo_expires_at: new Date(Date.now() - 86_400_000).toISOString() };
    render(<TrialCheckout onSubscribed={() => {}} />);

    expect((await screen.findAllByText("Creator")).length).toBeGreaterThan(0);
    expect(screen.getByText("$29.00")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Subscribe — \$29 today/ })).toBeInTheDocument();
    expect(screen.queryByText(/activation fee/)).toBeNull();
  });
});
