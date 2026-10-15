import { describe, expect, it } from "vitest";
import { plainLength, platformLength, xWeightedLength } from "@/lib/text-metrics";

// Mirror of backend CaptionRulesTest: the composer pill and the API must agree.
describe("platformLength", () => {
  it("counts Threads in UTF-8 bytes, as Meta does for its 500 limit", () => {
    expect(platformLength("threads", "😀")).toBe(4);
    expect(platformLength("threads", "“")).toBe(3); // curly quote
    expect(platformLength("threads", "é")).toBe(2);
    expect(platformLength("threads", "hello")).toBe(5);
    expect(platformLength("threads", "a".repeat(497) + "😀")).toBe(501);
  });

  it("normalises CRLF before counting Threads bytes", () => {
    expect(platformLength("threads", "a\r\nb")).toBe(3);
  });

  it("keeps code points for other platforms and the t.co weighting for X", () => {
    expect(platformLength("instagram", "😀")).toBe(1);
    expect(platformLength("instagram", "😀")).toBe(plainLength("😀"));
    expect(platformLength("x", "https://example.com/a/very/long/url/that/keeps/going")).toBe(xWeightedLength("https://example.com/a/very/long/url/that/keeps/going"));
    expect(platformLength("x", "😀")).toBe(2);
  });
});
