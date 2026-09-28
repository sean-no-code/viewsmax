import { describe, it, expect } from "vitest";
import { STATIC_PAGE_SEO } from "@/lib/static-page-seo";

// These pages are linked from the Claude and ChatGPT plugin listings (privacy
// policy, terms, and docs URLs). The site is a single-page app on S3, so a
// route without its own dist/<path>/index.html is served with HTTP 404 even
// though it renders in a browser. The build-time prerender writes one file per
// entry here, which makes the host answer 302 → 200 instead.
describe("STATIC_PAGE_SEO", () => {
  it("covers the pages the plugin listings link to", () => {
    const paths = STATIC_PAGE_SEO.map((p) => p.path);
    expect(paths).toEqual(expect.arrayContaining(["/privacy", "/terms", "/ai"]));
  });

  it("gives every page a unique root-relative path and real head tags", () => {
    const paths = STATIC_PAGE_SEO.map((p) => p.path);
    expect(new Set(paths).size).toBe(paths.length);

    for (const page of STATIC_PAGE_SEO) {
      expect(page.path).toMatch(/^\/[a-z0-9-]+(\/[a-z0-9-]+)*$/);
      expect(page.title.trim()).not.toBe("");
      expect(page.description.trim()).not.toBe("");
      expect(page.keywords.trim()).not.toBe("");
    }
  });
});
