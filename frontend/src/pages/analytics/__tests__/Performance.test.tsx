import { describe, expect, it } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { MemoryRouter, Route, Routes, useLocation } from "react-router-dom";
import Performance, { type PerformanceTab } from "../Performance";
import { ACCOUNTS, ALL_ACCOUNT_IDS, buildHistory, postsData, presetRange, profileData } from "@/lib/analytics-performance-mock";

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
  it("shows the profile KPIs, both charts and every account on the default 28-day range", () => {
    renderPage("profile");
    expect(screen.getByRole("heading", { level: 1 })).toHaveTextContent("Profile performance");
    const expected = profileData(buildHistory(), presetRange("28d", new Date()), ALL_ACCOUNT_IDS);
    const kpis = screen.getByRole("region", { name: "Key metrics" });
    for (const k of expected.kpis) {
      expect(within(kpis).getByText(k.label)).toBeInTheDocument();
      expect(within(kpis).getByText(k.value)).toBeInTheDocument();
    }
    expect(screen.getByRole("img", { name: "Follower growth chart" })).toBeInTheDocument();
    expect(screen.getByRole("img", { name: "Engagement chart" })).toBeInTheDocument();
    const follower = screen.getByRole("region", { name: "Follower growth" });
    for (const a of ACCOUNTS) expect(within(follower).getAllByText(a.handle).length).toBeGreaterThan(0);
    expect(screen.getByRole("button", { name: "Date range" })).toHaveTextContent("Last 28 days");
    expect(screen.getByRole("button", { name: "Sources" })).toHaveTextContent("All sources");
  });

  it("reads sources and range from the URL and narrows the tables", () => {
    renderPage("profile", "?sources=yt1,yt2&range=7d");
    expect(screen.getByRole("button", { name: "Sources" })).toHaveTextContent("All YouTube accounts");
    expect(screen.getByRole("button", { name: "Date range" })).toHaveTextContent("Last 7 days");
    const follower = screen.getByRole("region", { name: "Follower growth" });
    expect(within(follower).getAllByText("@viewsmax.shorts")).toHaveLength(1);
    expect(within(follower).queryByText("@viewsmax_help")).toBeNull();
  });

  it("switches the follower chart to net-per-day bars and writes it to the URL", async () => {
    const user = userEvent.setup();
    renderPage("profile");
    await user.click(screen.getByRole("button", { name: "Net per day" }));
    expect(screen.getByRole("button", { name: "Net per day" })).toHaveAttribute("aria-pressed", "true");
    expect(screen.getByTestId("search")).toHaveTextContent("view=net");
    const chart = screen.getByRole("img", { name: "Follower growth chart" });
    expect(chart.querySelectorAll("rect[rx='2']").length).toBe(28);
  });

  it("lists the posts for the period and toggles between grid and list", async () => {
    const user = userEvent.setup();
    renderPage("posts");
    expect(screen.getByRole("heading", { level: 1 })).toHaveTextContent("Post performance");
    const expected = postsData(buildHistory(), presetRange("28d", new Date()), ALL_ACCOUNT_IDS, "rate");
    expect(screen.getByText(`${expected.length} posts published in this period`)).toBeInTheDocument();
    expect(screen.getAllByRole("article")).toHaveLength(expected.length);
    expect(screen.getAllByRole("article")[0]).toHaveAccessibleName(expected[0].caption);

    await user.click(screen.getByRole("button", { name: "List" }));
    expect(screen.queryAllByRole("article")).toHaveLength(0);
    const table = screen.getByRole("table", { name: "Posts" });
    expect(within(table).getAllByRole("row")).toHaveLength(expected.length + 1);
    expect(screen.getByTestId("search")).toHaveTextContent("layout=list");
  });

  it("applies the sort from the URL", () => {
    renderPage("posts", "?sort=imp&layout=list");
    const expected = postsData(buildHistory(), presetRange("28d", new Date()), ALL_ACCOUNT_IDS, "imp");
    const rows = within(screen.getByRole("table", { name: "Posts" })).getAllByRole("row").slice(1);
    expect(rows[0]).toHaveTextContent(expected[0].caption);
    expect(rows[rows.length - 1]).toHaveTextContent(expected[expected.length - 1].caption);
  });
});
