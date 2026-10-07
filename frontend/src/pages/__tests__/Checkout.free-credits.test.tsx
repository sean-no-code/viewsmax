import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";

// A user on free credits is charged today when they subscribe (the backend
// creates the subscription with no trial), so checkout must not promise a
// free trial or "$0.00 due today".
vi.mock("@/hooks/useAuth", () => ({
  useAuth: () => ({
    user: { on_free_credits: true, has_active_plan: false, promo_expires_at: null },
    refreshUser: vi.fn(),
  }),
}));
vi.mock("@/components/StripeTrialStep", () => ({ default: () => <div>card form</div> }));

import Checkout from "../Checkout";

describe("Checkout for a user on free credits", () => {
  it("says they're charged today and that the free credits were the trial", () => {
    render(
      <MemoryRouter initialEntries={["/checkout?plan=Starter&price=29&period=month"]}>
        <Checkout />
      </MemoryRouter>,
    );

    expect(screen.getByText("Your free credits were your trial — add your card to keep going")).toBeInTheDocument();
    expect(screen.getByText("$29.00")).toBeInTheDocument();
    expect(screen.getByText("Your free credits were your trial — charged today")).toBeInTheDocument();
    expect(screen.getByText("$29/month, starting today")).toBeInTheDocument();
    expect(screen.queryByText("$0.00")).not.toBeInTheDocument();
    expect(screen.queryByText(/start your free trial/i)).not.toBeInTheDocument();
  });
});
