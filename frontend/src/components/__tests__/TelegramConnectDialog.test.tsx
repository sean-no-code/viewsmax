import { describe, it, expect, beforeEach, afterEach, vi } from "vitest";
import { render, screen, act } from "@testing-library/react";
import { TelegramConnectDialog } from "@/components/TelegramConnectDialog";
import { viewsMaxApi } from "@/lib/api-service";

const refusal = "This code must be posted by an administrator of the channel or group.";

const advance = async (ms: number) => {
  await act(async () => {
    await vi.advanceTimersByTimeAsync(ms);
  });
};

beforeEach(() => {
  vi.useFakeTimers({ shouldAdvanceTime: true });
  vi.spyOn(viewsMaxApi, "getTelegramConnectCode").mockResolvedValue({
    success: true,
    data: {
      code: "ABC123",
      command: "/connect ABC123",
      bot_username: "viewsmax_bot",
      bot_url: "https://t.me/viewsmax_bot",
      expires_at: new Date(Date.now() + 15 * 60 * 1000).toISOString(),
    },
  } as never);
});

afterEach(() => {
  vi.restoreAllMocks();
  vi.useRealTimers();
});

describe("TelegramConnectDialog", () => {
  it("keeps a refusal on screen when the next check answers pending", async () => {
    vi.spyOn(viewsMaxApi, "connectTelegram")
      .mockResolvedValueOnce({ success: false, error: refusal } as never)
      .mockResolvedValue({ success: false, pending: true } as never);

    render(<TelegramConnectDialog open onOpenChange={() => {}} onConnected={() => {}} />);
    await screen.findByText("/connect ABC123");

    await advance(3000);
    expect(screen.getByText(refusal)).toBeInTheDocument();

    await advance(3000);
    await advance(3000);
    expect(viewsMaxApi.connectTelegram).toHaveBeenCalledTimes(3);
    expect(screen.getByText(refusal)).toBeInTheDocument();
  });

  it("shows the waiting note while the code has not been posted", async () => {
    vi.spyOn(viewsMaxApi, "connectTelegram").mockResolvedValue({ success: false, pending: true } as never);

    render(<TelegramConnectDialog open onOpenChange={() => {}} onConnected={() => {}} />);
    await screen.findByText("/connect ABC123");

    await advance(3000);
    expect(screen.getByText(/waiting for the code to appear/i)).toBeInTheDocument();
  });
});
