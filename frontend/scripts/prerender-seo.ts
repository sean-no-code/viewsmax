// Build-time SEO prerender (head injection — no headless browser).
//
// After `vite build`, this writes a per-route static index.html for the free-tools
// pages with the real <title>, meta, canonical, and JSON-LD baked into the served
// HTML (so crawlers and social scrapers get correct tags without executing JS).
// The page body still hydrates via React on load. Runs as `postbuild` (see
// package.json), pure Node + tsx — no Chromium, CI-safe.
import { readFileSync, writeFileSync, mkdirSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { PLATFORM_LIST, HUB_SEO, buildJsonLd } from "../src/lib/transcript-tools";

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
  keywords: string;
  jsonLd: Record<string, unknown>[];
}

const routes: Route[] = [
  { path: "/free-tools", ...HUB_SEO, jsonLd: [] },
  ...PLATFORM_LIST.map((p) => ({
    path: p.slug,
    title: p.seo.title,
    description: p.seo.description,
    keywords: p.seo.keywords,
    jsonLd: buildJsonLd(p),
  })),
];

for (const r of routes) {
  const url = `${ORIGIN}${r.path}`;
  const head =
    `\n  <meta name="description" content="${esc(r.description)}" />` +
    `\n  <meta name="keywords" content="${esc(r.keywords)}" />` +
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
    r.jsonLd.map((d) => `\n  <script type="application/ld+json">${JSON.stringify(d)}</script>`).join("");

  const html = shell
    .replace(/<title>[\s\S]*?<\/title>/, `<title>${esc(r.title)}</title>`)
    .replace("</head>", `${head}\n</head>`);

  const outDir = join(distDir, r.path.replace(/^\//, ""));
  mkdirSync(outDir, { recursive: true });
  writeFileSync(join(outDir, "index.html"), html);
  console.log(`prerendered ${r.path}`);
}
