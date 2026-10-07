// The free transcript pages (YouTube / TikTok / Instagram) call
// viewsMaxApi.getTranscript. `vi.spyOn` throws if that method is missing, so
// this suite fails the moment the page and the service drift apart.
import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import TranscriptTool from "@/components/free-tools/TranscriptTool";
import { viewsMaxApi } from "@/lib/api-service";

vi.mock("@/pages/landing/LandingChrome", () => ({ LandingNav: () => null, LandingFooter: () => null }));
vi.mock("@/components/free-tools/BookmarkBar", () => ({ default: () => null }));
vi.mock("@/components/free-tools/ToolAds", () => ({
  TOOL_ADS: [1, 2, 3, 4].map((n) => ({ id: `ad-${n}` })),
  AdRail: () => null,
  AdStrip: () => null,
}));
vi.mock("@/hooks/useSeo", () => ({ useSeo: () => {} }));
vi.mock("sonner", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

const renderTool = (platform: "youtube" | "tiktok" | "instagram" = "youtube") =>
  render(
    <MemoryRouter>
      <TranscriptTool platform={platform} />
    </MemoryRouter>,
  );

const submit = (url: string) => {
  fireEvent.change(screen.getByRole("textbox", { name: /video url/i }), { target: { value: url } });
  fireEvent.click(screen.getByRole("button", { name: /generate free transcript/i }));
};

let getTranscript: ReturnType<typeof vi.spyOn>;

beforeEach(() => {
  getTranscript = vi.spyOn(viewsMaxApi, "getTranscript");
});
afterEach(() => vi.restoreAllMocks());

describe("TranscriptTool", () => {
  it("asks for a link before calling the API", () => {
    renderTool();
    fireEvent.click(screen.getByRole("button", { name: /generate free transcript/i }));

    expect(screen.getByText(/paste a youtube link first/i)).toBeInTheDocument();
    expect(getTranscript).not.toHaveBeenCalled();
  });

  it("fetches the transcript for the page's platform and renders the segments", async () => {
    let resolve!: (v: Awaited<ReturnType<typeof viewsMaxApi.getTranscript>>) => void;
    getTranscript.mockReturnValueOnce(new Promise((r) => { resolve = r; }));
    renderTool("tiktok");

    submit("  https://www.tiktok.com/@creator/video/123  ");

    expect(getTranscript).toHaveBeenCalledWith("tiktok", "https://www.tiktok.com/@creator/video/123");
    expect(screen.getByText(/fetching the tiktok transcript/i)).toBeInTheDocument();

    resolve({
      success: true,
      data: {
        platform: "tiktok",
        url: "https://www.tiktok.com/@creator/video/123",
        text: "first line second line",
        segments: [
          { text: "first line", startMs: 0, endMs: 1500 },
          { text: "second line", startMs: 65000, endMs: 70000 },
        ],
        language: "en",
        cached: false,
      },
    });

    expect(await screen.findByText("first line")).toBeInTheDocument();
    expect(screen.getByText("second line")).toBeInTheDocument();
    expect(screen.getByText("0:00")).toBeInTheDocument();
    expect(screen.getByText("1:05")).toBeInTheDocument();
    expect(screen.queryByText(/fetching the tiktok transcript/i)).toBeNull();
    expect(screen.getByRole("button", { name: /copy/i })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /txt/i })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /srt/i })).toBeInTheDocument();
  });

  it("shows the API's error message when the fetch fails", async () => {
    getTranscript.mockResolvedValueOnce({ success: false, error: "That video has no captions." });
    renderTool();

    submit("https://youtu.be/abc");

    expect(await screen.findByText("That video has no captions.")).toBeInTheDocument();
    await waitFor(() => expect(screen.queryByText(/fetching the youtube transcript/i)).toBeNull());
  });
});
