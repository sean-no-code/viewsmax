// Build-time SEO prerender (no headless browser).
//
// After `vite build`, this writes a per-route static index.html for the free-tools
// pages and the static pages in src/lib/static-page-seo.ts (privacy, terms, /ai)
// with the real <title>, meta, canonical, and JSON-LD baked into the served
// HTML (so crawlers and social scrapers get correct tags without executing JS).
// The static pages also get their body text baked in (src/lib/static-page-body.tsx):
// the plugin directory reviews read the privacy policy and terms without running
// JavaScript. Other routes keep an empty root that React fills on load. Runs as
// `postbuild` (see package.json), pure Node + tsx — no Chromium, CI-safe.
import { readFileSync, writeFileSync, mkdirSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { createServer } from "vite";
import { PLATFORM_LIST, HUB_SEO, buildJsonLd } from "../src/lib/transcript-tools";
import { STATIC_PAGE_SEO } from "../src/lib/static-page-seo";

const rootDir = join(dirname(fileURLToPath(import.meta.url)), "..");
const distDir = join(rootDir, "dist");
const ROOT_DIV = '<div id="root"></div>';

// The pages import assets, `@/` aliases and import.meta.env, so they load
// through Vite rather than plain tsx. api-service reads localStorage on
// import; there is no session at build time.
globalThis.localStorage ??= { getItem: () => null, setItem() {}, removeItem() {} } as unknown as Storage;
const vite = await createServer({
  root: rootDir,
  mode: "production",
  server: { middlewareMode: true },
  appType: "custom",
  logLevel: "error",
});
const { renderStaticPageBody } = (await vite.ssrLoadModule("/src/lib/static-page-body.tsx")) as
  typeof import("../src/lib/static-page-body");
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
  // Privacy, terms, and AI docs: without a file per route the host serves
  // them with HTTP 404 (see src/lib/static-page-seo.ts).
  ...STATIC_PAGE_SEO.map((p) => ({ ...p, jsonLd: [] })),
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

  let html = shell
    .replace(/<title>[\s\S]*?<\/title>/, `<title>${esc(r.title)}</title>`)
    .replace("</head>", `${head}\n</head>`);

  const body = renderStaticPageBody(r.path);
  if (body !== null) {
    // Fail the build rather than ship a policy page with no text.
    if (!html.includes(ROOT_DIV)) throw new Error(`${r.path}: no ${ROOT_DIV} in dist/index.html to fill`);
    html = html.replace(ROOT_DIV, () => `<div id="root">${body}</div>`);
  }

  const outDir = join(distDir, r.path.replace(/^\//, ""));
  mkdirSync(outDir, { recursive: true });
  writeFileSync(join(outDir, "index.html"), html);
  console.log(`prerendered ${r.path}${body !== null ? " (with body)" : ""}`);
}

await vite.close();
