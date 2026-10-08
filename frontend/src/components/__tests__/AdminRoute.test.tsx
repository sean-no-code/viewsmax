import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import AdminRoute from "@/components/AdminRoute";

const authState = vi.hoisted(() => ({ user: null as null | { id: number; is_admin?: boolean } }));
vi.mock("@/hooks/useAuth", () => ({ useAuth: () => ({ user: authState.user, loading: false }) }));

const renderAt = (path: string) =>
  render(
    <MemoryRouter initialEntries={[path]}>
      <Routes>
        <Route path="/dashboard" element={<div>Dashboard home</div>} />
        <Route path="/dashboard/analytics/profile" element={<AdminRoute><div>Admin page</div></AdminRoute>} />
      </Routes>
    </MemoryRouter>,
  );

describe("AdminRoute", () => {
  it("renders the page for an admin", () => {
    authState.user = { id: 1, is_admin: true };
    renderAt("/dashboard/analytics/profile");
    expect(screen.getByText("Admin page")).toBeInTheDocument();
  });

  it("sends a non-admin to the dashboard home", () => {
    authState.user = { id: 2, is_admin: false };
    renderAt("/dashboard/analytics/profile");
    expect(screen.queryByText("Admin page")).toBeNull();
    expect(screen.getByText("Dashboard home")).toBeInTheDocument();
  });

  it("sends a user without the admin flag to the dashboard home", () => {
    authState.user = { id: 3 };
    renderAt("/dashboard/analytics/profile");
    expect(screen.getByText("Dashboard home")).toBeInTheDocument();
  });
});
