import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, it, expect } from "vitest";
import { HOME_LOCALES } from "@/pages/landing/locales";
import { ORIGIN, SITEMAP_MARKER, hreflangLinks, sitemapHomepages } from "@/lib/home-seo";

const expected = [...HOME_LOCALES.map((l) => `${l.htmlLang}=${ORIGIN}${l.path}`), `x-default=${ORIGIN}/`].sort();

describe("homepage hreflang", () => {
  it("lists every language version plus x-default in the page head", () => {
    const pairs = [...hreflangLinks().matchAll(/hreflang="([^"]+)" href="([^"]+)"/g)].map((m) => `${m[1]}=${m[2]}`).sort();
    expect(pairs).toEqual(expected);
  });

  it("gives every homepage in the sitemap the full, identical set of alternates", () => {
    const doc = new DOMParser().parseFromString(
      `<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">${sitemapHomepages()}</urlset>`,
      "application/xml",
    );
    const urls = [...doc.getElementsByTagName("url")];
    expect(urls.map((u) => u.getElementsByTagName("loc")[0].textContent).sort())
      .toEqual(HOME_LOCALES.map((l) => `${ORIGIN}${l.path}`).sort());
    for (const url of urls) {
      const links = [...url.getElementsByTagName("xhtml:link")].map((a) => `${a.getAttribute("hreflang")}=${a.getAttribute("href")}`).sort();
      expect(links).toEqual(expected);
    }
  });

  it("has a slot in the public sitemap and no hand-written homepage entries", () => {
    const xml = readFileSync(join(process.cwd(), "public/sitemap-pages.xml"), "utf8");
    expect(xml.split(SITEMAP_MARKER)).toHaveLength(2);
    expect(xml).toContain('xmlns:xhtml="http://www.w3.org/1999/xhtml"');
    for (const l of HOME_LOCALES) expect(xml).not.toContain(`<loc>${ORIGIN}${l.path}</loc>`);
  });
});
