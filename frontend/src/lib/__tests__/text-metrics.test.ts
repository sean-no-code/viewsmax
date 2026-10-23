import { describe, expect, it } from "vitest";
import { graphemeLength, plainLength, platformLength, xWeightedLength } from "@/lib/text-metrics";

// Mirror of backend CaptionRulesTest: the composer pill and the API must agree.
describe("platformLength", () => {
  const family = "\u{1F468}‍\u{1F469}‍\u{1F467}"; // 👨‍👩‍👧: 5 code points, 1 grapheme
  const eWithAccent = "é"; // e + combining acute: 2 code points, 1 grapheme

  it("counts Threads in UTF-8 bytes, as Meta does for its 500 limit", () => {
    expect(platformLength("threads", "😀")).toBe(4);
    expect(platformLength("threads", "“")).toBe(3); // curly quote
    expect(platformLength("threads", "é")).toBe(2);
    expect(platformLength("threads", "hello")).toBe(5);
    expect(platformLength("threads", "a".repeat(497) + "😀")).toBe(501);
  });

  it("counts graphemes for bluesky and code points elsewhere", () => {
    const text = `hi ${family} caf${eWithAccent}`;
    expect(plainLength(text)).toBe(3 + 5 + 1 + 3 + 2);
    expect(graphemeLength(text)).toBe(3 + 1 + 1 + 3 + 1);
    expect(platformLength("bluesky", text)).toBe(9);
    expect(platformLength("instagram", text)).toBe(14);
  });

  it("lets a 300-grapheme bluesky caption through that code points would reject", () => {
    const text = family.repeat(300);
    expect(platformLength("bluesky", text)).toBe(300);
    expect(plainLength(text)).toBe(1500);
  });

  it("normalises CRLF before counting", () => {
    expect(platformLength("threads", "a\r\nb")).toBe(3);
    expect(platformLength("bluesky", "a\r\nb")).toBe(3);
  });

  it("keeps code points for other platforms and the t.co weighting for X", () => {
    expect(platformLength("instagram", "😀")).toBe(1);
    expect(platformLength("instagram", "😀")).toBe(plainLength("😀"));
    expect(platformLength("x", "https://example.com/a/very/long/url/that/keeps/going")).toBe(xWeightedLength("https://example.com/a/very/long/url/that/keeps/going"));
    expect(platformLength("x", "😀")).toBe(2);
  });
});
