import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, it, expect } from "vitest";
import { HOME_LOCALES, localizedPath } from "@/pages/landing/locales";
import { LOCALIZED_PAGES, ORIGIN, SITEMAP_MARKER, hreflangLinks, sitemapLocalizedPages } from "@/lib/locale-seo";

const expectedFor = (page: string) =>
  [...HOME_LOCALES.map((l) => `${l.htmlLang}=${ORIGIN}${localizedPath(l, page)}`), `x-default=${ORIGIN}${page}`].sort();

describe("translated-page hreflang", () => {
  it.each(LOCALIZED_PAGES)("%s lists every language version plus x-default in the page head", (page) => {
    const pairs = [...hreflangLinks(page).matchAll(/hreflang="([^"]+)" href="([^"]+)"/g)].map((m) => `${m[1]}=${m[2]}`).sort();
    expect(pairs).toEqual(expectedFor(page));
  });

  it("gives every language version in the sitemap the full, identical set of alternates", () => {
    const doc = new DOMParser().parseFromString(
      `<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">${sitemapLocalizedPages()}</urlset>`,
      "application/xml",
    );
    const urls = [...doc.getElementsByTagName("url")];
    const locs = urls.map((u) => u.getElementsByTagName("loc")[0].textContent ?? "");
    expect(locs.sort()).toEqual(
      LOCALIZED_PAGES.flatMap((page) => HOME_LOCALES.map((l) => `${ORIGIN}${localizedPath(l, page)}`)).sort(),
    );
    for (const url of urls) {
      const loc = url.getElementsByTagName("loc")[0].textContent ?? "";
      const page = LOCALIZED_PAGES.find((p) => expectedFor(p).some((e) => e.endsWith(`=${loc}`)))!;
      const links = [...url.getElementsByTagName("xhtml:link")].map((a) => `${a.getAttribute("hreflang")}=${a.getAttribute("href")}`).sort();
      expect(links, loc).toEqual(expectedFor(page));
    }
  });

  it("has a slot in the public sitemap and no hand-written entries for translated pages", () => {
    const xml = readFileSync(join(process.cwd(), "public/sitemap-pages.xml"), "utf8");
    expect(xml.split(SITEMAP_MARKER)).toHaveLength(2);
    expect(xml).toContain('xmlns:xhtml="http://www.w3.org/1999/xhtml"');
    for (const page of LOCALIZED_PAGES) {
      for (const l of HOME_LOCALES) expect(xml).not.toContain(`<loc>${ORIGIN}${localizedPath(l, page)}</loc>`);
    }
  });
});
