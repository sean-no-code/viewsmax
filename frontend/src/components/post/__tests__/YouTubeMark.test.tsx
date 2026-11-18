import { describe, it, expect } from "vitest";
import { render, screen } from "@testing-library/react";
import { YouTubeMark, youtubeMarkHref, youtubeMarkSize, YOUTUBE_MARK_MIN_HEIGHT } from "@/components/post/YouTubeMark";
import { PAvatar } from "@/components/post/composer";

// YouTube API Services branding: the official mark, in its own colours,
// never under 20px high, linking to the channel (or youtube.com).
describe("YouTubeMark", () => {
  it("is never under 20px high, however small the slot", () => {
    for (const size of [12, 16, 20, 28]) {
      expect(youtubeMarkSize(size).height).toBeGreaterThanOrEqual(YOUTUBE_MARK_MIN_HEIGHT);
    }
    // A big slot keeps the official proportions (wider than high).
    const big = youtubeMarkSize(44);
    expect(big.width).toBe(44);
    expect(big.height).toBeCloseTo(44 / (396.533 / 277.776), 1);
  });

  it("links to the channel when it is known, otherwise to youtube.com", () => {
    expect(youtubeMarkHref("UC1234567890123456789012")).toBe("https://www.youtube.com/channel/UC1234567890123456789012");
    expect(youtubeMarkHref("@creator")).toBe("https://www.youtube.com/@creator");
    expect(youtubeMarkHref("https://www.youtube.com/@creator")).toBe("https://www.youtube.com/@creator");
    expect(youtubeMarkHref("https://evil.example/@creator")).toBe("https://www.youtube.com");
    expect(youtubeMarkHref(null)).toBe("https://www.youtube.com");
  });

  it("draws the official colours and opens YouTube in a new tab", () => {
    const { container } = render(<YouTubeMark size={12} channel="@creator" />);
    const link = screen.getByRole("link");
    expect(link).toHaveAttribute("href", "https://www.youtube.com/@creator");
    expect(link).toHaveAttribute("target", "_blank");
    const fills = [...container.querySelectorAll("path")].map((p) => p.getAttribute("fill"));
    expect(fills).toEqual(["#FF0033", "#FFFFFF"]);
    expect(Number(container.querySelector("svg")!.getAttribute("height"))).toBeGreaterThanOrEqual(20);
  });

  it("renders no link inside another clickable element", () => {
    render(<YouTubeMark link={false} />);
    expect(screen.queryByRole("link")).toBeNull();
    expect(screen.getByRole("img", { name: "YouTube" })).toBeInTheDocument();
  });

  it("replaces the coloured avatar circle and is not greyed out when muted", () => {
    const { container } = render(<PAvatar id="youtube" size={16} dim />);
    expect(screen.getByRole("img", { name: "YouTube" })).toBeInTheDocument();
    expect(container.innerHTML).not.toContain("grayscale");
    expect(Number(container.querySelector("svg")!.getAttribute("height"))).toBeGreaterThanOrEqual(20);
  });
});
