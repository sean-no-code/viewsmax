import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import AdminRoute from "@/components/AdminRoute";

const authState = vi.hoisted(() => ({ user: null as null | { id: number; is_admin?: boolean; is_promotional?: boolean } }));
vi.mock("@/hooks/useAuth", () => ({ useAuth: () => ({ user: authState.user, loading: false }) }));

const renderAt = (path: string) =>
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/dashboard" element={<div>Dashboard home</div>} />
        <Route path="/dashboard/admin" element={<AdminRoute><div>Admin page</div></AdminRoute>} />
        <Route path="/dashboard/analytics/profile" element={<AdminRoute allowPromo><div>Limited release page</div></AdminRoute>} />
      </Routes>
    </MemoryRouter>,
  );

describe("AdminRoute", () => {
  it("renders the page for an admin", () => {
    authState.user = { id: 1, is_admin: true };
    renderAt("/dashboard/admin");
    expect(screen.getByText("Admin page")).toBeInTheDocument();
  });

  it("sends a non-admin to the dashboard home", () => {
    authState.user = { id: 2, is_admin: false };
    renderAt("/dashboard/admin");
    expect(screen.queryByText("Admin page")).toBeNull();
    expect(screen.getByText("Dashboard home")).toBeInTheDocument();
  });

  it("sends a user without the admin flag to the dashboard home", () => {
    authState.user = { id: 3 };
    renderAt("/dashboard/admin");
    expect(screen.getByText("Dashboard home")).toBeInTheDocument();
  });

  it("keeps a promotional customer out of an admin-only page", () => {
    authState.user = { id: 4, is_promotional: true };
    renderAt("/dashboard/admin");
    expect(screen.getByText("Dashboard home")).toBeInTheDocument();
  });
});

describe("AdminRoute allowPromo", () => {
  it.each([
    ["an admin", { id: 1, is_admin: true }],
    ["a promotional customer", { id: 2, is_promotional: true }],
  ])("renders the page for %s", (_, user) => {
    authState.user = user;
    renderAt("/dashboard/analytics/profile");
    expect(screen.getByText("Limited release page")).toBeInTheDocument();
  });

  it("sends a regular customer to the dashboard home", () => {
    authState.user = { id: 3, is_admin: false, is_promotional: false };
    renderAt("/dashboard/analytics/profile");
    expect(screen.queryByText("Limited release page")).toBeNull();
    expect(screen.getByText("Dashboard home")).toBeInTheDocument();
  });
});
