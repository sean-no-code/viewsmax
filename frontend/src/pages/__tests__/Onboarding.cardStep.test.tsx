import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";

// Onboarding's card step follows SIGNUP_CARD_REQUIRED (the user payload's
// card_required). Required: connect → choose a plan and add a card. Card-free
// trial: connect → straight into the app; the card comes when the window closes.
const authState = vi.hoisted(() => ({ user: null as null | Record<string, unknown> }));
const api = vi.hoisted(() => ({ completeOnboarding: vi.fn() }));
const refreshUser = vi.hoisted(() => vi.fn());

vi.mock("@/hooks/useAuth", () => ({ useAuth: () => ({ user: authState.user, loading: false, refreshUser, signOut: vi.fn() }) }));
vi.mock("@/lib/api-service", async (original) => ({
  ...(await original<typeof import("@/lib/api-service")>()),
  viewsMaxApi: api,
}));
vi.mock("@/components/ConnectAccounts", () => ({ default: () => <div>Connect accounts list</div> }));
vi.mock("@/components/onboarding/TrialCheckout", () => ({ default: () => <div>Card form</div> }));

import Onboarding from "@/pages/Onboarding";

const window_ = () => new Date(Date.now() + 5 * 86_400_000).toISOString();
const signup = (attrs: Record<string, unknown>) => ({
  id: 1, email: "new@example.com", has_active_plan: false, has_active_subscription: true, is_promotional: false,
  onboarding_completed_at: null, promo_expires_at: window_(), connections_count: 0, ...attrs,
});

const renderOnboarding = () => render(<MemoryRouter><Onboarding /></MemoryRouter>);

describe("Onboarding card step", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    api.completeOnboarding.mockResolvedValue({ success: true });
  });

  it("asks for a card after connecting when a card is required", async () => {
    authState.user = signup({ card_required: true });
    renderOnboarding();

    expect(screen.getByText("Choose your plan")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Skip for now" }));

    expect(await screen.findByText("Card form")).toBeInTheDocument();
    expect(api.completeOnboarding).not.toHaveBeenCalled();
  });

  it("has no card step in a card-free trial: skipping connect finishes onboarding", async () => {
    authState.user = signup({ card_required: false });
    renderOnboarding();

    expect(screen.queryByText("Choose your plan")).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: "Skip for now" }));

    await waitFor(() => expect(api.completeOnboarding).toHaveBeenCalledTimes(1));
    expect(screen.queryByText("Card form")).toBeNull();
  });

  it("finishes straight away for a card-free signup that already connected an account", async () => {
    authState.user = signup({ card_required: false, connections_count: 1 });
    renderOnboarding();

    await waitFor(() => expect(api.completeOnboarding).toHaveBeenCalledTimes(1));
    expect(screen.queryByText("Card form")).toBeNull();
  });

  it("puts the card step back once a card-free window has closed", async () => {
    authState.user = signup({ card_required: false, promo_expires_at: new Date(Date.now() - 3_600_000).toISOString(), connections_count: 1 });
    renderOnboarding();

    expect(await screen.findByText("Card form")).toBeInTheDocument();
    expect(api.completeOnboarding).not.toHaveBeenCalled();
  });
});
