// The link modal's platform picker is a MULTI-select: every selected platform
// with a pullable content source (YouTube video / Beehiiv post / X post) must
// show its own picker at the same time — selecting Beehiiv alongside YouTube
// once hid Beehiiv's picker entirely (single-select regression).
import { describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { useState } from "react";
import { PlatformSourceFields, type LinkSource } from "../OfferDetail";

vi.mock("@/hooks/useAuth", () => ({
  useAuth: () => ({ session: { token: "test-token" }, user: { id: 1 } }),
}));

vi.mock("@/lib/api-service", async (importOriginal) => {
  const original = await importOriginal<typeof import("@/lib/api-service")>();
  return {
    ...original,
    viewsMaxApi: {
      ...original.viewsMaxApi,
      getBeehiivConnection: vi.fn().mockResolvedValue({ success: true, data: { connected: true } }),
      getBeehiivPosts: vi.fn().mockResolvedValue({ success: true, data: [{ id: "b1", title: "Newsletter #1" }] }),
      getChannels: vi.fn().mockResolvedValue({ success: true, data: [{ id: 1, channel_name: "C" }] }),
      getChannelVideos: vi.fn().mockResolvedValue({
        success: true,
        data: { videos: [{ id: 1, youtube_video_id: "v1", title: "My launch video", view_count: 12500 }] },
      }),
      getXPublishedPosts: vi.fn().mockResolvedValue({ success: true, data: [{ id: "t1", text: "Big launch", url: "https://x.com/s/1", posted_at: null }] }),
    },
  };
});

function Harness({ initial }: { initial?: Partial<LinkSource> }) {
  const [src, setSrc] = useState<LinkSource>({
    platforms: [], ytVideoId: "", beehiivPostId: "", xPostId: "", location: "",
    ...initial,
  });
  return <PlatformSourceFields value={src} set={(patch) => setSrc((s) => ({ ...s, ...patch }))} />;
}

describe("PlatformSourceFields", () => {
  it("renders one multi-select chip group with every platform", () => {
    render(<Harness />);
    const group = screen.getByRole("group", { name: "Platforms" });
    expect(group).toBeTruthy();
    for (const label of ["YouTube", "Beehiiv", "X / Twitter", "LinkedIn", "Website"]) {
      expect(screen.getByRole("button", { name: label })).toBeTruthy();
    }
    // No content pickers until a platform is chosen.
    expect(screen.queryByText("YouTube video")).toBeNull();
    expect(screen.queryByText("Beehiiv post")).toBeNull();
  });

  it("shows BOTH content pickers when YouTube and Beehiiv are selected together", async () => {
    const user = userEvent.setup();
    render(<Harness />);

    await user.click(screen.getByRole("button", { name: "YouTube" }));
    await waitFor(() => expect(screen.getByText("YouTube video")).toBeTruthy());

    await user.click(screen.getByRole("button", { name: "Beehiiv" }));
    await waitFor(() => expect(screen.getByText("Beehiiv post")).toBeTruthy());

    // The regression: selecting Beehiiv second must not hide either picker.
    expect(screen.getByText("YouTube video")).toBeTruthy();
    expect(screen.getByText("Beehiiv post")).toBeTruthy();

    // Selected chips report pressed state (and X's picker stays hidden).
    expect(screen.getByRole("button", { name: "YouTube" }).getAttribute("aria-pressed")).toBe("true");
    expect(screen.getByRole("button", { name: "Beehiiv" }).getAttribute("aria-pressed")).toBe("true");
    expect(screen.queryByText("X post")).toBeNull();
  });

  it("deselecting a platform removes its picker and keeps the others", async () => {
    const user = userEvent.setup();
    render(<Harness initial={{ platforms: ["video", "beehiiv"] }} />);

    await waitFor(() => expect(screen.getByText("Beehiiv post")).toBeTruthy());
    await user.click(screen.getByRole("button", { name: "YouTube" }));

    expect(screen.queryByText("YouTube video")).toBeNull();
    expect(screen.getByText("Beehiiv post")).toBeTruthy();
  });

  it("shows the X post picker when X is selected", async () => {
    const user = userEvent.setup();
    render(<Harness />);

    await user.click(screen.getByRole("button", { name: "X / Twitter" }));

    await waitFor(() => expect(screen.getByText("X post")).toBeTruthy());
  });
});
