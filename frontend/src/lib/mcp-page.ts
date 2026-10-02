// Content for the /mcp landing page (src/pages/McpLanding.tsx), its plain-markdown
// twin (/mcp.md) and its JSON-LD. One module so the page, the markdown and the
// structured data can never disagree. Tool names come from MCP_TOOLS, which a test
// checks against backend/app/Mcp/Tools, and a test here checks that TOOL_GROUPS
// covers every one of them exactly once.
//
// No import.meta.env access at module scope: scripts/prerender-agents.ts and
// vite.config.ts import this file under plain Node.
import {
  AGENT_LIST,
  API_KEY_PATH,
  MCP_ENDPOINT,
  MCP_TOOLS,
  PLATFORMS,
  type AgentConfig,
  type AgentKey,
  type FaqItem,
} from "./agent-pages";

export const MCP_PATH = "/mcp";
export const MCP_MD_PATH = "/mcp.md";

const API_ORIGIN = MCP_ENDPOINT.replace(/\/api\/mcp$/, "");
export const DISCOVERY_URL = `${API_ORIGIN}/api/ai`;
export const DOCS_URL = `${API_ORIGIN}/docs`;
export const OAUTH_AS_URL = `${API_ORIGIN}/.well-known/oauth-authorization-server`;
export const OAUTH_PR_URL = `${API_ORIGIN}/.well-known/oauth-protected-resource`;
export const OAUTH_REGISTER_URL = `${API_ORIGIN}/oauth/register`;

const PLATFORM_NAMES = PLATFORMS.map((p) => p.name);
const platformList = PLATFORM_NAMES.slice(0, -1).join(", ") + " and " + PLATFORM_NAMES.at(-1);

export const MCP_PAGE_SEO = {
  title: "MCP Server for Social Media Posting & Scheduling | ViewsMax",
  description: `Connect Claude, ChatGPT, Cursor or any MCP client to ViewsMax to post, schedule and track on ${platformList}.`,
  keywords:
    "social media mcp server, mcp server social media scheduling, connect claude to tiktok, connect chatgpt to youtube, ai agent social media posting, model context protocol social media, viewsmax mcp",
};

export interface ToolGroup {
  key: string;
  name: string;
  blurb: string;
  tools: string[];
}

/** Every MCP tool, grouped by job. The order here is the order the page lists them. */
export const TOOL_GROUPS: ToolGroup[] = [
  {
    key: "accounts",
    name: "Accounts & brands",
    blurb: "See which channels are linked, group them into brands, and hand the user a connect link when one is missing.",
    tools: ["list_connected_accounts", "list_brands", "get_connect_url", "disconnect_account"],
  },
  {
    key: "posting",
    name: "Posting & scheduling",
    blurb: "Host media by URL, then draft, schedule or publish to any mix of platforms and read each platform's result.",
    tools: ["upload_media", "create_post", "list_posts", "get_post", "update_post", "delete_post"],
  },
  {
    key: "offers",
    name: "Offers & link tracking",
    blurb: "Create offers with goals, mint a tracked link per placement, and read clicks, conversions and revenue over time.",
    tools: [
      "list_offers",
      "create_offer",
      "get_offer",
      "update_offer",
      "delete_offer",
      "create_tracking_link",
      "get_offer_stats",
      "get_stats_timeseries",
    ],
  },
  {
    key: "outliers",
    name: "Outlier research",
    blurb: "Find videos that beat their channel's average, get an AI breakdown of why, save the best, and follow creators.",
    tools: [
      "list_outliers",
      "search_outliers",
      "get_outlier",
      "fetch_outlier",
      "get_outlier_breakdown",
      "generate_outlier_breakdown",
      "list_saved_outliers",
      "save_outlier",
      "remove_saved_outlier",
      "add_outlier_channel",
      "get_outlier_channel_ingest",
    ],
  },
  {
    key: "feedback",
    name: "Feedback",
    blurb: "File a feature request on the user's behalf.",
    tools: ["create_feature_request"],
  },
];

export interface Capability {
  task: string;
  tools: string[];
  /** The scope the row needs; "write" rows disappear for read-only credentials. */
  access: "read" | "write";
}

export const CAPABILITIES: Capability[] = [
  { task: "List linked accounts and brands", tools: ["list_connected_accounts", "list_brands"], access: "read" },
  {
    task: `Publish or schedule to any mix of ${PLATFORMS.length} platforms — draft, scheduled or posted, with per-platform caption and option overrides`,
    tools: ["create_post", "update_post"],
    access: "write",
  },
  { task: "Host an image or video by URL for TikTok, Instagram and YouTube posts", tools: ["upload_media"], access: "write" },
  { task: "Read each platform's publish result for a post", tools: ["get_post", "list_posts"], access: "read" },
  { task: "Create offers and a tracked link per placement", tools: ["create_offer", "create_tracking_link"], access: "write" },
  { task: "Read clicks, conversions and revenue — totals and a daily series", tools: ["get_offer_stats", "get_stats_timeseries"], access: "read" },
  { task: "Browse outlier videos by platform, score, views and date", tools: ["list_outliers", "get_outlier"], access: "read" },
  { task: "Search YouTube for a new topic, or pull in a specific video by URL", tools: ["search_outliers", "fetch_outlier"], access: "write" },
  { task: "Generate and read an AI breakdown of why a video over-performed", tools: ["generate_outlier_breakdown", "get_outlier_breakdown"], access: "write" },
  { task: "Save outliers to a tagged library", tools: ["save_outlier", "list_saved_outliers", "remove_saved_outlier"], access: "write" },
  { task: "Follow a creator's channel and pull in their recent videos", tools: ["add_outlier_channel", "get_outlier_channel_ingest"], access: "write" },
  { task: "File a feature request", tools: ["create_feature_request"], access: "write" },
];

/** Things people ask for that the MCP does not do — stated so the page never over-claims. */
export const NOT_SUPPORTED = [
  "Generating images or video — you supply media by URL and ViewsMax hosts it",
  "Clipping or editing video",
  "Reading or replying to comments and DMs",
  "Channel analytics such as follower counts or watch time — the MCP reports offer clicks, conversions and revenue",
  "Plans and billing — those stay in the ViewsMax web app",
];

export interface AuthMode {
  name: string;
  bestFor: string;
  how: string;
}

export const AUTH_MODES: AuthMode[] = [
  {
    name: "OAuth 2.1 + PKCE",
    bestFor: "Chat apps and IDEs: Claude, ChatGPT, Codex, Hermes",
    how: `Point the client at ${MCP_ENDPOINT}. It finds ViewsMax through ${OAUTH_AS_URL}, registers itself (dynamic client registration at ${OAUTH_REGISTER_URL}), and opens a browser where you sign in and choose read-only or full access. Access tokens are scoped to this endpoint and last an hour; refresh tokens last 30 days.`,
  },
  {
    name: "API key",
    bestFor: "Headless agents, scripts, CI, Cursor",
    how: `Generate a key under ${API_KEY_PATH} (it starts with vmx_), read-only or full. Send it as Authorization: Bearer vmx_… on every request — keys in the URL are rejected. Rotate the key to revoke access instantly; the same key works on the REST API.`,
  },
];

export const RATE_LIMITS: { scope: string; limit: string }[] = [
  { scope: "All MCP requests", limit: "120 per minute, per credential" },
  { scope: "create_post", limit: "180 per hour" },
  { scope: "upload_media", limit: "40 per hour · 100 MB per file" },
  { scope: "fetch_outlier", limit: "60 per hour" },
  { scope: "search_outliers", limit: "30 per hour" },
  { scope: "generate_outlier_breakdown", limit: "30 per hour" },
  { scope: "add_outlier_channel", limit: "10 per hour" },
];

export interface ClientRow {
  /** How the client is pointed at the server. */
  method: string;
  /** Which credential(s) that route supports, per the agent's own guide. */
  auth: string;
}

/** One row per AI agent guide, for the "which client supports what" table. */
export const CLIENT_MATRIX: Record<AgentKey, ClientRow> = {
  claude: { method: "Custom connector (Customize → Connectors)", auth: "OAuth" },
  "claude-code": { method: "claude mcp add --transport http", auth: "OAuth or API key" },
  "claude-cowork": { method: "Shares the claude.ai connector", auth: "OAuth" },
  chatgpt: { method: "Developer mode → custom connector", auth: "OAuth" },
  codex: { method: "codex mcp add, or ~/.codex/config.toml", auth: "OAuth or API key" },
  cursor: { method: "~/.cursor/mcp.json", auth: "API key" },
  openclaw: { method: "ViewsMax skill (drives the REST API)", auth: "API key" },
  hermes: { method: "~/.hermes/config.yaml", auth: "OAuth or API key" },
};

/** The first copy-paste snippet from the agent's own install steps — the connector address for chat apps, the CLI command or config file for the rest. */
export const clientSnippet = (agent: AgentConfig) => agent.install.find((s) => s.code)?.code;

export interface ExamplePrompt {
  title: string;
  prompt: string;
  /** Illustrative reply, written to match what the tools actually do. */
  reply: string;
}

export const EXAMPLE_PROMPTS: ExamplePrompt[] = [
  {
    title: "Schedule a cross-post",
    prompt: 'Post the video at https://example.com/clip.mp4 to TikTok and YouTube tomorrow at 9am. Caption: "Three mistakes new creators make."',
    reply:
      "Before I schedule it: which TikTok privacy level do you want, and should the YouTube upload be public, unlisted or private? Once you answer I'll host the file with upload_media and create one scheduled post for both platforms — you'll get a separate publish status for each.",
  },
  {
    title: "Find the best earner",
    prompt: "Which of my offers made the most money in the last 30 days, and which platform sent the clicks?",
    reply:
      "Your top offer by revenue is the Content Calendar Template ($1,240 from 38 conversions). get_offer_stats puts 61% of its clicks on YouTube and 27% on Instagram, and the daily series shows the spike lines up with Tuesday's video. Want the same breakdown for the other offers?",
  },
  {
    title: "Research what's working",
    prompt: "Find YouTube outliers about home workouts from this month, explain why the top one worked, and save it to my library.",
    reply:
      'search_outliers queued a YouTube scan for "home workouts". The top result is a 10-minute no-equipment routine running at 14x its channel\'s median. I generated the breakdown (hook, structure, retention beats) and saved it with the tags home-workout and hook-study. Breakdowns are capped at 30 an hour, so say the word before I run more.',
  },
];

export const MCP_FAQ: FaqItem[] = [
  {
    q: "What is the ViewsMax MCP server?",
    a: `A remote MCP (Model Context Protocol) server at ${MCP_ENDPOINT} that gives any MCP client ${MCP_TOOLS.length} tools for posting and scheduling to ${platformList}, tracking offers and revenue, and researching outlier videos. It runs over Streamable HTTP, so there is nothing to install or host.`,
  },
  {
    q: "Which AI agents work with it?",
    a: "Any client that speaks MCP over Streamable HTTP. ViewsMax has step-by-step guides for Claude, Claude Code, Claude Cowork, ChatGPT, Codex, Cursor and Hermes Agent, plus a REST-based skill for OpenClaw.",
  },
  {
    q: "How do I connect Claude to ViewsMax?",
    a: `In Claude, open Customize → Connectors, add a custom connector with the address ${MCP_ENDPOINT}, then press Connect and approve read-only or full access. The same connector carries over to Claude Code and Claude Cowork.`,
  },
  {
    q: "How do I connect ChatGPT to ViewsMax?",
    a: `Turn on Developer mode under Settings → Security and login, then add a custom connector at chatgpt.com/plugins with the address ${MCP_ENDPOINT} and complete the sign-in. ViewsMax is an action connector — post, schedule, read stats — not a deep-research source.`,
  },
  {
    q: "Does the MCP server support SSE or stdio?",
    a: "No. The server speaks Streamable HTTP only — the current MCP transport that Claude, ChatGPT, Codex, Cursor and Hermes all support. There is no SSE endpoint and nothing to run locally.",
  },
  {
    q: "How does authentication work?",
    a: `Two ways, both sent as a Bearer token. Chat apps use OAuth 2.1 with PKCE: the client registers itself, you sign in to ViewsMax in a browser and choose read-only or full access. Headless agents use an API key (vmx_…) generated under ${API_KEY_PATH}. Keys go in the Authorization header, never the URL.`,
  },
  {
    q: "Can I give an agent read-only access?",
    a: "Yes. Pick Read-only on the OAuth consent screen or generate a read-only API key. Read-only credentials carry the mcp:read scope, so write tools such as create_post don't even appear in the agent's tool list.",
  },
  {
    q: "Can I see what my AI agent did?",
    a: `Yes. Every tool call — the tool, its arguments and whether it failed — is recorded in your audit log under ${API_KEY_PATH}. Rotating the API key or disconnecting the OAuth client revokes access instantly.`,
  },
  {
    q: "Which platforms can an AI agent post to?",
    a: `${platformList} — whichever of them you have linked in ViewsMax. Platform logins stay inside ViewsMax; the agent only ever holds a ViewsMax permission.`,
  },
  {
    q: "Can it generate videos or images?",
    a: "No. The MCP server publishes media you already have: give the agent a public URL and upload_media hosts the file (JPEG, PNG, WebP, GIF, MP4 or MOV, up to 100 MB) for the post.",
  },
  {
    q: "Can it post to several accounts at once?",
    a: "Yes. One create_post call can target any mix of platforms, or a brand — a named group of accounts — with per-platform caption and option overrides. Each platform is published separately and keeps its own status, so one failure never blocks the rest.",
  },
  {
    q: "What are the rate limits?",
    a: "120 MCP requests a minute per credential. Per hour: 180 create_post, 40 upload_media, 60 fetch_outlier, 30 search_outliers, 30 generate_outlier_breakdown and 10 add_outlier_channel. A limited call returns a retry-after hint rather than failing silently.",
  },
  {
    q: "Does the MCP server cost extra?",
    a: "No. Connecting an agent is included on every plan, including the free one. What the agent can do follows your ViewsMax plan limits, such as monthly posts and active offers.",
  },
  {
    q: "Does it expose MCP resources or prompts?",
    a: `No — tools only. All ${MCP_TOOLS.length} capabilities are tools, and the server's own instructions tell the agent the typical posting and research flows. A JSON description of everything lives at ${DISCOVERY_URL}.`,
  },
];

const HOW_TO_STEPS = [
  {
    name: "Link your channels in ViewsMax",
    text: "Sign up, open Dashboard → Connections and link the platforms you publish to. The platform logins stay inside ViewsMax.",
  },
  {
    name: "Add the ViewsMax MCP server to your AI client",
    text: `Give the client the address ${MCP_ENDPOINT} — as a custom connector in Claude or ChatGPT, or with claude mcp add / codex mcp add on the command line.`,
  },
  {
    name: "Approve access",
    text: "Sign in when the client asks and choose read-only or full access. Headless agents use an API key from Settings → AI Assistant Access instead.",
  },
];

/** JSON-LD for /mcp: the server as software, the FAQ, the connect steps, and the breadcrumb under /ai. */
export function buildMcpJsonLd(): Record<string, unknown>[] {
  const url = `https://viewsmax.com${MCP_PATH}`;
  return [
    {
      "@context": "https://schema.org",
      "@type": "SoftwareApplication",
      name: "ViewsMax MCP Server",
      applicationCategory: "BusinessApplication",
      operatingSystem: "Web",
      url,
      description: MCP_PAGE_SEO.description,
      offers: { "@type": "Offer", price: "0", priceCurrency: "USD" },
      featureList: TOOL_GROUPS.map((g) => g.name),
    },
    {
      "@context": "https://schema.org",
      "@type": "FAQPage",
      mainEntity: MCP_FAQ.map((f) => ({
        "@type": "Question",
        name: f.q,
        acceptedAnswer: { "@type": "Answer", text: f.a },
      })),
    },
    {
      "@context": "https://schema.org",
      "@type": "HowTo",
      name: "How to connect an AI agent to ViewsMax",
      description: MCP_PAGE_SEO.description,
      url,
      step: HOW_TO_STEPS.map((s, i) => ({ "@type": "HowToStep", position: i + 1, name: s.name, text: s.text })),
    },
    {
      "@context": "https://schema.org",
      "@type": "BreadcrumbList",
      itemListElement: [
        { "@type": "ListItem", position: 1, name: "ViewsMax", item: "https://viewsmax.com/" },
        { "@type": "ListItem", position: 2, name: "AI agents", item: "https://viewsmax.com/ai" },
        { "@type": "ListItem", position: 3, name: "MCP server", item: url },
      ],
    },
  ];
}

/** Plain-markdown twin of the page, for agents that read docs directly. */
export function buildMcpMarkdown(): string {
  return [
    "# ViewsMax MCP server",
    "",
    MCP_PAGE_SEO.description,
    "",
    `- MCP endpoint: ${MCP_ENDPOINT} (Streamable HTTP; no SSE, no stdio)`,
    `- Capability discovery (JSON): ${DISCOVERY_URL}`,
    `- OAuth metadata: ${OAUTH_AS_URL} · dynamic client registration: POST ${OAUTH_REGISTER_URL}`,
    `- Human version of this page: https://viewsmax.com${MCP_PATH} · install hub: https://viewsmax.com/ai.md`,
    "",
    "## What an AI agent can do",
    "",
    ...CAPABILITIES.map((c) => `- ${c.task} (${c.access}: ${c.tools.join(", ")})`),
    "",
    "Not through the MCP:",
    "",
    ...NOT_SUPPORTED.map((n) => `- ${n}`),
    "",
    "## How to connect",
    "",
    ...AGENT_LIST.flatMap((a) => {
      const row = CLIENT_MATRIX[a.key];
      const code = clientSnippet(a);
      return [
        `### ${a.name}`,
        "",
        `${row.method} — ${row.auth}. Guide: https://viewsmax.com${a.slug} (plain text: https://viewsmax.com${a.slug}.md)`,
        ...(code ? ["", "```", code.text, "```"] : []),
        "",
      ];
    }),
    "## Authentication & security",
    "",
    ...AUTH_MODES.map((m) => `- **${m.name}** (${m.bestFor}). ${m.how}`),
    "- Scopes: mcp:read / mcp:write. Read-only credentials hide write tools from tools/list.",
    `- Every tools/call is recorded in the user's audit log (${API_KEY_PATH}).`,
    "",
    "Rate limits:",
    "",
    ...RATE_LIMITS.map((r) => `- ${r.scope}: ${r.limit}`),
    "",
    `## Tools (${MCP_TOOLS.length})`,
    "",
    ...TOOL_GROUPS.flatMap((g) => [`### ${g.name} (${g.tools.length})`, "", g.blurb, "", ...g.tools.map((t) => `- \`${t}\``), ""]),
    "No MCP resources or prompts — tools only.",
    "",
    "## Platforms",
    "",
    ...PLATFORMS.map((p) => `- ${p.name}: ${p.note}`),
    "",
    "## Prompts to try",
    "",
    ...EXAMPLE_PROMPTS.map((p) => `- **${p.title}:** ${p.prompt}`),
    "",
    "## FAQ",
    "",
    ...MCP_FAQ.flatMap((f) => [`**${f.q}**`, "", f.a, ""]),
  ].join("\n");
}
