// Body HTML for the public pages, rendered at build time.
//
// scripts/prerender-bodies.ts bakes this into dist/<path>/index.html so a fetch
// that doesn't run JavaScript (AI crawlers, the Claude and ChatGPT plugin
// directory reviews, search engines before they render) still gets the page
// text. In the browser React renders the same page over it on load.
import type { ReactElement } from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { StaticRouter } from "react-router-dom/server";
import Index from "@/pages/Index";
import Privacy from "@/pages/Privacy";
import Terms from "@/pages/Terms";
import ConnectAI from "@/pages/ConnectAI";
import McpLanding from "@/pages/McpLanding";
import RevenueCalculator from "@/pages/RevenueCalculator";
import ThumbnailPreview from "@/pages/ThumbnailPreview";
import FreeToolsHub from "@/pages/free-tools/FreeToolsHub";
import YoutubeTranscript from "@/pages/free-tools/YoutubeTranscript";
import TiktokTranscript from "@/pages/free-tools/TiktokTranscript";
import InstagramTranscript from "@/pages/free-tools/InstagramTranscript";
import AgentPage from "@/components/agents/AgentPage";
import { AGENT_LIST } from "@/lib/agent-pages";
import { MCP_PATH } from "@/lib/mcp-page";

// Every public content route in App.tsx (not /auth — a login form has nothing
// to read). Each needs a dist/<path>/index.html written by
// prerender-seo.ts or prerender-agents.ts first — prerender-bodies.ts fails the
// build if one is missing.
const PAGES: Record<string, ReactElement> = {
  "/": <Index />,
  "/privacy": <Privacy />,
  "/terms": <Terms />,
  "/ai": <ConnectAI />,
  [MCP_PATH]: <McpLanding />,
  "/youtube-monetization-calculator": <RevenueCalculator />,
  "/thumbnail-preview": <ThumbnailPreview />,
  "/free-tools": <FreeToolsHub />,
  "/free-tools/youtube-transcript": <YoutubeTranscript />,
  "/free-tools/tiktok-transcript": <TiktokTranscript />,
  "/free-tools/instagram-transcript": <InstagramTranscript />,
  ...Object.fromEntries(AGENT_LIST.map((a) => [a.slug, <AgentPage agent={a.key} />])),
};

export const STATIC_BODY_PATHS = Object.keys(PAGES);

export function renderStaticPageBody(path: string): string | null {
  const page = PAGES[path];
  if (!page) return null;
  return renderToStaticMarkup(<StaticRouter location={path}>{page}</StaticRouter>);
}
