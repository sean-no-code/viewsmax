import { describe, it, expect } from "vitest";
import { STATIC_PAGE_SEO } from "@/lib/static-page-seo";
import { AGENT_LIST } from "@/lib/agent-pages";
import { PLATFORM_LIST } from "@/lib/transcript-tools";
import { STATIC_BODY_PATHS, renderStaticPageBody } from "@/lib/static-page-body";

// AI crawlers and the plugin directory reviews fetch these URLs without
// running JavaScript, so the page text has to be in the HTML the build
// writes — an empty <div id="root"> reads as a blank page.
describe("renderStaticPageBody", () => {
  it("covers every public page", () => {
    const expected = [
      "/",
      "/mcp",
      "/free-tools",
      ...STATIC_PAGE_SEO.map((p) => p.path),
      ...AGENT_LIST.map((a) => a.slug),
      ...PLATFORM_LIST.map((p) => p.slug),
    ];
    expect(STATIC_BODY_PATHS).toEqual(expect.arrayContaining(expected));
  });

  it("renders a heading and real text for every page", () => {
    for (const path of STATIC_BODY_PATHS) {
      const html = renderStaticPageBody(path) ?? "";
      const text = html.replace(/<style[\s\S]*?<\/style>/g, "").replace(/<[^>]+>/g, " ");
      expect(html, path).toContain("<h1");
      expect(text.length, path).toBeGreaterThan(500);
    }
  });

  it("includes the actual policy, terms, and docs text", () => {
    expect(renderStaticPageBody("/privacy")).toContain("Information We Collect");
    expect(renderStaticPageBody("/terms")).toContain("Subscriptions and Billing");
    expect(renderStaticPageBody("/ai")).toContain("Connect your AI to ViewsMax");
  });

  it("returns null for a path with no static body", () => {
    expect(renderStaticPageBody("/dashboard")).toBeNull();
  });
});
