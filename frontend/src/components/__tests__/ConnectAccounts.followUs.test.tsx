import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";

const api = vi.hoisted(() => ({
  getConnections: vi.fn(),
  getSocialAccounts: vi.fn(),
  getSocialPlatforms: vi.fn(),
}));
const connectSocialPlatform = vi.hoisted(() => vi.fn());

vi.mock("@/lib/api-service", async (original) => ({
  ...(await original<typeof import("@/lib/api-service")>()),
  viewsMaxApi: api,
}));
vi.mock("@/lib/oauth-connect", () => ({
  connectProvider: vi.fn(),
  connectSocialPlatform,
  isProviderConfigured: () => true,
  PROVIDER_CONFIG: {},
}));
vi.mock("@/components/BeehiivIntegration", () => ({ BeehiivIntegration: () => null }));
vi.mock("@/components/post/useAccountDirectory", () => ({ refreshAccountDirectory: vi.fn() }));

import ConnectAccounts from "@/components/ConnectAccounts";

const platform = (name: string, follow_us: string | null = null) => ({
  platform: name, label: name, enabled: true, configured: true, uses_oauth: true, follow_us,
});

// "Follow us" appears only where the backend has an account to follow (X,
// Bluesky), is ticked by default, and the choice travels with the connect call.
describe("ConnectAccounts — Follow us", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    api.getConnections.mockResolvedValue({ success: true, data: [] });
    api.getSocialAccounts.mockResolvedValue({ success: true, data: [] });
    api.getSocialPlatforms.mockResolvedValue({
      success: true,
      data: [platform("x", "viewsmax"), platform("bluesky", "viewsmax.bsky.social"), platform("linkedin")],
    });
    connectSocialPlatform.mockResolvedValue([]);
  });

  const xCard = async () => (await screen.findByText(/Follow us \(@viewsmax\)/)).closest("div.rounded-lg") as HTMLElement;

  it("shows a ticked box only on platforms with an account to follow", async () => {
    render(<ConnectAccounts mode="settings" />);

    await xCard();
    const boxes = screen.getAllByRole("checkbox");
    expect(boxes).toHaveLength(2);
    for (const box of boxes) expect(box).toBeChecked();
  });

  it("connects with follow on by default and off once unticked", async () => {
    render(<ConnectAccounts mode="settings" />);
    const card = await xCard();
    const connect = card.querySelector("button:not([role=checkbox])") as HTMLButtonElement;

    fireEvent.click(connect);
    await waitFor(() => expect(connectSocialPlatform).toHaveBeenLastCalledWith("x", true));

    fireEvent.click(card.querySelector("[role=checkbox]")!);
    fireEvent.click(connect);
    await waitFor(() => expect(connectSocialPlatform).toHaveBeenLastCalledWith("x", false));
  });
});
