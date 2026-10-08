import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes, useLocation } from "react-router-dom";
import Performance, { type PerformanceTab } from "../Performance";
import { FIXTURE } from "@/test/fixtures/performance";

const api = vi.hoisted(() => ({ getAnalyticsPerformance: vi.fn() }));
vi.mock("@/lib/api-service", async (importOriginal) => {
  const original = await importOriginal<typeof import("@/lib/api-service")>();
  return { ...original, viewsMaxApi: { ...original.viewsMaxApi, getAnalyticsPerformance: api.getAnalyticsPerformance } };
});

function LocationProbe() { const l = useLocation(); return <div data-testid="search">{l.search}</div>; }

const renderPage = (tab: PerformanceTab, search = "") =>
  render(
    <MemoryRouter initialEntries={[`/dashboard/analytics/${tab}${search}`]}>
      <Routes>
        <Route path="/dashboard/analytics/:tab" element={<><Performance tab={tab} /><LocationProbe /></>} />
      </Routes>
    </MemoryRouter>,
  );

describe("Performance page", () => {
  beforeEach(() => {
    api.getAnalyticsPerformance.mockReset();
    api.getAnalyticsPerformance.mockResolvedValue({ success: true, data: FIXTURE });
  });

  it("requests the selected range and shows KPIs, charts and every account", async () => {
    renderPage("profile", "?range=7d");
    expect(screen.getByRole("heading", { level: 1 })).toHaveTextContent("Profile performance");
    const kpis = await screen.findByRole("region", { name: "Key metrics" });
    expect(api.getAnalyticsPerformance).toHaveBeenCalledTimes(1);
    const { from, to } = api.getAnalyticsPerformance.mock.calls[0][0];
    expect(Math.round((to.getTime() - from.getTime()) / 864e5)).toBe(6);

    expect(within(kpis).getByText("Total followers").nextSibling).toHaveTextContent("120");
    expect(within(kpis).getByText("Engagement rate (per view)").nextSibling).toHaveTextContent("6.0%");
    expect(screen.getByRole("img", { name: "Follower growth chart" })).toBeInTheDocument();
    expect(screen.getByRole("img", { name: "Engagement chart" })).toBeInTheDocument();
    const follower = screen.getByRole("region", { name: "Follower growth" });
    for (const handle of ["@seancreates", "@sean_x", "Sean Facer", "@seantok", "@sean.ig"]) expect(within(follower).getByText(handle)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Sources" })).toHaveTextContent("All sources");
    expect(screen.getByRole("button", { name: "Date range" })).toHaveTextContent("Last 7 days");
  });

  it("explains every account it can't get data from", async () => {
    renderPage("profile");
    await screen.findByRole("region", { name: "Key metrics" });
    const alerts = [...screen.getAllByRole("alert"), ...screen.getAllByRole("status")].map((el) => el.textContent);
    expect(alerts.find((t) => t?.includes("Reconnect @sean_x (X)"))).toBeTruthy();
    expect(screen.getByRole("link", { name: "Open Connections" })).toHaveAttribute("href", "/dashboard/connections");
    expect(alerts.find((t) => t?.includes("LinkedIn doesn't expose follower or post stats") && t.includes("Sean Facer"))).toBeTruthy();
    expect(alerts.find((t) => t?.includes("Couldn't fetch the follower count for @seantok (TikTok): HTTP 403 from TikTok"))).toBeTruthy();
    expect(alerts.find((t) => t?.includes("No data yet for @sean.ig (Instagram)"))).toBeTruthy();
    // The healthy account raises nothing.
    expect(alerts.find((t) => t?.includes("@seancreates"))).toBeUndefined();
  });

  it("narrows to the sources in the URL", async () => {
    renderPage("profile", "?sources=1");
    const follower = await screen.findByRole("region", { name: "Follower growth" });
    expect(screen.getByRole("button", { name: "Sources" })).toHaveTextContent("YouTube @seancreates");
    expect(within(follower).getByText("@seancreates")).toBeInTheDocument();
    expect(within(follower).queryByText("@sean_x")).toBeNull();
  });

  it("switches the follower chart to net-per-day bars and writes it to the URL", async () => {
    const user = userEvent.setup();
    renderPage("profile", "?range=7d");
    await screen.findByRole("region", { name: "Key metrics" });
    await user.click(screen.getByRole("button", { name: "Net per day" }));
    expect(screen.getByTestId("search")).toHaveTextContent("view=net");
    expect(screen.getByRole("img", { name: "Follower growth chart" }).querySelectorAll("rect[rx='2']").length).toBe(7);
  });

  it("lists the posts for the period, toggles layout and honours the sort", async () => {
    const user = userEvent.setup();
    renderPage("posts", "?sort=views");
    expect(screen.getByRole("heading", { level: 1 })).toHaveTextContent("Post performance");
    expect(await screen.findByText("3 posts published in this period")).toBeInTheDocument();
    const cards = screen.getAllByRole("article");
    expect(cards.map((c) => c.getAttribute("aria-label"))).toEqual(["Thumbnail test results", "Channel audit", "A thread on retention"]);
    expect(within(cards[0]).getByRole("link", { name: "Thumbnail test results" })).toHaveAttribute("href", "https://youtu.be/a");
    expect(within(cards[0]).getByText("Views").nextSibling).toHaveTextContent("600"); // the sort key is the headline stat

    await user.click(screen.getByRole("button", { name: "List" }));
    const table = screen.getByRole("table", { name: "Posts" });
    const rows = within(table).getAllByRole("row");
    expect(rows).toHaveLength(4);
    expect(within(rows[3]).getAllByRole("cell")[3]).toHaveTextContent("–"); // no views on X → no rate
    expect(screen.getByTestId("search")).toHaveTextContent("layout=list");
  });

  it("shows an empty state when nothing is connected and the error when the request fails", async () => {
    api.getAnalyticsPerformance.mockResolvedValueOnce({ success: true, data: { ...FIXTURE, accounts: [], posts: [] } });
    renderPage("profile");
    expect(await screen.findByText(/No connected accounts yet/)).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "Connect a platform" })).toHaveAttribute("href", "/dashboard/connections");

    api.getAnalyticsPerformance.mockResolvedValueOnce({ success: false, error: "Failed to fetch analytics: 500" });
    renderPage("posts");
    expect(await screen.findByText("Failed to fetch analytics: 500")).toBeInTheDocument();
  });
});
