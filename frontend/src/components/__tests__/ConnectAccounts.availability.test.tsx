import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";

const api = vi.hoisted(() => ({
  getConnections: vi.fn(),
  getSocialAccounts: vi.fn(),
  getSocialPlatforms: vi.fn(),
}));

vi.mock("@/lib/api-service", async (original) => ({
  ...(await original<typeof import("@/lib/api-service")>()),
  viewsMaxApi: api,
}));
vi.mock("@/lib/oauth-connect", () => ({
  connectProvider: vi.fn(),
  connectSocialPlatform: vi.fn(),
  isProviderConfigured: () => true,
  PROVIDER_CONFIG: {},
}));
vi.mock("@/components/BeehiivIntegration", () => ({ BeehiivIntegration: () => null }));
vi.mock("@/components/post/useAccountDirectory", () => ({ refreshAccountDirectory: vi.fn() }));

import ConnectAccounts from "@/components/ConnectAccounts";

const platform = (name: string, overrides: Record<string, unknown> = {}) => ({
  platform: name, label: name, enabled: true, configured: true, available: true,
  uses_oauth: true, follow_us: null, ...overrides,
});

// `available` is the per-user answer: a platform in limited release (Facebook,
// Instagram and Threads are promotional-customer-only until Meta App Review
// lands) is configured on the backend but must still read "Coming soon" here.
describe("ConnectAccounts — platform availability", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    api.getConnections.mockResolvedValue({ success: true, data: [] });
    api.getSocialAccounts.mockResolvedValue({ success: true, data: [] });
  });

  it("shows Coming soon with Connect disabled when the platform is unavailable to this user", async () => {
    api.getSocialPlatforms.mockResolvedValue({
      success: true,
      data: [platform("instagram", { available: false }), platform("x")],
    });

    render(<ConnectAccounts mode="settings" />);
    await screen.findByText("Coming soon");

    expect(screen.getAllByText("Coming soon")).toHaveLength(1);
    expect(screen.getAllByText("Not connected").length).toBeGreaterThan(0);
  });

  it("offers Connect when the platform is available, even though nothing is connected", async () => {
    api.getSocialPlatforms.mockResolvedValue({
      success: true,
      data: [platform("instagram"), platform("x")],
    });

    render(<ConnectAccounts mode="settings" />);
    await screen.findAllByText("Not connected");

    expect(screen.queryByText("Coming soon")).toBeNull();
  });

  it("falls back to `configured` when the backend predates `available`", async () => {
    // Frontend deployed ahead of the backend: no `available` in the payload.
    api.getSocialPlatforms.mockResolvedValue({
      success: true,
      data: [platform("instagram", { available: undefined, configured: false }), platform("x", { available: undefined })],
    });

    render(<ConnectAccounts mode="settings" />);
    await screen.findByText("Coming soon");

    expect(screen.getAllByText("Coming soon")).toHaveLength(1);
  });
});
