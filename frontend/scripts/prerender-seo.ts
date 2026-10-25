// Build-time SEO prerender (head injection — no headless browser).
//
// After `vite build`, this writes a per-route static index.html for the free-tools
// pages, the channel pages (src/lib/channel-pages.ts) and the static pages in src/lib/static-page-seo.ts (privacy, terms, /ai)
// with the real <title>, meta, canonical, and JSON-LD baked into the served
// HTML (so crawlers and social scrapers get correct tags without executing JS).
// It also writes the localized homepages (/es, /ru, ... in
// src/pages/landing/locales.ts) with their <html lang> and hreflang links.
// The page body is baked in afterwards by scripts/prerender-bodies.ts. Runs as
// `postbuild` (see package.json), pure Node + tsx — no Chromium, CI-safe.
import { readFileSync, writeFileSync, mkdirSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { PLATFORM_LIST, HUB_SEO, buildJsonLd } from "../src/lib/transcript-tools";
import { STATIC_PAGE_SEO } from "../src/lib/static-page-seo";
import { CHANNEL_LIST, CHANNELS_HUB_SEO, CHANNELS_PATH, buildChannelJsonLd, buildChannelsHubJsonLd } from "../src/lib/channel-pages";
import { HOME_LOCALES } from "../src/pages/landing/locales";
import { SITEMAP_MARKER, hreflangLinks, sitemapLocalizedPages } from "../src/lib/locale-seo";

const distDir = join(dirname(fileURLToPath(import.meta.url)), "..", "dist");
const ORIGIN = "https://viewsmax.com";
const OG_IMAGE = `${ORIGIN}/og-image.png`;

const esc = (s: string) =>
  s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");

// Strip the generic head tags baked into index.html so per-route tags don't duplicate them.
const cleanHead = (html: string) =>
  html
    .replace(/\s*<meta name="description"[^>]*>/gi, "")
    .replace(/\s*<meta name="keywords"[^>]*>/gi, "")
    .replace(/\s*<meta property="og:[^"]*"[^>]*>/gi, "")
    .replace(/\s*<meta name="twitter:[^"]*"[^>]*>/gi, "")
    .replace(/\s*<link rel="canonical"[^>]*>/gi, "")
    .replace(/\s*<script type="application\/ld\+json">[\s\S]*?<\/script>/gi, "");

const shell = cleanHead(readFileSync(join(distDir, "index.html"), "utf8"));

interface Route {
  path: string;
  title: string;
  description: string;
  keywords?: string;
  jsonLd: Record<string, unknown>[];
  // Localized homepages only.
  htmlLang?: string;
  ogLocale?: string;
}

// Every homepage lists every other one (search engines ignore one-way hreflang).
const HREFLANG = hreflangLinks("/");

const routes: Route[] = [
  { path: "/free-tools", ...HUB_SEO, jsonLd: [] },
  ...PLATFORM_LIST.map((p) => ({
    path: p.slug,
    title: p.seo.title,
    description: p.seo.description,
    keywords: p.seo.keywords,
    jsonLd: buildJsonLd(p),
  })),
  // /channels and one guide per posting channel (src/lib/channel-pages.ts).
  { path: CHANNELS_PATH, ...CHANNELS_HUB_SEO, jsonLd: buildChannelsHubJsonLd() },
  ...CHANNEL_LIST.map((c) => ({ path: c.slug, ...c.seo, jsonLd: buildChannelJsonLd(c) })),
  // Privacy, terms, and AI docs: without a file per route the host serves
  // them with HTTP 404 (see src/lib/static-page-seo.ts).
  ...STATIC_PAGE_SEO.map((p) => ({ ...p, jsonLd: [] })),
  ...HOME_LOCALES.filter((l) => l.path !== "/").map((l) => ({
    path: l.path,
    ...l.copy.seo,
    // The English homepage's SoftwareApplication block lives in index.html;
    // each translation describes itself in its own language.
    jsonLd: [{
      "@context": "https://schema.org",
      "@type": "WebPage",
      name: l.copy.seo.title,
      description: l.copy.seo.description,
      url: `${ORIGIN}${l.path}`,
      inLanguage: l.htmlLang,
      isPartOf: { "@type": "WebSite", name: "ViewsMax", url: `${ORIGIN}/` },
    }],
    htmlLang: l.htmlLang,
    ogLocale: l.ogLocale,
  })),
];

for (const r of routes) {
  const url = `${ORIGIN}${r.path}`;
  const head =
    `\n  <meta name="description" content="${esc(r.description)}" />` +
    (r.keywords ? `\n  <meta name="keywords" content="${esc(r.keywords)}" />` : "") +
    `\n  <link rel="canonical" href="${url}" />` +
    `\n  <meta property="og:title" content="${esc(r.title)}" />` +
    `\n  <meta property="og:description" content="${esc(r.description)}" />` +
    `\n  <meta property="og:type" content="website" />` +
    `\n  <meta property="og:url" content="${url}" />` +
    `\n  <meta property="og:image" content="${OG_IMAGE}" />` +
    `\n  <meta name="twitter:card" content="summary_large_image" />` +
    `\n  <meta name="twitter:title" content="${esc(r.title)}" />` +
    `\n  <meta name="twitter:description" content="${esc(r.description)}" />` +
    `\n  <meta name="twitter:image" content="${OG_IMAGE}" />` +
    (r.ogLocale ? `\n  <meta property="og:locale" content="${r.ogLocale}" />` + HREFLANG : "") +
    r.jsonLd.map((d) => `\n  <script type="application/ld+json">${JSON.stringify(d)}</script>`).join("");

  let html = shell
    .replace(/<title>[\s\S]*?<\/title>/, `<title>${esc(r.title)}</title>`)
    .replace("</head>", `${head}\n</head>`);
  if (r.htmlLang) html = html.replace('<html lang="en">', `<html lang="${r.htmlLang}">`);

  const outDir = join(distDir, r.path.replace(/^\//, ""));
  mkdirSync(outDir, { recursive: true });
  writeFileSync(join(outDir, "index.html"), html);
  console.log(`prerendered ${r.path}`);
}

// The English homepage is dist/index.html itself: add its hreflang links last,
// after every route above has copied the shell. prerender-agents.ts strips them
// from its copies again.
const indexFile = join(distDir, "index.html");
writeFileSync(indexFile, readFileSync(indexFile, "utf8").replace("</head>", `${HREFLANG}\n</head>`));
console.log("prerendered hreflang /");

// The translated pages' sitemap entries (every language of / and /mcp), with
// their hreflang alternates, go where public/sitemap-pages.xml has the marker.
// Fail the build rather than ship a sitemap without them.
const sitemapFile = join(distDir, "sitemap-pages.xml");
const sitemap = readFileSync(sitemapFile, "utf8");
if (!sitemap.includes(SITEMAP_MARKER)) throw new Error(`sitemap-pages.xml: no ${SITEMAP_MARKER}`);
writeFileSync(sitemapFile, sitemap.replace(SITEMAP_MARKER, () => sitemapLocalizedPages()));
console.log("prerendered sitemap translated pages");
