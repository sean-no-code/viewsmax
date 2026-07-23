import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { ConversionEventsModal } from "@/pages/analytics/OfferDetail";
import { viewsMaxApi, type GoalType } from "@/lib/api-service";
import type { PageMetric } from "@/lib/analytics-model";

vi.mock("sonner", () => ({ toast: { error: vi.fn(), success: vi.fn() } }));

// The goal types are the DB-owned reference list (GET /api/goal-types).
const goalTypes: GoalType[] = [
  { value: "conversion", label: "Purchase" },
  { value: "newsletter", label: "Newsletter" },
];

const page = {
  id: "1",
  name: "Test offer",
  url: "example.com",
  live: true,
  conv: { url: "/thank-you", value: 0, label: "Purchase" },
  goals: [],
  visits: 0,
  conversions: 0,
  rev: 0,
  cr: 0,
  raw: { id: 1, offer_url: "https://example.com" },
} as unknown as PageMetric;

describe("ConversionEventsModal — custom goal type", () => {
  beforeEach(() => {
    vi.restoreAllMocks();
    // Persist succeeds and echoes the goals back so the form closes.
    vi.spyOn(viewsMaxApi, "updateTrackingEvent").mockResolvedValue({
      success: true,
      data: { goals: [] },
    } as never);
  });

  it("stores a custom event type verbatim", async () => {
    const user = userEvent.setup();
    render(<ConversionEventsModal page={page} goalTypes={goalTypes} onClose={vi.fn()} onSaved={vi.fn()} />);

    await user.click(screen.getByRole("button", { name: /add conversion event/i }));
    await user.type(screen.getByPlaceholderText("/thank-you"), "/demo");

    // Pick "Custom…" — this should reveal the free-text event-name field.
    await user.selectOptions(screen.getByRole("combobox"), "custom");
    const customInput = screen.getByPlaceholderText("e.g. demo-requested");
    await user.type(customInput, "demo-requested");

    await user.click(screen.getByRole("button", { name: /^add event$/i }));

    await waitFor(() => expect(viewsMaxApi.updateTrackingEvent).toHaveBeenCalled());
    const [, payload] = (viewsMaxApi.updateTrackingEvent as unknown as ReturnType<typeof vi.fn>).mock.calls[0];
    expect(payload.goals).toEqual([
      expect.objectContaining({ event_type: "demo-requested", conversion_url: "/demo" }),
    ]);
  });

  it("does not show the custom field for a built-in DB type, and sends its value", async () => {
    const user = userEvent.setup();
    render(<ConversionEventsModal page={page} goalTypes={goalTypes} onClose={vi.fn()} onSaved={vi.fn()} />);

    await user.click(screen.getByRole("button", { name: /add conversion event/i }));
    await user.type(screen.getByPlaceholderText("/thank-you"), "/subscribed");
    await user.selectOptions(screen.getByRole("combobox"), "newsletter");

    // Built-in selected → no free-text custom field.
    expect(screen.queryByPlaceholderText("e.g. demo-requested")).not.toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: /^add event$/i }));

    await waitFor(() => expect(viewsMaxApi.updateTrackingEvent).toHaveBeenCalled());
    const [, payload] = (viewsMaxApi.updateTrackingEvent as unknown as ReturnType<typeof vi.fn>).mock.calls[0];
    expect(payload.goals).toEqual([
      expect.objectContaining({ event_type: "newsletter" }),
    ]);
  });
});
