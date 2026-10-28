import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import OnboardingGate from "@/components/OnboardingGate";

// The card gate in the app shell. A signup is never sent to Billing while its
// 7-day window is open (with SIGNUP_CARD_REQUIRED=false it doesn't even see a
// card step); the moment the window closes without a plan, every dashboard
// page redirects to Billing until a card is added.
const authState = vi.hoisted(() => ({ user: null as null | Record<string, unknown> }));
vi.mock("@/hooks/useAuth", () => ({ useAuth: () => ({ user: authState.user, loading: false }) }));

const day = 86_400_000;
const future = () => new Date(Date.now() + 3 * day).toISOString();
const past = () => new Date(Date.now() - 3_600_000).toISOString(); // closed an hour ago

const renderAt = (path: string) =>
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/onboarding" element={<div>Onboarding wizard</div>} />
        <Route path="/dashboard/post" element={<OnboardingGate><div>New post page</div></OnboardingGate>} />
        <Route path="/dashboard/billing" element={<OnboardingGate><div>Billing page</div></OnboardingGate>} />
      </Routes>
    </MemoryRouter>,
  );

const cardFree = (attrs: Record<string, unknown>) => ({ id: 1, card_required: false, has_active_plan: false, ...attrs });

describe("OnboardingGate — card-free trial", () => {
  it("sends a new signup inside its window to onboarding, not to Billing", () => {
    authState.user = cardFree({ promo_expires_at: future(), onboarding_completed_at: null });
    renderAt("/dashboard/post");
    expect(screen.getByText("Onboarding wizard")).toBeInTheDocument();
    expect(screen.queryByText("Billing page")).toBeNull();
  });

  it("lets an onboarded signup use the app until the window closes, with no card", () => {
    authState.user = cardFree({ promo_expires_at: future(), onboarding_completed_at: "2026-10-01T00:00:00Z" });
    renderAt("/dashboard/post");
    expect(screen.getByText("New post page")).toBeInTheDocument();
  });

  it("card-gates every page once the window has closed", () => {
    authState.user = cardFree({ promo_expires_at: past(), onboarding_completed_at: "2026-10-01T00:00:00Z", access_expired: true });
    renderAt("/dashboard/post");
    expect(screen.getByText("Billing page")).toBeInTheDocument();
    expect(screen.queryByText("New post page")).toBeNull();
  });

  it("card-gates on the date alone, for a session cached before the window closed", () => {
    // The backend's access_expired was false when this session was fetched.
    authState.user = cardFree({ promo_expires_at: past(), onboarding_completed_at: "2026-10-01T00:00:00Z", access_expired: false });
    renderAt("/dashboard/post");
    expect(screen.getByText("Billing page")).toBeInTheDocument();
  });

  it("keeps Billing itself open so the card can be added", () => {
    authState.user = cardFree({ promo_expires_at: past(), onboarding_completed_at: "2026-10-01T00:00:00Z", access_expired: true });
    renderAt("/dashboard/billing");
    expect(screen.getByText("Billing page")).toBeInTheDocument();
  });

  it("card-gates even a user who never finished onboarding, once the window is gone", () => {
    authState.user = cardFree({ promo_expires_at: past(), onboarding_completed_at: null, access_expired: true });
    renderAt("/dashboard/post");
    expect(screen.getByText("Billing page")).toBeInTheDocument();
    expect(screen.queryByText("Onboarding wizard")).toBeNull();
  });

  it("lets a user who added a card through after the window", () => {
    authState.user = cardFree({ promo_expires_at: past(), onboarding_completed_at: "2026-10-01T00:00:00Z", has_active_plan: true });
    renderAt("/dashboard/post");
    expect(screen.getByText("New post page")).toBeInTheDocument();
  });
});
