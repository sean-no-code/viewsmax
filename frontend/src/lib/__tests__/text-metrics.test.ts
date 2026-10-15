import { describe, it, expect } from "vitest";
import { graphemeLength, plainLength, platformLength } from "@/lib/text-metrics";

// Bluesky's 300 limit counts graphemes (mirrors CaptionRules::lengthFor), so
// a family emoji or a letter with a combining mark must count as one.
describe("platformLength", () => {
  const family = "\u{1F468}‍\u{1F469}‍\u{1F467}"; // 👨‍👩‍👧: 5 code points, 1 grapheme
  const eWithAccent = "é"; // e + combining acute: 2 code points, 1 grapheme

  it("counts graphemes for bluesky and code points elsewhere", () => {
    const text = `hi ${family} caf${eWithAccent}`;
    expect(plainLength(text)).toBe(3 + 5 + 1 + 3 + 2);
    expect(graphemeLength(text)).toBe(3 + 1 + 1 + 3 + 1);
    expect(platformLength("bluesky", text)).toBe(9);
    expect(platformLength("threads", text)).toBe(14);
  });

  it("lets a 300-grapheme bluesky caption through that code points would reject", () => {
    const text = family.repeat(300);
    expect(platformLength("bluesky", text)).toBe(300);
    expect(plainLength(text)).toBe(1500);
  });

  it("normalises CRLF before counting", () => {
    expect(platformLength("bluesky", "a\r\nb")).toBe(3);
  });
});
