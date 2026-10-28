import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import AnalyticsRoute from "@/components/AnalyticsRoute";

const authState = vi.hoisted(() => ({
  user: null as null | { id: number; is_admin?: boolean; is_promotional?: boolean; can_use_analytics?: boolean },
}));
vi.mock("@/hooks/useAuth", () => ({ useAuth: () => ({ user: authState.user, loading: false }) }));

const renderPage = () =>
  render(
    <MemoryRouter initialEntries={["/dashboard/analytics/posts"]}>
      <Routes>
        <Route path="/dashboard" element={<div>Dashboard home</div>} />
        <Route
          path="/dashboard/analytics/posts"
          element={<AnalyticsRoute><div>Post Performance</div></AnalyticsRoute>}
        />
      </Routes>
    </MemoryRouter>,
  );

describe("AnalyticsRoute", () => {
  it("renders the page for a user whose plan includes analytics", () => {
    authState.user = { id: 1, can_use_analytics: true };
    renderPage();
    expect(screen.getByText("Post Performance")).toBeInTheDocument();
  });

  it("sends a user on a lower tier to the dashboard home", () => {
    authState.user = { id: 2, can_use_analytics: false };
    renderPage();
    expect(screen.queryByText("Post Performance")).toBeNull();
    expect(screen.getByText("Dashboard home")).toBeInTheDocument();
  });

  it("keeps admins and promotional customers in", () => {
    authState.user = { id: 3, is_admin: true };
    renderPage();
    expect(screen.getByText("Post Performance")).toBeInTheDocument();

    authState.user = { id: 4, is_promotional: true };
    renderPage();
    expect(screen.getAllByText("Post Performance").length).toBeGreaterThan(0);
  });
});
