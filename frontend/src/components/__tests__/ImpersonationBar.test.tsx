import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";

const api = vi.hoisted(() => ({ logout: vi.fn() }));
const setAuthData = vi.hoisted(() => vi.fn());

vi.mock("@/lib/api-service", async (original) => ({
  ...(await original<typeof import("@/lib/api-service")>()),
  viewsMaxApi: api,
}));
vi.mock("@/hooks/useAuth", () => ({
  useAuth: () => ({ user: { id: 2, email: "customer@example.com" }, setAuthData }),
}));

import ImpersonationBar from "@/components/ImpersonationBar";
import { IMPERSONATOR_KEY, startImpersonation, endImpersonation, readImpersonator } from "@/lib/impersonation";

const adminSession = { user: { id: 1, email: "admin@example.com" }, token: "admin-token", token_type: "Bearer" };
const userSession = { user: { id: 2, email: "customer@example.com" }, token: "user-token", token_type: "Bearer" };

// The test setup stubs localStorage with vi.fn()s; back them with a real map here.
const store = new Map<string, string>();
beforeEach(() => {
  vi.clearAllMocks();
  store.clear();
  vi.mocked(localStorage.getItem).mockImplementation((k: string) => store.get(k) ?? null);
  vi.mocked(localStorage.setItem).mockImplementation((k: string, v: string) => { store.set(k, v); });
  vi.mocked(localStorage.removeItem).mockImplementation((k: string) => { store.delete(k); });
  api.logout.mockResolvedValue({ success: true });
});

describe("impersonation helpers", () => {
  it("parks the admin session, clears per-user caches and swaps in the user", () => {
    store.set("active_subscription", "x");
    startImpersonation(adminSession, userSession, setAuthData);

    expect(readImpersonator()).toEqual(adminSession);
    expect(store.has("active_subscription")).toBe(false);
    expect(setAuthData).toHaveBeenCalledWith(userSession);
  });

  it("restores the admin session and removes the stash on return", () => {
    store.set(IMPERSONATOR_KEY, JSON.stringify(adminSession));

    expect(endImpersonation(setAuthData)).toBe(true);
    expect(setAuthData).toHaveBeenCalledWith(adminSession);
    expect(store.has(IMPERSONATOR_KEY)).toBe(false);
    expect(endImpersonation(setAuthData)).toBe(false);
  });
});

describe("ImpersonationBar", () => {
  it("renders nothing in a normal session", () => {
    const { container } = render(<MemoryRouter><ImpersonationBar /></MemoryRouter>);
    expect(container).toBeEmptyDOMElement();
  });

  it("shows who is logged in as whom and returns to admin", async () => {
    store.set(IMPERSONATOR_KEY, JSON.stringify(adminSession));
    render(<MemoryRouter><ImpersonationBar /></MemoryRouter>);

    expect(screen.getByText("customer@example.com")).toBeInTheDocument();
    expect(screen.getByText(/you are admin@example.com/)).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: /return to admin/i }));

    await waitFor(() => expect(api.logout).toHaveBeenCalled());
    await waitFor(() => expect(setAuthData).toHaveBeenCalledWith(adminSession));
    expect(store.has(IMPERSONATOR_KEY)).toBe(false);
  });
});
