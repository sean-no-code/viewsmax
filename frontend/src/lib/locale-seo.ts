// hreflang for the translated public pages (the homepage and /mcp), used by
// both the page heads and the sitemap (scripts/prerender-seo.ts and
// prerender-agents.ts write them at build time). Google wants the same set
// everywhere: every version lists all versions, itself included, plus
// x-default, and the sitemap repeats it per URL.
import { HOME_LOCALES, localizedPath } from "../pages/landing/locales";
import { MCP_PATH } from "./mcp-page";

export const ORIGIN = "https://viewsmax.com";

/** Pages that exist in every HOME_LOCALES language, by their English path. */
export const LOCALIZED_PAGES = ["/", MCP_PATH];

// public/sitemap-pages.xml holds this marker where those pages' entries go.
export const SITEMAP_MARKER = "<!-- translated pages: written by scripts/prerender-seo.ts -->";

export const alternatesFor = (page = "/") => [
  ...HOME_LOCALES.map((l) => ({ hreflang: l.htmlLang, href: `${ORIGIN}${localizedPath(l, page)}` })),
  { hreflang: "x-default", href: `${ORIGIN}${page}` },
];

/** <link rel="alternate" hreflang> tags for one version of `page`'s <head>. */
export const hreflangLinks = (page = "/") =>
  alternatesFor(page).map((a) => `\n  <link rel="alternate" hreflang="${a.hreflang}" href="${a.href}" />`).join("");

/** One sitemap <url> per language version of every translated page, each with every alternate (xhtml namespace). */
export const sitemapLocalizedPages = () =>
  LOCALIZED_PAGES.flatMap((page) =>
    HOME_LOCALES.map((l) =>
      `  <url>\n    <loc>${ORIGIN}${localizedPath(l, page)}</loc>\n` +
      alternatesFor(page).map((a) => `    <xhtml:link rel="alternate" hreflang="${a.hreflang}" href="${a.href}" />\n`).join("") +
      "  </url>",
    ),
  ).join("\n");
