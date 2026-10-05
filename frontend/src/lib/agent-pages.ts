// Config for the per-agent setup pages (/claude, /chatgpt, /cursor, ...). One
// entry per AI agent drives the shared <AgentPage>, the build-time prerender
// (head tags + JSON-LD), and the /<agent>.md brief an AI can read.
//
// Directory listings: while ViewsMax isn't listed in an agent's app directory,
// the page leads with the manual setup. Once it is, set the listing's env var
// (e.g. VITE_CLAUDE_CONNECTOR_URL) and rebuild — the page then leads with the
// listing and moves the manual setup into a collapsed section.
//
// No import.meta.env access at module scope: scripts/prerender-seo.ts and
// vite.config.ts import this file under plain Node.

export const MCP_ENDPOINT = "https://api.viewsmax.com/api/mcp";
export const SKILL_URL = "https://viewsmax.com/skills/viewsmax/SKILL.md";
export const API_KEY_PATH = "Settings → AI Assistant Access";
export const UPDATED = "September 2026";

/** Every MCP tool the server exposes (backend/app/Mcp/Tools), in the order ai.md lists them. */
export const MCP_TOOLS = [
  "list_connected_accounts", "list_brands", "upload_media", "create_post",
  "list_posts", "get_post", "update_post", "delete_post", "list_offers",
  "create_offer", "get_offer", "update_offer", "delete_offer",
  "create_tracking_link", "get_offer_stats", "get_stats_timeseries",
  "disconnect_account", "get_connect_url", "create_feature_request",
  "list_outliers", "search_outliers", "get_outlier", "fetch_outlier",
  "get_outlier_breakdown", "generate_outlier_breakdown", "list_saved_outliers",
  "save_outlier", "remove_saved_outlier", "add_outlier_channel",
  "get_outlier_channel_ingest", "get_transcript",
];

export type AgentKey =
  | "claude"
  | "claude-cowork"
  | "claude-code"
  | "chatgpt"
  | "codex"
  | "cursor"
  | "openclaw"
  | "hermes";

export interface FaqItem {
  q: string;
  a: string;
}

export interface InstallStep {
  title: string;
  body: string;
  code?: { label: string; text: string };
  note?: string;
}

/** An app-directory listing. The URL comes from env so it can be set at deploy time. */
export interface Listing {
  env: "VITE_CLAUDE_CONNECTOR_URL" | "VITE_CHATGPT_PLUGIN_URL";
  label: string; // button text, e.g. "Add to Claude"
  steps: string; // what the user does once they reach the listing
  code?: { label: string; text: string };
  /** Section label on the page; defaults to "Plugin (easiest)". */
  heading?: string;
}

export interface AgentConfig {
  key: AgentKey;
  slug: string;
  name: string;
  /** One line for the "other agents" cards. */
  card: string;
  /** "mcp" = connects to the MCP server; "skill" = a skill that drives the REST API. */
  transport: "mcp" | "skill";
  seo: { title: string; description: string; keywords: string };
  h1: string;
  subhead: string;
  setupTime: string;
  listing?: Listing;
  /** Shown above the manual steps while unlisted (e.g. a connector that carries over from another app). */
  tip?: string;
  /** The manual "add ViewsMax to <agent>" steps. */
  install: InstallStep[];
  /** Section label for the manual steps; defaults by transport (see manualHeading()). */
  manualHeading?: string;
  /** Optional API-key route for headless / scripted use. */
  apiKeyRoute?: InstallStep;
  firstTask: string;
  /** Illustrative reply to firstTask, shown as "Example reply". */
  firstReply: string;
  /** Time label for the "add ViewsMax" step. */
  addTime: string;
  prompts: { title: string; prompt: string }[];
  troubleshooting: FaqItem[];
  faq: FaqItem[];
}

const CLAUDE_LISTING: Listing = {
  env: "VITE_CLAUDE_CONNECTOR_URL",
  label: "Add to Claude",
  steps:
    "Press Add to Claude. Claude sends you to ViewsMax, where you create an account or sign in, connect your channels, then pick Full access or Read-only and press Approve. Nothing to paste.",
};

const CHATGPT_LISTING: Listing = {
  env: "VITE_CHATGPT_PLUGIN_URL",
  label: "Open in ChatGPT",
  steps:
    "Open ViewsMax in the ChatGPT plugin directory and press Install. ChatGPT sends you to ViewsMax, where you create an account or sign in, connect your channels, then pick Full access or Read-only and press Approve. Nothing to paste.",
};

// Claude Code picks up connectors from the claude.ai account it's logged in
// with, so the Claude directory listing covers it too.
// https://code.claude.com/docs/en/mcp#use-mcp-servers-from-claudeai
const CLAUDE_CODE_LISTING: Listing = {
  env: "VITE_CLAUDE_CONNECTOR_URL",
  label: "Add to Claude",
  steps:
    "Connect ViewsMax from its Claude directory listing (create an account or sign in on the ViewsMax screen, then Approve). Claude Code shares connectors with the claude.ai account you log in with, so ViewsMax appears there with no extra setup — /mcp lists it.",
  code: { label: "Inside Claude Code", text: "/mcp" },
};

// ChatGPT and Codex share one plugin directory; Codex installs from /plugins.
// https://help.openai.com/en/articles/20001256-plugins-in-chatgpt-and-codex
const CODEX_LISTING: Listing = {
  env: "VITE_CHATGPT_PLUGIN_URL",
  label: "View in the plugin directory",
  steps:
    "Codex reads from the same plugin directory as ChatGPT. Type /plugins in the Codex CLI, look up ViewsMax, install it, then create an account or sign in on the ViewsMax screen and press Approve.",
  code: { label: "Inside Codex", text: "/plugins" },
};

/** `icon` is a key of components/post/brand-icons; `color` is the brand tile colour. */
export const PLATFORMS: { name: string; icon: string; color: string; note: string }[] = [
  { name: "YouTube", icon: "youtube", color: "#FF0000", note: "One video. You'll be asked whether it goes out public, unlisted or private." },
  { name: "TikTok", icon: "tiktok", color: "#0A0A0C", note: "A video or a photo set. You'll be asked to pick the privacy level." },
  { name: "Instagram", icon: "instagram", color: "#DD2A7B", note: "One photo or one Reel, on an Instagram Business or Creator account." },
  { name: "X", icon: "x", color: "#0A0A0C", note: "Text, with up to 4 images. No video yet." },
  { name: "LinkedIn", icon: "linkedin", color: "#0A66C2", note: "Text, with one image. No video yet." },
  { name: "Threads", icon: "threads", color: "#0A0A0C", note: "Text, with one image or one video." },
  { name: "Bluesky", icon: "bluesky", color: "#0085FF", note: "Text, with up to 4 images. No video yet." },
];

const PLATFORM_NAMES = PLATFORMS.map((p) => p.name).join(", ");

export const CONNECT_STEP: InstallStep = {
  title: "Link the accounts you publish to",
  body:
    "Sign up for ViewsMax, go to Dashboard → Connections, and link each channel. The platform logins happen inside ViewsMax, so your AI only ever holds a ViewsMax permission — and new channels you add later show up for it straight away.",
};

export const TEST_STEP_TITLE = "Run a quick test";
export const TRY_STEP_TITLE = "Give it a real job";

/** Time labels shown on the numbered steps. */
export const STEP_TIMES = { connect: "≈2 min · one-time", test: "≈30 sec", try: "≈1 min" };

export const MULTI_ACCOUNT_TIP =
  "Got two accounts on the same platform? Name the one you mean (\"my @brand Instagram\") so your AI doesn't have to stop and ask.";

/** What happens after the first task. Kept agent-neutral; `{name}` is filled in. */
export const WHAT_HAPPENS = [
  "Before it uses a ViewsMax tool that changes something, {name} asks for your OK.",
  "ViewsMax stores the post as a draft, or queues it for the time you gave.",
  "At publish time ViewsMax sends it to each channel separately and keeps a result per platform, so one failure never blocks the rest.",
];

export const CHECK_PROMPT = "Which of my social accounts can you reach through ViewsMax?";

const SHARED_PROMPTS: { title: string; prompt: string }[] = [
  {
    title: "Queue a post",
    prompt:
      "Put this on my LinkedIn and Bluesky for Thursday at 8:30am my time: \"Our free content calendar template is out — grab it from the link in my bio.\"",
  },
  {
    title: "Cross-post a clip",
    prompt:
      "Take the video at https://example.com/reel.mp4 and make it an Instagram Reel and a TikTok draft. Give TikTok a shorter caption than Instagram.",
  },
  {
    title: "Track a promotion",
    prompt:
      "I'm promoting my ebook at https://example.com/ebook. Set it up as an offer with sales as the goal, and make separate tracked links for YouTube and Instagram.",
  },
  {
    title: "Find your best earner",
    prompt: "Rank my offers by revenue for the past 30 days and tell me which traffic source is actually converting.",
  },
  {
    title: "Borrow what's working",
    prompt:
      "Pull YouTube outliers in personal finance from this month and explain the hook behind the top three. Save the best one to my library.",
  },
  {
    title: "Tidy the queue",
    prompt: "What's going out this weekend? Push anything on X back by two hours.",
  },
];

const REPO_PROMPTS: { title: string; prompt: string }[] = [
  {
    title: "Explain a feature",
    prompt:
      "Look at the changes on this branch and write a short X thread explaining the new feature to non-developers. Save it as a ViewsMax draft.",
  },
  ...SHARED_PROMPTS.slice(1),
];

const SHARED_TROUBLESHOOTING: FaqItem[] = [
  {
    q: "No ViewsMax tools in the list",
    a: "Make sure ViewsMax is switched on for the conversation you're in, then open a fresh one. Still missing? Remove ViewsMax and connect it again.",
  },
  {
    q: "Reads work, but posting is refused",
    a: "The connection was approved as read-only. Reconnect and pick full access on the ViewsMax approval screen, or swap in a full-access API key.",
  },
  {
    q: "Media won't upload",
    a: "Uploading a file into the chat window isn't enough — ViewsMax downloads media from a link. Host the file at a public https address that serves the file itself, not a preview page. Accepted: JPEG, PNG, WebP, GIF, MP4, MOV.",
  },
  {
    q: "A channel isn't listed",
    a: "Link it under Dashboard → Connections in ViewsMax (your AI can also hand you a connect link), then run the test prompt again.",
  },
  {
    q: "One platform published, another didn't",
    a: "Each platform is published separately in the background and keeps its own status. Ask for the status of that post to see the exact error the platform sent back.",
  },
];

const sharedFaq = (name: string): FaqItem[] => [
  {
    q: `Can ${name} post to social media?`,
    a: `Yes, once ViewsMax is connected. ${name} can't log in to Instagram, TikTok or any other platform itself, so ViewsMax acts as the bridge: ${name} drafts, times and sends out posts on ${PLATFORM_NAMES} through the channels you've linked in ViewsMax.`,
  },
  {
    q: `Does ${name} see my social media passwords?`,
    a: `Never. Your platform logins never leave ViewsMax. ${name} holds a ViewsMax permission — read-only or full, your choice — and every action it takes is logged under ${API_KEY_PATH}.`,
  },
  {
    q: `Will ${name} post without asking me?`,
    a: `${name} checks with you before it runs anything that changes your account. If you want an extra safety net, tell it to keep everything as drafts and approve them yourself in ViewsMax.`,
  },
  {
    q: "How much does it cost?",
    a: "Connecting an AI agent costs nothing extra. What it can do follows your ViewsMax plan, such as monthly posts and active offers. The free plan is $0, and paid plans start at $29 a month — compare them on viewsmax.com.",
  },
  {
    q: "What is MCP, and why does ViewsMax use it?",
    a: `MCP (Model Context Protocol) is the open standard AI apps use to call outside tools. ViewsMax runs an MCP server, so any agent that speaks MCP gets the same ${MCP_TOOLS.length} tools — posting, offers, analytics and outlier research — after a single sign-in.`,
  },
  {
    q: "What else can it do besides posting?",
    a: "Quite a lot. The same connection sets up offers and tracked links, reports clicks, booked calls, sign-ups and revenue per offer, and digs into outlier videos on YouTube, TikTok and Instagram with an AI breakdown of each.",
  },
];

const claudeConnectorSteps = (surface: string): InstallStep[] => [
  {
    title: "Go to connectors",
    body: `In ${surface}, choose Customize → Connectors, press +, and pick Add custom connector.`,
  },
  {
    title: "Paste the ViewsMax address",
    body: "Call it ViewsMax, paste the address below, and save with Add.",
    code: { label: "ViewsMax MCP address", text: MCP_ENDPOINT },
  },
  {
    title: "Approve access",
    body: "Press Connect, log in to ViewsMax, pick Full access or Read-only, and press Approve.",
    note: "Custom connectors work on every Claude plan. Pro and Max: the steps above are all you need. Free: you're limited to one custom connector. Team and Enterprise: an owner adds it first under Organization settings → Connectors, then everyone else presses Connect in Customize → Connectors.",
  },
];

const claudeCodeApiKey = `claude mcp add --transport http --scope user viewsmax ${MCP_ENDPOINT} --header "Authorization: Bearer vmx_YOUR_KEY"`;

const title = (name: string) => `Post, Find Outliers, and Track Sales from ${name}`;

export const AGENTS: Record<AgentKey, AgentConfig> = {
  claude: {
    key: "claude",
    addTime: "≈1 min · one-time",
    slug: "/claude",
    name: "Claude",
    card: "One connector across all of Claude's apps",
    transport: "mcp",
    seo: {
      title: `${title("Claude")} | ViewsMax`,
      description:
        "Connect ViewsMax to Claude and schedule posts to YouTube, TikTok, Instagram, X, LinkedIn, Threads and Bluesky from a chat, then see which ones drive sales.",
      keywords:
        "post to social media from claude, claude social media, claude connector, claude mcp social media, schedule posts with claude, viewsmax claude",
    },
    h1: title("Claude"),
    subhead:
      "Write the post, pick the channels, set the time — all in a Claude chat. ViewsMax does the publishing, tracks your offers, shows you the outlier videos breaking out in your niche, and tells you which content turns into sales.",
    setupTime: "Around 5 minutes in your browser.",
    listing: CLAUDE_LISTING,
    install: claudeConnectorSteps("Claude"),
    firstTask:
      "Write a LinkedIn post announcing our spring sale (20% off until Friday) and keep it as a ViewsMax draft so I can look it over.",
    firstReply:
      "Saved a LinkedIn draft in ViewsMax: \"Spring sale is on — 20% off everything until Friday.\" It's not scheduled yet. Want me to set a time, or tweak the copy first?",
    prompts: SHARED_PROMPTS,
    troubleshooting: SHARED_TROUBLESHOOTING,
    faq: [
      ...sharedFaq("Claude"),
      {
        q: "Does it work in the Claude mobile app, Cowork, and Claude Code?",
        a: "Yes. Connectors belong to your Claude account, so ViewsMax follows you to the web app, desktop app, mobile apps and Cowork — and to Claude Code when you log in there with the same claude.ai account.",
      },
      {
        q: "Claude, Claude Code, or Cowork — which guide do I need?",
        a: "This one if you chat with Claude. Pick the Claude Code guide if you live in the terminal, or the Cowork guide for Claude's desktop agent.",
      },
    ],
  },

  "claude-cowork": {
    key: "claude-cowork",
    addTime: "≈1 min · one-time (skip if Claude has it)",
    slug: "/claude-cowork",
    name: "Claude Cowork",
    card: "Same connector as Claude, used by the desktop agent",
    transport: "mcp",
    seo: {
      title: `${title("Claude Cowork")} | ViewsMax`,
      description:
        "Let Claude Cowork schedule and publish your social posts through ViewsMax. One connector covers YouTube, TikTok, Instagram, X, LinkedIn, Threads and Bluesky.",
      keywords:
        "claude cowork social media, claude cowork connector, cowork schedule posts, claude cowork mcp, viewsmax cowork",
    },
    h1: title("Claude Cowork"),
    subhead:
      "Give Cowork a content brief and a deadline. With ViewsMax connected it can study the outlier videos in your niche, line up a week of posts, attach your media, create tracked links, and come back with the numbers.",
    setupTime: "Around 5 minutes — less if Claude already has ViewsMax.",
    listing: CLAUDE_LISTING,
    install: claudeConnectorSteps("Claude").map((s, i) =>
      i === 0
        ? { ...s, body: `${s.body} Cowork shares your Claude account's connectors — if ViewsMax is already there, jump to step 3.` }
        : s,
    ),
    firstTask:
      "Draft five LinkedIn posts for next week, one per weekday at 8am, about our product launch. Keep them as drafts and send me the list.",
    firstReply:
      "Five LinkedIn drafts are in ViewsMax, Monday to Friday at 8:00: teaser, problem, demo, customer quote, launch-day post. Nothing is scheduled until you approve them.",
    prompts: SHARED_PROMPTS,
    troubleshooting: SHARED_TROUBLESHOOTING,
    faq: [
      ...sharedFaq("Cowork"),
      {
        q: "Do I need to set ViewsMax up separately for Cowork?",
        a: "No. Cowork reads the connectors on your Claude account, so a single ViewsMax connection covers both.",
      },
    ],
  },

  "claude-code": {
    key: "claude-code",
    addTime: "≈1 min · one-time",
    slug: "/claude-code",
    name: "Claude Code",
    card: "One terminal command, sign in over OAuth",
    transport: "mcp",
    seo: {
      title: `${title("Claude Code")} | ViewsMax`,
      description:
        "One command connects Claude Code to ViewsMax. Schedule posts to YouTube, TikTok, Instagram, X, LinkedIn, Threads and Bluesky from the terminal.",
      keywords:
        "claude code social media, claude code mcp, post from terminal, claude code schedule posts, claude mcp add social media, viewsmax claude code",
    },
    h1: title("Claude Code"),
    subhead:
      "Announce a launch without leaving your editor. Claude Code can read what you just built, write the posts, schedule them through ViewsMax, report back on clicks and sales, and dig up outlier videos for your next idea.",
    setupTime: "Around 2 minutes.",
    listing: CLAUDE_CODE_LISTING,
    tip: "Already connected ViewsMax in Claude? Claude Code inherits connectors from the claude.ai account you log in with — run /mcp and look for it before adding anything. (On Team and Enterprise, only admins can add connectors in claude.ai.)",
    install: [
      {
        title: "Register the server",
        body: "Paste this into your terminal. The --scope user flag makes ViewsMax available across all your projects.",
        code: { label: "Terminal", text: `claude mcp add --transport http --scope user viewsmax ${MCP_ENDPOINT}` },
        note: "Claude Code comes with Claude Pro, Max, Team and Enterprise plans, or a Claude Console account with API credits.",
      },
      {
        title: "Log in",
        body: "Open Claude Code, type /mcp, choose viewsmax, then approve ViewsMax in the browser window that opens (read-only or full access).",
        code: { label: "Inside Claude Code", text: "/mcp" },
      },
    ],
    apiKeyRoute: {
      title: "No browser (CI, servers)?",
      body: `Generate an API key in ViewsMax under ${API_KEY_PATH} and pass it as a header instead.`,
      code: { label: "Terminal", text: claudeCodeApiKey },
    },
    firstTask:
      "Summarise what changed in the last three commits as a LinkedIn update and a shorter X version. Keep both as ViewsMax drafts.",
    firstReply:
      "Read the last three commits and saved two ViewsMax drafts: a LinkedIn update (4 short paragraphs) and a 240-character X post. Neither is scheduled — say when and I'll queue them.",
    prompts: REPO_PROMPTS,
    troubleshooting: [
      {
        q: "Claude Code asks you to authenticate",
        a: "Type /mcp, choose viewsmax, and complete the login in the browser window.",
      },
      ...SHARED_TROUBLESHOOTING.slice(1),
    ],
    faq: [
      ...sharedFaq("Claude Code"),
      {
        q: "Can Claude Code post a video from my project folder?",
        a: "Not straight from disk — ViewsMax downloads media from a public https link. Put the file somewhere public first and give Claude Code that address.",
      },
    ],
  },

  chatgpt: {
    key: "chatgpt",
    addTime: "≈2 min · one-time",
    slug: "/chatgpt",
    name: "ChatGPT",
    card: "Custom connector in Developer mode",
    transport: "mcp",
    seo: {
      title: `${title("ChatGPT")} | ViewsMax`,
      description:
        "Connect ViewsMax to ChatGPT and schedule posts to YouTube, TikTok, Instagram, X, LinkedIn, Threads and Bluesky by asking, then see which ones drive sales.",
      keywords:
        "post to social media from chatgpt, chatgpt social media, chatgpt connector, chatgpt mcp, chatgpt developer mode mcp, schedule posts with chatgpt, viewsmax chatgpt",
    },
    h1: title("ChatGPT"),
    subhead:
      "Turn a ChatGPT conversation into a publishing desk. Ask for the post, the channels and the time; ViewsMax sends it out, tracks your offers, surfaces outlier videos worth copying, and shows you what's selling.",
    setupTime: "Around 5 minutes in your browser.",
    listing: CHATGPT_LISTING,
    install: [
      {
        title: "Switch on Developer mode",
        body: "In ChatGPT, go to Settings → Security and login and enable Developer mode.",
        note: "Whether you see Developer mode depends on your ChatGPT plan, and on work accounts, on your admin's settings.",
      },
      {
        title: "Create the ViewsMax connection",
        body: "Visit chatgpt.com/plugins, press +, call it ViewsMax, paste the address below, and create it.",
        code: { label: "ViewsMax MCP address", text: MCP_ENDPOINT },
      },
      {
        title: "Approve access",
        body: "When ChatGPT sends you to ViewsMax, log in, pick Full access or Read-only, and press Approve.",
      },
    ],
    firstTask:
      "Write a LinkedIn post announcing our spring sale (20% off until Friday) and keep it as a ViewsMax draft so I can look it over.",
    firstReply:
      "Saved a LinkedIn draft in ViewsMax: \"Spring sale is on — 20% off everything until Friday.\" It's not scheduled yet. Want me to set a time, or tweak the copy first?",
    prompts: SHARED_PROMPTS,
    troubleshooting: [
      {
        q: "Developer mode is missing",
        a: "It's in Settings → Security and login. If there's no toggle, your plan or workspace hasn't enabled it.",
      },
      ...SHARED_TROUBLESHOOTING,
    ],
    faq: [
      ...sharedFaq("ChatGPT"),
      {
        q: "Does it work in Codex too?",
        a: "Once ViewsMax is in the plugin directory, yes — ChatGPT and Codex share it, and Codex installs from /plugins. A Developer-mode connector doesn't carry over, so follow the Codex guide for that route.",
      },
    ],
  },

  codex: {
    key: "codex",
    addTime: "≈1 min · one-time",
    slug: "/codex",
    name: "Codex",
    card: "Two commands in the Codex CLI",
    transport: "mcp",
    seo: {
      title: `${title("Codex")} | ViewsMax`,
      description:
        "Connect Codex to ViewsMax with two commands and schedule posts to YouTube, TikTok, Instagram, X, LinkedIn, Threads and Bluesky from the CLI or IDE extension.",
      keywords:
        "codex social media, codex mcp, codex mcp add, openai codex post to social media, codex schedule posts, viewsmax codex",
    },
    h1: title("Codex"),
    subhead:
      "Let Codex write the launch posts for the code it just helped you ship, track the sales they bring in, and research outlier videos for what to post next. One setup covers the Codex CLI, the IDE extension and the ChatGPT desktop app.",
    setupTime: "Around 2 minutes.",
    listing: CODEX_LISTING,
    install: [
      {
        title: "Register the server",
        body: "Paste this into your terminal.",
        code: { label: "Terminal", text: `codex mcp add viewsmax --url ${MCP_ENDPOINT}` },
      },
      {
        title: "Log in",
        body: "Run the login command then approve ViewsMax in the browser window that opens (read-only or full access).",
        code: { label: "Terminal", text: "codex mcp login viewsmax" },
        note: "The CLI, the IDE extension and the ChatGPT desktop app read the same config, so this only has to be done once.",
      },
    ],
    apiKeyRoute: {
      title: "No browser (CI, servers)?",
      body: `Generate an API key in ViewsMax under ${API_KEY_PATH}, put it in a VIEWSMAX_API_KEY environment variable, and reference it from ~/.codex/config.toml.`,
      code: {
        label: "~/.codex/config.toml",
        text: `[mcp_servers.viewsmax]\nurl = "${MCP_ENDPOINT}"\nbearer_token_env_var = "VIEWSMAX_API_KEY"`,
      },
    },
    firstTask:
      "Summarise what changed in the last three commits as a LinkedIn update and a shorter X version. Keep both as ViewsMax drafts.",
    firstReply:
      "Read the last three commits and saved two ViewsMax drafts: a LinkedIn update (4 short paragraphs) and a 240-character X post. Neither is scheduled — say when and I'll queue them.",
    prompts: REPO_PROMPTS,
    troubleshooting: [
      {
        q: "Codex reports it isn't authorized",
        a: "Run codex mcp login viewsmax again and finish the login in the browser.",
      },
      ...SHARED_TROUBLESHOOTING.slice(1),
    ],
    faq: sharedFaq("Codex"),
  },

  cursor: {
    key: "cursor",
    addTime: "≈2 min · one-time",
    slug: "/cursor",
    name: "Cursor",
    card: "MCP config with an API key",
    transport: "mcp",
    seo: {
      title: `${title("Cursor")} | ViewsMax`,
      description:
        "Add ViewsMax to Cursor's mcp.json and let the Agent schedule posts to YouTube, TikTok, Instagram, X, LinkedIn, Threads and Bluesky while you code.",
      keywords:
        "cursor social media, cursor mcp, cursor mcp.json, cursor agent post to social media, cursor schedule posts, viewsmax cursor",
    },
    h1: title("Cursor"),
    subhead:
      "Keep coding while Cursor's Agent handles the announcement: it drafts and schedules posts, builds tracked links, pulls your offer numbers, and finds outlier videos in your niche through ViewsMax.",
    setupTime: "Around 3 minutes.",
    install: [
      {
        title: "Generate an API key",
        body: `In ViewsMax, open ${API_KEY_PATH} and create a key — full access to publish, read-only for reporting. It begins with vmx_ and is only displayed once.`,
      },
      {
        title: "Add it to Cursor",
        body: "Save this in ~/.cursor/mcp.json so every project can use ViewsMax, then restart Cursor. A project-level .cursor/mcp.json works too — just keep a file containing your key out of git.",
        code: {
          label: "~/.cursor/mcp.json",
          text: `{\n  "mcpServers": {\n    "viewsmax": {\n      "url": "${MCP_ENDPOINT}",\n      "headers": { "Authorization": "Bearer vmx_YOUR_KEY" }\n    }\n  }\n}`,
        },
      },
    ],
    firstTask:
      "Turn the README's feature list into a LinkedIn post and a shorter X version, and keep both as ViewsMax drafts.",
    firstReply:
      "Pulled six features from README.md and saved two ViewsMax drafts: a LinkedIn post with a bullet list, and a one-line X version. Want them scheduled?",
    prompts: SHARED_PROMPTS,
    troubleshooting: [
      {
        q: "The server appears but has no tools",
        a: "Check the key sits right after \"Bearer \" with no stray spaces and hasn't been rotated, then restart Cursor.",
      },
      ...SHARED_TROUBLESHOOTING.slice(1),
    ],
    faq: [
      ...sharedFaq("Cursor"),
      {
        q: "How do I revoke Cursor's access?",
        a: `Rotate the API key under ${API_KEY_PATH} in ViewsMax. The previous key stops working at once.`,
      },
    ],
  },

  openclaw: {
    key: "openclaw",
    addTime: "≈3 min · one-time",
    slug: "/openclaw",
    name: "OpenClaw",
    card: "ViewsMax skill with an API key",
    transport: "skill",
    seo: {
      title: `${title("OpenClaw")} | ViewsMax`,
      description:
        "Install the ViewsMax skill in OpenClaw and let your agent schedule posts to YouTube, TikTok, Instagram, X, LinkedIn, Threads and Bluesky from any chat.",
      keywords:
        "openclaw social media, openclaw skill, openclaw post to tiktok, openclaw schedule posts, openclaw instagram, viewsmax openclaw",
    },
    h1: title("OpenClaw"),
    subhead:
      "Message your OpenClaw agent from Telegram, Discord or wherever it lives, and it can schedule posts, track offers, report sales and research outlier videos through the ViewsMax skill.",
    setupTime: "Around 5 minutes.",
    install: [
      {
        title: "Generate an API key",
        body: `In ViewsMax, open ${API_KEY_PATH} and create a full-access key. It begins with vmx_ and is only displayed once.`,
      },
      {
        title: "Install the skill",
        body: "Download the ViewsMax skill and install it for every local agent.",
        code: {
          label: "Terminal",
          text: `mkdir -p viewsmax && curl -o viewsmax/SKILL.md ${SKILL_URL}\nopenclaw skills install ./viewsmax --global`,
        },
      },
      {
        title: "Hand the skill your key",
        body: "Put the key in ~/.openclaw/openclaw.json so the skill can call ViewsMax.",
        code: {
          label: "~/.openclaw/openclaw.json",
          text: `{\n  skills: {\n    entries: {\n      viewsmax: {\n        enabled: true,\n        env: { VIEWSMAX_API_KEY: "vmx_YOUR_KEY" },\n      },\n    },\n  },\n}`,
        },
      },
    ],
    firstTask:
      "Write a LinkedIn post announcing our spring sale (20% off until Friday) and keep it as a ViewsMax draft so I can look it over.",
    firstReply:
      "Saved a LinkedIn draft in ViewsMax: \"Spring sale is on — 20% off everything until Friday.\" It's not scheduled yet. Want me to set a time, or tweak the copy first?",
    prompts: SHARED_PROMPTS,
    troubleshooting: [
      {
        q: "The agent can't find a ViewsMax key",
        a: "Confirm VIEWSMAX_API_KEY is set under skills.entries.viewsmax.env in ~/.openclaw/openclaw.json, then restart the agent.",
      },
      ...SHARED_TROUBLESHOOTING.slice(2),
    ],
    faq: [
      ...sharedFaq("OpenClaw"),
      {
        q: "Does OpenClaw use MCP or the skill?",
        a: "The skill, which talks to the ViewsMax REST API with your key. If your OpenClaw build supports remote MCP servers, the ViewsMax MCP address works as an alternative.",
      },
    ],
  },

  hermes: {
    key: "hermes",
    addTime: "≈1 min · one-time",
    slug: "/hermes",
    name: "Hermes Agent",
    card: "MCP server in config.yaml, sign in over OAuth",
    transport: "mcp",
    seo: {
      title: `${title("Hermes Agent")} | ViewsMax`,
      description:
        "Add ViewsMax to Hermes Agent's config.yaml and let it schedule posts to YouTube, TikTok, Instagram, X, LinkedIn, Threads and Bluesky.",
      keywords:
        "hermes agent social media, hermes agent mcp, nous hermes agent post, hermes config.yaml mcp, viewsmax hermes",
    },
    h1: title("Hermes Agent"),
    subhead:
      "Four lines of config give Hermes Agent the ViewsMax toolset — publishing, offer tracking, sales reporting and outlier research — with a browser login the first time it connects.",
    setupTime: "Around 3 minutes.",
    install: [
      {
        title: "Edit your Hermes config",
        body: "Add ViewsMax under mcp_servers in ~/.hermes/config.yaml.",
        code: {
          label: "~/.hermes/config.yaml",
          text: `mcp_servers:\n  viewsmax:\n    url: "${MCP_ENDPOINT}"\n    auth: oauth`,
        },
      },
      {
        title: "Log in",
        body: "Start Hermes. The first time it connects to ViewsMax it shows a sign-in link (and opens your browser when it can). Log in to ViewsMax, pick Full access or Read-only, and press Approve.",
      },
    ],
    apiKeyRoute: {
      title: "Headless server?",
      body: `Generate an API key in ViewsMax under ${API_KEY_PATH} and send it as a header in place of auth: oauth.`,
      code: {
        label: "~/.hermes/config.yaml",
        text: `mcp_servers:\n  viewsmax:\n    url: "${MCP_ENDPOINT}"\n    headers:\n      Authorization: "Bearer vmx_YOUR_KEY"`,
      },
    },
    firstTask:
      "Write a LinkedIn post announcing our spring sale (20% off until Friday) and keep it as a ViewsMax draft so I can look it over.",
    firstReply:
      "Saved a LinkedIn draft in ViewsMax: \"Spring sale is on — 20% off everything until Friday.\" It's not scheduled yet. Want me to set a time, or tweak the copy first?",
    prompts: SHARED_PROMPTS,
    troubleshooting: [
      {
        q: "Hermes lost its ViewsMax login",
        a: "Run hermes mcp login viewsmax to sign in again. Hermes keeps the login in ~/.hermes/mcp-tokens/ and renews it on its own until that fails.",
      },
      ...SHARED_TROUBLESHOOTING,
    ],
    faq: sharedFaq("Hermes Agent"),
  },
};

export const AGENT_LIST: AgentConfig[] = [
  AGENTS.claude,
  AGENTS["claude-code"],
  AGENTS["claude-cowork"],
  AGENTS.chatgpt,
  AGENTS.codex,
  AGENTS.cursor,
  AGENTS.openclaw,
  AGENTS.hermes,
];

type Env = Record<string, string | boolean | undefined>;

/** Label for the directory ("plugin") route on the page. */
export function listingHeading(agent: AgentConfig): string {
  return agent.listing?.heading ?? "Plugin (easiest)";
}

/** Label for the manual steps. MCP agents add a custom connector; skill agents set up by hand. */
export function manualHeading(agent: AgentConfig): string {
  return agent.manualHeading ?? (agent.transport === "mcp" ? "Custom connector" : "Set up by hand");
}

/** The agent's directory listing URL, or undefined while ViewsMax isn't listed there. */
export function agentListingUrl(agent: AgentConfig, env: Env): string | undefined {
  if (!agent.listing) return undefined;
  const raw = env[agent.listing.env];
  const url = typeof raw === "string" ? raw.trim() : "";
  return url.startsWith("https://") ? url : undefined;
}

/** Path of the plain-markdown brief, next to /ai.md, /about.md and /pricing.md. */
export const briefPath = (agent: AgentConfig) => `${agent.slug}.md`;

/** The "add ViewsMax to <agent>" step title. */
export const addStepTitle = (agent: AgentConfig) => `Plug ViewsMax into ${agent.name}`;

const mdStep = (s: InstallStep) =>
  [
    `- **${s.title}.** ${s.body}`,
    s.code ? `\n  \`\`\`\n${s.code.text.replace(/^/gm, "  ")}\n  \`\`\`` : "",
    s.note ? `\n  ${s.note}` : "",
  ].join("");

/** Plain-markdown brief an AI agent can read or a user can paste into a chat. */
export function buildAgentMarkdown(agent: AgentConfig, env: Env): string {
  const listing = agentListingUrl(agent, env);
  const listingCode = agent.listing?.code ? ["", "```", agent.listing.code.text, "```"] : [];
  const add = listing
    ? [`${listingHeading(agent)}: ${agent.listing!.steps} Listing: ${listing}`, ...listingCode, "", `${manualHeading(agent)}:`, "", ...agent.install.map(mdStep)]
    : [...(agent.tip ? [agent.tip, ""] : []), ...agent.install.map(mdStep)];

  return [
    `# ViewsMax setup brief: ${agent.name}`,
    "",
    agent.subhead,
    "",
    `MCP endpoint: ${MCP_ENDPOINT}. Full docs: https://viewsmax.com/ai.md. Human version of this page: https://viewsmax.com${agent.slug}.`,
    "",
    `## 1. ${CONNECT_STEP.title}`,
    "",
    CONNECT_STEP.body,
    "",
    `## 2. ${addStepTitle(agent)}`,
    "",
    ...add,
    ...(agent.apiKeyRoute ? ["", mdStep(agent.apiKeyRoute)] : []),
    "",
    `## 3. ${TEST_STEP_TITLE}`,
    "",
    `> ${CHECK_PROMPT}`,
    "",
    MULTI_ACCOUNT_TIP,
    "",
    `## 4. ${TRY_STEP_TITLE}`,
    "",
    `> ${agent.firstTask}`,
    "",
    "What happens next:",
    "",
    ...WHAT_HAPPENS.map((w) => `- ${w.replace("{name}", agent.name)}`),
    "",
    "## Channels",
    "",
    ...PLATFORMS.map((p) => `- ${p.name}: ${p.note}`),
    "",
    "## Prompts to try",
    "",
    ...agent.prompts.map((p) => `- **${p.title}:** ${p.prompt}`),
    "",
    "## Fixes",
    "",
    ...agent.troubleshooting.map((t) => `- **${t.q}.** ${t.a}`),
    "",
  ].join("\n");
}

/** JSON-LD (HowTo + FAQPage) for an agent's page. */
export function buildAgentJsonLd(agent: AgentConfig): Record<string, unknown>[] {
  const url = `https://viewsmax.com${agent.slug}`;
  const steps = [CONNECT_STEP, ...agent.install, { title: TEST_STEP_TITLE, body: `Ask: "${CHECK_PROMPT}"` }];
  return [
    {
      "@context": "https://schema.org",
      "@type": "HowTo",
      name: `How to connect ViewsMax to ${agent.name}`,
      description: agent.seo.description,
      url,
      step: steps.map((s, i) => ({ "@type": "HowToStep", position: i + 1, name: s.title, text: s.body })),
    },
    {
      "@context": "https://schema.org",
      "@type": "FAQPage",
      mainEntity: agent.faq.map((f) => ({
        "@type": "Question",
        name: f.q,
        acceptedAnswer: { "@type": "Answer", text: f.a },
      })),
    },
    {
      "@context": "https://schema.org",
      "@type": "BreadcrumbList",
      itemListElement: [
        { "@type": "ListItem", position: 1, name: "ViewsMax", item: "https://viewsmax.com/" },
        { "@type": "ListItem", position: 2, name: "AI agents", item: "https://viewsmax.com/ai" },
        { "@type": "ListItem", position: 3, name: agent.name, item: url },
      ],
    },
  ];
}
