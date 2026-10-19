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
import en from "./mcp-copy/en.json";
import es from "./mcp-copy/es.json";
import ru from "./mcp-copy/ru.json";
import ptBR from "./mcp-copy/pt-br.json";
import id from "./mcp-copy/id.json";

export const MCP_PATH = "/mcp";
export const MCP_MD_PATH = "/mcp.md";

const API_ORIGIN = MCP_ENDPOINT.replace(/\/api\/mcp$/, "");
export const DISCOVERY_URL = `${API_ORIGIN}/api/ai`;
export const DOCS_URL = `${API_ORIGIN}/docs`;
export const OAUTH_AS_URL = `${API_ORIGIN}/.well-known/oauth-authorization-server`;
export const OAUTH_PR_URL = `${API_ORIGIN}/.well-known/oauth-protected-resource`;
export const OAUTH_REGISTER_URL = `${API_ORIGIN}/oauth/register`;

const PLATFORM_NAMES = PLATFORMS.map((p) => p.name);

/* ---------- copy ----------
 * The page's words live in mcp-copy/<code>.json (en.json is the source; the
 * deploy's translate step fills the others, see src/i18n/catalog.ts). Live
 * values are {{placeholders}} filled here; <b>, <code>, <hub>… and <name/>
 * tags in page strings are rendered by the page. A string missing from a
 * translation falls back to English, so a new string never breaks a page. */
export type McpCopy = typeof en;
const TRANSLATIONS: Record<string, unknown> = { es, ru, "pt-br": ptBR, id };

function withEnglishFallback(source: unknown, translated: unknown): unknown {
  if (typeof source === "string") return typeof translated === "string" && translated.trim() ? translated : source;
  if (Array.isArray(source)) return source.map((v, i) => withEnglishFallback(v, Array.isArray(translated) ? translated[i] : undefined));
  if (source && typeof source === "object") {
    const t = translated && typeof translated === "object" ? (translated as Record<string, unknown>) : {};
    return Object.fromEntries(Object.entries(source).map(([k, v]) => [k, withEnglishFallback(v, t[k])]));
  }
  return source;
}

const fill = (value: unknown, vars: Record<string, string | number>): unknown => {
  if (typeof value === "string") return value.replace(/\{\{(\w+)\}\}/g, (m, k: string) => (k in vars ? String(vars[k]) : m));
  if (Array.isArray(value)) return value.map((v) => fill(v, vars));
  if (value && typeof value === "object") return Object.fromEntries(Object.entries(value).map(([k, v]) => [k, fill(v, vars)]));
  return value;
};

// "YouTube, TikTok … and Bluesky". English keeps its existing join (no serial comma).
const platformList = (lang: string) =>
  lang.startsWith("en")
    ? PLATFORM_NAMES.slice(0, -1).join(", ") + " and " + PLATFORM_NAMES.at(-1)
    : new Intl.ListFormat(lang, { type: "conjunction" }).format(PLATFORM_NAMES);

export interface ToolGroup {
  key: string;
  name: string;
  blurb: string;
  tools: string[];
}

/** Every MCP tool, grouped by job. The order here is the order the page lists them. */
const TOOL_GROUP_TOOLS: { key: keyof McpCopy["toolGroups"]; tools: string[] }[] = [
  { key: "accounts", tools: ["list_connected_accounts", "list_brands", "get_connect_url", "disconnect_account"] },
  { key: "posting", tools: ["upload_media", "create_post", "list_posts", "get_post", "update_post", "delete_post"] },
  { key: "offers", tools: ["list_offers", "get_offer", "update_offer", "delete_offer", "get_offer_stats", "get_stats_timeseries"] },
  {
    key: "outliers",
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
      "get_outlier_channel_ingest",
    ],
  },
];

export interface Capability {
  task: string;
  tools: string[];
  /** The scope the row needs; "write" rows disappear for read-only credentials. */
  access: "read" | "write";
}

const CAPABILITY_ROWS: { key: keyof McpCopy["capabilities"]; tools: string[]; access: Capability["access"] }[] = [
  { key: "accounts", tools: ["list_connected_accounts", "list_brands"], access: "read" },
  { key: "publish", tools: ["create_post", "update_post"], access: "write" },
  { key: "media", tools: ["upload_media"], access: "write" },
  { key: "results", tools: ["get_post", "list_posts"], access: "read" },
  { key: "offers", tools: ["list_offers", "get_offer"], access: "read" },
  { key: "updateOffer", tools: ["update_offer"], access: "write" },
  { key: "stats", tools: ["get_offer_stats", "get_stats_timeseries"], access: "read" },
  { key: "browseOutliers", tools: ["list_outliers", "get_outlier"], access: "read" },
  { key: "searchOutliers", tools: ["search_outliers", "fetch_outlier"], access: "write" },
  { key: "breakdown", tools: ["generate_outlier_breakdown", "get_outlier_breakdown"], access: "write" },
  { key: "saveOutliers", tools: ["save_outlier", "list_saved_outliers", "remove_saved_outlier"], access: "write" },
];

export interface AuthMode {
  key: "oauth" | "apiKey";
  name: string;
  bestFor: string;
  how: string;
}

export interface RateLimit {
  scope: string;
  limit: string;
  /** True when `scope` is a tool name (shown as code), false for "All MCP requests". */
  tool: boolean;
}

export interface ClientRow {
  /** How the client is pointed at the server. */
  method: string;
  /** Which credential(s) that route supports, per the agent's own guide. */
  auth: string;
}

export interface ExamplePrompt {
  title: string;
  prompt: string;
  /** Illustrative reply, written to match what the tools actually do. */
  reply: string;
}

/** Everything the /mcp page says, in one language. `code` is a HOME_LOCALES code; `lang` its BCP 47 tag. */
export function mcpContent(code = "en", lang = "en") {
  const merged = code === "en" ? en : (withEnglishFallback(en, TRANSLATIONS[code]) as McpCopy);
  const copy = fill(merged, {
    endpoint: MCP_ENDPOINT,
    oauthAs: OAUTH_AS_URL,
    oauthRegister: OAUTH_REGISTER_URL,
    discovery: DISCOVERY_URL,
    apiKeyPath: API_KEY_PATH,
    toolCount: MCP_TOOLS.length,
    platformCount: PLATFORMS.length,
    platforms: platformList(lang),
    platformNames: PLATFORM_NAMES.join(", "),
    read: merged.page.accessRead,
    write: merged.page.accessWrite,
  }) as McpCopy;
  const perHour = (n: number) => copy.rateLimits.perHour.replace("{{n}}", String(n));

  return {
    code,
    lang,
    copy,
    seo: copy.seo,
    toolGroups: TOOL_GROUP_TOOLS.map(({ key, tools }): ToolGroup => ({ key, ...copy.toolGroups[key], tools })),
    capabilities: CAPABILITY_ROWS.map(({ key, tools, access }): Capability => ({ task: copy.capabilities[key], tools, access })),
    notSupported: copy.notSupported,
    authModes: (["oauth", "apiKey"] as const).map((key): AuthMode => ({ key, ...copy.authModes[key] })),
    rateLimits: [
      { scope: copy.rateLimits.allScope, limit: copy.rateLimits.allLimit, tool: false },
      { scope: "create_post", limit: perHour(180), tool: true },
      { scope: "upload_media", limit: copy.rateLimits.uploadMedia, tool: true },
      { scope: "fetch_outlier", limit: perHour(60), tool: true },
      { scope: "search_outliers", limit: perHour(30), tool: true },
      { scope: "generate_outlier_breakdown", limit: perHour(30), tool: true },
    ] satisfies RateLimit[],
    clientMatrix: copy.clients as Record<AgentKey, ClientRow>,
    examplePrompts: (["schedule", "earner", "research"] as const).map((k): ExamplePrompt => copy.prompts[k]),
    faq: Object.values(copy.faq) as FaqItem[],
    howTo: { name: copy.howTo.name, steps: [copy.howTo.link, copy.howTo.add, copy.howTo.approve] },
  };
}

export type McpContent = ReturnType<typeof mcpContent>;

// The English page, for /mcp.md, the JSON-LD of /mcp and anything else that
// imported these before the page was translated.
const EN = mcpContent();
export const MCP_PAGE_SEO = EN.seo;
export const TOOL_GROUPS: ToolGroup[] = EN.toolGroups;
export const CAPABILITIES: Capability[] = EN.capabilities;
/** Things people ask for that the MCP does not do — stated so the page never over-claims. */
export const NOT_SUPPORTED = EN.notSupported;
export const AUTH_MODES: AuthMode[] = EN.authModes;
export const RATE_LIMITS: RateLimit[] = EN.rateLimits;
/** One row per AI agent guide, for the "which client supports what" table. */
export const CLIENT_MATRIX: Record<AgentKey, ClientRow> = EN.clientMatrix;
export const EXAMPLE_PROMPTS: ExamplePrompt[] = EN.examplePrompts;
export const MCP_FAQ: FaqItem[] = EN.faq;

/** The first copy-paste snippet from the agent's own install steps — the connector address for chat apps, the CLI command or config file for the rest. */
export const clientSnippet = (agent: AgentConfig) => agent.install.find((s) => s.code)?.code;

/** JSON-LD for /mcp (or a translation at `path`): the server as software, the FAQ, the connect steps, and the breadcrumb under /ai. */
export function buildMcpJsonLd(content: McpContent = EN, path = MCP_PATH, homePath = "/"): Record<string, unknown>[] {
  const url = `https://viewsmax.com${path}`;
  const inLanguage = content.code === "en" ? {} : { inLanguage: content.lang };
  return [
    {
      "@context": "https://schema.org",
      "@type": "SoftwareApplication",
      name: content.copy.jsonLd.appName,
      applicationCategory: "BusinessApplication",
      operatingSystem: "Web",
      url,
      description: content.seo.description,
      offers: { "@type": "Offer", price: "0", priceCurrency: "USD" },
      featureList: content.toolGroups.map((g) => g.name),
      ...inLanguage,
    },
    {
      "@context": "https://schema.org",
      "@type": "FAQPage",
      mainEntity: content.faq.map((f) => ({
        "@type": "Question",
        name: f.q,
        acceptedAnswer: { "@type": "Answer", text: f.a },
      })),
      ...inLanguage,
    },
    {
      "@context": "https://schema.org",
      "@type": "HowTo",
      name: content.howTo.name,
      description: content.seo.description,
      url,
      step: content.howTo.steps.map((s, i) => ({ "@type": "HowToStep", position: i + 1, name: s.name, text: s.text })),
      ...inLanguage,
    },
    {
      "@context": "https://schema.org",
      "@type": "BreadcrumbList",
      itemListElement: [
        { "@type": "ListItem", position: 1, name: "ViewsMax", item: `https://viewsmax.com${homePath}` },
        { "@type": "ListItem", position: 2, name: content.copy.page.breadcrumbAi, item: "https://viewsmax.com/ai" },
        { "@type": "ListItem", position: 3, name: content.copy.page.breadcrumbMcp, item: url },
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
