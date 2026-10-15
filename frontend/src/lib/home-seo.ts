// hreflang for the localized homepages, used by both the page heads and the
// sitemap (scripts/prerender-seo.ts writes both at build time). Google wants
// the same set everywhere: every version lists all versions, itself included,
// plus x-default, and the sitemap repeats it per URL.
import { HOME_LOCALES } from "../pages/landing/locales";

export const ORIGIN = "https://viewsmax.com";

// public/sitemap-pages.xml holds this marker where the homepage entries go.
export const SITEMAP_MARKER = "<!-- homepages: written by scripts/prerender-seo.ts -->";

export const homeAlternates = () => [
  ...HOME_LOCALES.map((l) => ({ hreflang: l.htmlLang, href: `${ORIGIN}${l.path}` })),
  { hreflang: "x-default", href: `${ORIGIN}/` },
];

/** <link rel="alternate" hreflang> tags for a homepage's <head>. */
export const hreflangLinks = () =>
  homeAlternates().map((a) => `\n  <link rel="alternate" hreflang="${a.hreflang}" href="${a.href}" />`).join("");

/** One sitemap <url> per homepage, each with every alternate (xhtml namespace). */
export const sitemapHomepages = () =>
  HOME_LOCALES.map((l) =>
    `  <url>\n    <loc>${ORIGIN}${l.path}</loc>\n` +
    homeAlternates().map((a) => `    <xhtml:link rel="alternate" hreflang="${a.hreflang}" href="${a.href}" />\n`).join("") +
    "  </url>",
  ).join("\n");
