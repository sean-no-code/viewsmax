// Build-time prerender for the per-agent setup guides (/claude, /chatgpt, ...)
// in src/lib/agent-pages.ts and the /mcp landing page in src/lib/mcp-page.ts,
// plus their plain-markdown twins (/claude.md, /mcp.md).
//
// Runs right after scripts/prerender-seo.ts (see `postbuild` in package.json)
// and injects head tags the same way: real <title>, meta, canonical, and
// JSON-LD baked into dist/<path>/index.html, which S3 serves with HTTP 200.
// Kept as its own script so prerender-seo.ts stays untouched by these pages.
import { readFileSync, writeFileSync, mkdirSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { loadEnv } from "vite";
import { AGENT_LIST, briefPath, buildAgentJsonLd, buildAgentMarkdown } from "../src/lib/agent-pages";
import { MCP_MD_PATH, MCP_PAGE_SEO, MCP_PATH, buildMcpJsonLd, buildMcpMarkdown } from "../src/lib/mcp-page";

const rootDir = join(dirname(fileURLToPath(import.meta.url)), "..");
const distDir = join(rootDir, "dist");
const ORIGIN = "https://viewsmax.com";
const OG_IMAGE = `${ORIGIN}/og-image.png`;
// Same VITE_* values the build baked into the bundle, so each agent brief
// shows a directory listing exactly when the page does.
const env = { ...loadEnv("production", rootDir, "VITE_"), ...process.env };

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

interface Seo {
  title: string;
  description: string;
  keywords: string;
}

function writeRoute(path: string, { title, description, keywords }: Seo, jsonLd: Record<string, unknown>[]) {
  const url = `${ORIGIN}${path}`;
  const head =
    `\n  <meta name="description" content="${esc(description)}" />` +
    `\n  <meta name="keywords" content="${esc(keywords)}" />` +
    `\n  <link rel="canonical" href="${url}" />` +
    `\n  <meta property="og:title" content="${esc(title)}" />` +
    `\n  <meta property="og:description" content="${esc(description)}" />` +
    `\n  <meta property="og:type" content="website" />` +
    `\n  <meta property="og:url" content="${url}" />` +
    `\n  <meta property="og:image" content="${OG_IMAGE}" />` +
    `\n  <meta name="twitter:card" content="summary_large_image" />` +
    `\n  <meta name="twitter:title" content="${esc(title)}" />` +
    `\n  <meta name="twitter:description" content="${esc(description)}" />` +
    `\n  <meta name="twitter:image" content="${OG_IMAGE}" />` +
    jsonLd.map((d) => `\n  <script type="application/ld+json">${JSON.stringify(d)}</script>`).join("");

  const html = shell
    .replace(/<title>[\s\S]*?<\/title>/, `<title>${esc(title)}</title>`)
    .replace("</head>", `${head}\n</head>`);

  const outDir = join(distDir, path.replace(/^\//, ""));
  mkdirSync(outDir, { recursive: true });
  writeFileSync(join(outDir, "index.html"), html);
  console.log(`prerendered ${path}`);
}

// Plain-markdown twin, alongside /ai.md, for agents that read docs directly
// and for the page's "View plain text" link.
function writeMd(path: string, markdown: string) {
  writeFileSync(join(distDir, path.replace(/^\//, "")), markdown);
  console.log(`wrote ${path}`);
}

for (const a of AGENT_LIST) {
  writeRoute(a.slug, a.seo, buildAgentJsonLd(a));
  writeMd(briefPath(a), buildAgentMarkdown(a, env));
}

writeRoute(MCP_PATH, MCP_PAGE_SEO, buildMcpJsonLd());
writeMd(MCP_MD_PATH, buildMcpMarkdown());
