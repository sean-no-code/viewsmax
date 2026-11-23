// Config for the per-agent setup pages (/claude, /chatgpt, /cursor, ...). One
// entry per AI agent drives the shared <AgentPage>, the build-time prerender
// (head tags + JSON-LD), and the /<agent>.md brief an AI can read.
//
// Directory listings: an agent with a `listing` (Claude, ChatGPT, Codex) leads
// its page with the directory button and shows the manual setup below it as the
// fallback route. Agents without one lead with the manual setup.
//
// No import.meta.env access at module scope: scripts/prerender-seo.ts and
// vite.config.ts import this file under plain Node.

export const MCP_ENDPOINT = "https://api.viewsmax.com/api/mcp";
/** ViewsMax in the Claude connector directory (claude.ai, Desktop, Cowork and Claude Code). */
export const CLAUDE_DIRECTORY_URL = "https://claude.ai/directory/viewsmax";
/** ViewsMax in the ChatGPT plugin directory, which Codex shares. */
export const CHATGPT_PLUGIN_URL = "https://chatgpt.com/plugins/plugin_asdk_app_6abf68b6ecb08191b55d646bbd9091b9";
export const SKILL_URL = "https://viewsmax.com/skills/viewsmax/SKILL.md";
export const API_KEY_PATH = "Settings → AI Assistant Access";
export const UPDATED = "September 2026";

/**
 * Every MCP tool the server registers (backend/app/Mcp/ViewsMaxServer.php $tools),
 * in the order ai.md lists them.
 * TEMP: create_offer, create_tracking_link and add_outlier_channel are hidden
 * from the MCP for now and left out here too.
 */
export const MCP_TOOLS = [
  "list_connected_accounts", "list_brands", "upload_media", "create_post",
  "list_posts", "get_post", "update_post", "delete_post", "list_offers",
  "get_offer", "update_offer", "delete_offer",
  "get_offer_stats", "get_stats_timeseries",
  "disconnect_account", "get_connect_url",
  "list_outliers", "search_outliers", "get_outlier", "fetch_outlier",
  "get_outlier_breakdown", "generate_outlier_breakdown", "list_saved_outliers",
  "save_outlier", "remove_saved_outlier",
  "get_outlier_channel_ingest",
];

export type AgentKey =
  | "claude"
  | "claude-cowork"
  | "claude-duet"
  | "claude-code"
  | "chatgpt"
  | "codex"
  | "muse"
  | "deepseek"
  | "instinct"
  | "paperclip"
  | "perplexity"
  | "gemini"
  | "chatgpt-dots"
  | "cursor"
  | "openclaw"
  | "hermes";

export interface FaqItem {
  q: string;
  a: string;
}

/** Column an agent sits under in the AI Agents menu (LandingChrome), in display order. */
export const AGENT_GROUPS = ["claude", "openai", "assistants", "builders"] as const;
export type AgentGroup = (typeof AGENT_GROUPS)[number];

export interface InstallStep {
  title: string;
  body: string;
  code?: { label: string; text: string };
  note?: string;
}

/** An app-directory listing. */
export interface Listing {
  /** ViewsMax's page in the directory. */
  url: string;
  label: string; // button text, e.g. "Add to Claude"
  steps: string; // what the user does once they reach the listing
  code?: { label: string; text: string };
  /** Section label on the page; defaults to "Plugin (easiest)". */
  heading?: string;
}

export interface AgentConfig {
  key: AgentKey;
  /** Menu column: the maker for Claude and OpenAI products, otherwise the kind of agent. */
  group: AgentGroup;
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
  url: CLAUDE_DIRECTORY_URL,
  label: "Add to Claude",
  steps:
    "Press Add to Claude. Claude sends you to ViewsMax, where you create an account or sign in, connect your channels, then pick Full access or Read-only and press Approve. Nothing to paste.",
};

const CHATGPT_LISTING: Listing = {
  url: CHATGPT_PLUGIN_URL,
  label: "Add to ChatGPT",
  steps:
    "Press Add to ChatGPT and install ViewsMax from its plugin page. ChatGPT sends you to ViewsMax, where you create an account or sign in, connect your channels, then pick Full access or Read-only and press Approve. Nothing to paste.",
};

// Claude Code picks up connectors from the claude.ai account it's logged in
// with, so the Claude directory listing covers it too.
// https://code.claude.com/docs/en/mcp#use-mcp-servers-from-claudeai
const CLAUDE_CODE_LISTING: Listing = {
  url: CLAUDE_DIRECTORY_URL,
  label: "Add to Claude",
  steps:
    "Connect ViewsMax from its Claude directory listing (create an account or sign in on the ViewsMax screen, then Approve). Claude Code shares connectors with the claude.ai account you log in with, so ViewsMax appears there with no extra setup — /mcp lists it.",
  code: { label: "Inside Claude Code", text: "/mcp" },
};

// Duet starts with the memory, skills and connectors already on the Claude
// account, so the Claude directory listing covers it too.
// https://support.claude.com/en/articles/17631495-get-started-with-claude-duet
const CLAUDE_DUET_LISTING: Listing = {
  url: CLAUDE_DIRECTORY_URL,
  label: "Add to Claude",
  steps:
    "Duet uses the connectors on your Claude account. Press Add to Claude, create an account or sign in on the ViewsMax screen, connect your channels, then pick Full access or Read-only and press Approve. ViewsMax is there in Duet from your next message. Nothing to paste.",
};

// ChatGPT and Codex share one plugin directory; Codex installs from /plugins.
// https://help.openai.com/en/articles/20001256-plugins-in-chatgpt-and-codex
const CODEX_LISTING: Listing = {
  url: CHATGPT_PLUGIN_URL,
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
  "Outlier research reads a shared database, so it works before you've connected a single channel; anything it saves lands in your ViewsMax library.",
  "Posts are stored as drafts or queued for the time you gave. At publish time ViewsMax sends to each channel separately and keeps a result per platform, so one failure never blocks the rest.",
];

export const CHECK_PROMPT =
  "Show me five outlier videos from the ViewsMax featured feed and say in one line why each one over-performed.";

/**
 * What a Muse user pastes into a new Muse conversation. Muse has no connector
 * settings screen: it builds the connection from the message, sends back a
 * sign-in link and keeps the result as a skill.
 */
export const MUSE_SETUP_PROMPT =
  `Add ViewsMax, my social posting and analytics tool, as a custom connector. ` +
  `MCP server (streamable HTTP, OAuth sign-in): ${MCP_ENDPOINT}. ` +
  `Send me the sign-in link and I'll approve it. ` +
  `Save the connection as a skill so posting, scheduling, offers and outlier research always go through ViewsMax. ` +
  `Setup and tool docs: https://viewsmax.com/ai.md. Once connected, answer: ${CHECK_PROMPT}`;

/** The first job and example reply most pages share: outliers work before any channel is connected. */
const FIRST_TASK =
  "Ask me my niche, then find this month's top YouTube outliers in it through ViewsMax, break down why the best one worked, and save it to my library.";
const FIRST_REPLY =
  "Found 12 outliers in personal finance this month. The top one did 41× its channel's average: it opens with a contradiction (\"You don't need a budget\") and then a three-step list. Saved it to your ViewsMax library tagged \"hooks\". Want that hook rewritten for your channel?";

/**
 * What an Instinct user sends to Instinct. It has no connector settings and
 * has not published how it reaches outside tools, so the message asks it to
 * connect over MCP and names the REST API as the fallback.
 */
export const INSTINCT_SETUP_PROMPT =
  `Connect to ViewsMax, my social posting and analytics tool. ` +
  `MCP server (streamable HTTP, OAuth sign-in): ${MCP_ENDPOINT}. ` +
  `Send me the sign-in link and I'll approve it. ` +
  `If you can't connect to MCP servers, use the REST API described at https://viewsmax.com/ai.md and ask me for an API key through your vault, not in this chat. ` +
  `Once connected, answer: ${CHECK_PROMPT}`;

// Dots use the plugins on the ChatGPT account ("Plugin permissions are shared
// across dots, ChatGPT, ChatGPT Work, and Codex"), so the ChatGPT listing covers them.
// https://help.openai.com/en/articles/20001530-getting-started-with-your-dot
const CHATGPT_DOTS_LISTING: Listing = {
  url: CHATGPT_PLUGIN_URL,
  label: "Add to ChatGPT",
  steps:
    "Your dot uses the plugins on your ChatGPT account. Press Add to ChatGPT and install ViewsMax from its plugin page, create an account or sign in on the ViewsMax screen, connect your channels, then pick Full access or Read-only and press Approve. Nothing to paste.",
};

const SHARED_PROMPTS: { title: string; prompt: string }[] = [
  {
    title: "Borrow what's working",
    prompt:
      "Pull YouTube outliers in personal finance from this month and explain the hook behind the top three. Save the best one to my library.",
  },
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
    title: "Tidy the queue",
    prompt: "What's going out this weekend? Push anything on X back by two hours.",
  },
];

const REPO_PROMPTS: { title: string; prompt: string }[] = [
  SHARED_PROMPTS[0],
  {
    title: "Explain a feature",
    prompt:
      "Look at the changes on this branch and write a short X thread explaining the new feature to non-developers. Save it as a ViewsMax draft.",
  },
  ...SHARED_PROMPTS.slice(2),
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

/** sharedFaq with the "will it post without asking" answer replaced: approvals differ per agent. */
const faqWithApproval = (name: string, answer: string): FaqItem[] =>
  sharedFaq(name).map((f) => (/post without asking/.test(f.q) ? { ...f, a: answer } : f));

/** Sent to an agent that has no connector settings and sets itself up from a message. */
const DRAFTS_TIP =
  "tell it to keep everything as drafts and approve them yourself in ViewsMax; a Read-only connection can't publish at all.";

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

// ChatGPT's own route for a server that isn't in the plugin directory.
// https://help.openai.com/en/articles/12584461-connecting-custom-mcp-servers-in-chatgpt
const chatgptCustomServerSteps: InstallStep[] = [
  {
    title: "Open Plugins on the web",
    body: "In ChatGPT on the web, go to Plugins, press the plus button, and choose Add custom MCP server.",
    note: "No Developer mode needed. Custom servers are added on the web, not in the mobile apps.",
  },
  {
    title: "Paste the ViewsMax address",
    body: "Call it ViewsMax and paste the address below under Connection. Choose OAuth for authentication, tick I understand and want to continue, and press Create as a plugin.",
    code: { label: "ViewsMax MCP address", text: MCP_ENDPOINT },
  },
  {
    title: "Approve access",
    body: "Install the plugin and press Connect. When ChatGPT sends you to ViewsMax, log in, pick Full access or Read-only, and press Approve.",
    note: "On Pro, a custom server can read but not post, so use the Add to ChatGPT plugin for posting. Business, Enterprise and Edu workspaces get full access once an admin or owner publishes it.",
  },
];

const claudeCodeApiKey = `claude mcp add --transport http --scope user viewsmax ${MCP_ENDPOINT} --header "Authorization: Bearer vmx_YOUR_KEY"`;

const title = (name: string) => `Post, Find Outliers, and Track Sales from ${name}`;

export const AGENTS: Record<AgentKey, AgentConfig> = {
  claude: {
    key: "claude",
    group: "claude",
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
      "Ask me my niche, then find this month's top YouTube outliers in it through ViewsMax, break down why the best one worked, and save it to my library.",
    firstReply:
      "Found 12 outliers in personal finance this month. The top one did 41× its channel's average: it opens with a contradiction (\"You don't need a budget\") and then a three-step list. Saved it to your ViewsMax library tagged \"hooks\". Want that hook rewritten for your channel?",
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
    group: "claude",
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
      "Ask me my niche, then pull the ten strongest outliers in it from ViewsMax with a one-line note per video on the hook. Save the best three to my library tagged \"swipe\".",
    firstReply:
      "Ten outliers in home fitness, ranked by score. Most hooks pair a bold claim with a time limit (\"20 minutes, no equipment\"). Saved the top three to your ViewsMax library tagged swipe. Want post drafts built on the best hook?",
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

  "claude-duet": {
    key: "claude-duet",
    group: "claude",
    addTime: "≈1 min · one-time (skip if Claude has it)",
    slug: "/claude-duet",
    name: "Claude Duet",
    card: "Same connector as Claude, for the always-on agent",
    transport: "mcp",
    seo: {
      title: `${title("Claude Duet")} | ViewsMax`,
      description:
        "Connect ViewsMax to Claude Duet and have it schedule posts to YouTube, TikTok, Instagram, X, LinkedIn, Threads and Bluesky, watch your offers and report sales while you're away.",
      keywords:
        "claude duet social media, claude duet connector, claude duet schedule posts, claude duet mcp, claude duet plugin, viewsmax claude duet",
    },
    h1: title("Claude Duet"),
    subhead:
      "Message Duet the way you'd message a chief of staff. With ViewsMax connected it can line up the week's posts, check which offers are selling, find outlier videos in your niche and bring you the numbers, even after you've closed the app.",
    setupTime: "Around 5 minutes — nothing to do if Claude already has ViewsMax.",
    listing: CLAUDE_DUET_LISTING,
    install: claudeConnectorSteps("Claude").map((s, i) =>
      i === 0
        ? { ...s, body: `${s.body} Duet uses your Claude account's connectors — if ViewsMax is already there, you're done.` }
        : s,
    ),
    firstTask:
      "Ask me my niche, then find this week's strongest outlier video in it through ViewsMax, tell me in two lines why it worked, save it to my library, and write me a post idea that uses the same hook.",
    firstReply:
      "The strongest outlier in home fitness this week did 38× its channel's average. It opens on a result (\"I trained 10 minutes a day for a month\") and holds the payoff to the end. Saved it to your ViewsMax library. Post idea: \"I posted every day for 30 days. Here's what moved the numbers.\" Want it drafted for one of your channels?",
    prompts: SHARED_PROMPTS,
    troubleshooting: [
      {
        q: "I don't have Claude Duet",
        a: "Duet is a research preview for Claude Pro, Max and Ultra plans in the US, and access comes by invitation from its waitlist. Until you're in, the same ViewsMax connector works in Claude and Cowork.",
      },
      ...SHARED_TROUBLESHOOTING,
    ],
    faq: [
      ...faqWithApproval("Duet", "Duet is designed to wait for your approval before it sends a message, makes a payment, signs a document or deletes anything. For posts, tell it to keep everything as drafts and approve them yourself in ViewsMax; a Read-only connection can't publish at all."),
      {
        q: "Do I need to set ViewsMax up separately for Duet?",
        a: "No. Duet uses the connectors on your Claude account, so one ViewsMax connection covers Claude, Cowork and Duet.",
      },
      {
        q: "Can Duet work on my posts while I'm away?",
        a: "Yes. Duet runs on its own computer in the cloud, so it can keep working after you close the app, for example checking offer results each morning or lining up drafts. Scheduled posts are published by ViewsMax itself at the time you set, whether or not Duet is running.",
      },
      {
        q: "Who can use Claude Duet?",
        a: "Duet is a research preview for Claude Pro, Max and Ultra plans in the US, with access by invitation from a waitlist. It isn't available on Team or Enterprise plans yet.",
      },
    ],
  },

  "claude-code": {
    key: "claude-code",
    group: "claude",
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
      "Work out what this project does from the repo, then use ViewsMax to find outlier videos in that space and explain the hook behind the top three. Save the best one to my library.",
    firstReply:
      "The repo is a budgeting CLI, so I searched ViewsMax for personal-finance outliers. The top three all open with a number (\"I tracked every dollar for 90 days\"). Saved the strongest, 38× its channel average, to your library. Want an X thread built on that hook?",
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
    group: "openai",
    addTime: "≈2 min · one-time",
    slug: "/chatgpt",
    name: "ChatGPT",
    card: "One-click plugin from the ChatGPT directory",
    transport: "mcp",
    seo: {
      title: `${title("ChatGPT")} | ViewsMax`,
      description:
        "Connect ViewsMax to ChatGPT and schedule posts to YouTube, TikTok, Instagram, X, LinkedIn, Threads and Bluesky by asking, then see which ones drive sales.",
      keywords:
        "post to social media from chatgpt, chatgpt social media, chatgpt plugin, chatgpt connector, chatgpt mcp, schedule posts with chatgpt, viewsmax chatgpt",
    },
    h1: title("ChatGPT"),
    subhead:
      "Turn a ChatGPT conversation into a publishing desk. Ask for the post, the channels and the time; ViewsMax sends it out, tracks your offers, surfaces outlier videos worth copying, and shows you what's selling.",
    setupTime: "Around 2 minutes in your browser.",
    listing: CHATGPT_LISTING,
    install: chatgptCustomServerSteps,
    firstTask:
      "Ask me my niche, then find this month's top YouTube outliers in it through ViewsMax, break down why the best one worked, and save it to my library.",
    firstReply:
      "Found 12 outliers in personal finance this month. The top one did 41× its channel's average: it opens with a contradiction (\"You don't need a budget\") and then a three-step list. Saved it to your ViewsMax library tagged \"hooks\". Want that hook rewritten for your channel?",
    prompts: SHARED_PROMPTS,
    troubleshooting: [
      {
        q: "My custom server can read but not post",
        a: "On ChatGPT Pro a custom MCP server is read-only. Install ViewsMax with the Add to ChatGPT button instead: the directory plugin can post.",
      },
      ...SHARED_TROUBLESHOOTING,
    ],
    faq: [
      ...sharedFaq("ChatGPT"),
      {
        q: "Does it work in Codex too?",
        a: "Yes. ChatGPT and Codex share one plugin directory, and Codex installs ViewsMax from /plugins. A custom MCP server added by hand doesn't carry over, so follow the Codex guide for that route.",
      },
    ],
  },

  codex: {
    key: "codex",
    group: "openai",
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
      "Work out what this project does from the repo, then use ViewsMax to find outlier videos in that space and explain the hook behind the top three. Save the best one to my library.",
    firstReply:
      "The repo is a budgeting CLI, so I searched ViewsMax for personal-finance outliers. The top three all open with a number (\"I tracked every dollar for 90 days\"). Saved the strongest, 38× its channel average, to your library. Want an X thread built on that hook?",
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

  muse: {
    key: "muse",
    group: "assistants",
    addTime: "≈2 min · one-time",
    slug: "/muse",
    name: "Meta Muse",
    card: "Paste one setup prompt, sign in over OAuth",
    transport: "mcp",
    seo: {
      title: `${title("Meta Muse")} | ViewsMax`,
      description:
        "Connect ViewsMax to Meta's Muse with one setup prompt and have it schedule posts to YouTube, TikTok, Instagram, X, LinkedIn, Threads and Bluesky, track offers and find outlier videos.",
      keywords:
        "meta muse social media, muse ai connector, muse mcp, muse schedule posts, meta muse custom connector, viewsmax muse",
    },
    h1: title("Meta Muse"),
    subhead:
      "Muse works across your apps from a chat on the web, on your phone or in WhatsApp. Add ViewsMax and it can line up posts across your channels, check what your offers earned and pull the outlier videos worth copying.",
    setupTime: "Around 5 minutes.",
    manualHeading: "Setup prompt",
    install: [
      {
        title: "Paste the setup prompt",
        body: "Open Muse, start a new conversation and paste this. Muse has no connector settings screen; it sets ViewsMax up from the message.",
        code: { label: "Setup prompt for Muse", text: MUSE_SETUP_PROMPT },
      },
      {
        title: "Approve access",
        body: "Muse replies with a ViewsMax sign-in link. Open it, log in to ViewsMax, pick Full access or Read-only, and press Approve.",
        note: "ViewsMax isn't in Muse's connector directory yet, so it is added as a custom connector, which Meta doesn't review. Keep Muse's approvals switched on for anything that posts.",
      },
    ],
    apiKeyRoute: {
      title: "No sign-in link?",
      body: `Connect with an API key instead. Create one in ViewsMax under ${API_KEY_PATH}, send Muse the message below, and type the key into Muse's secure credential prompt when it asks, not into the chat.`,
      code: {
        label: "Message for Muse",
        text: `Connect to the ViewsMax MCP server at ${MCP_ENDPOINT} with a bearer API key. Ask me for the key through your secure credential prompt, then save the connection as a skill.`,
      },
    },
    firstTask:
      "Ask me my niche, then find this month's top YouTube outliers in it through ViewsMax, break down why the best one worked, and save it to my library.",
    firstReply:
      "Found 12 outliers in personal finance this month. The top one did 41× its channel's average: it opens with a contradiction (\"You don't need a budget\") and then a three-step list. Saved it to your ViewsMax library tagged \"hooks\". Want that hook rewritten for your channel?",
    prompts: SHARED_PROMPTS,
    troubleshooting: [
      {
        q: "Muse doesn't offer to add the connector",
        a: "There is no settings screen to look for: Muse builds the connection from your message. Start a new conversation and paste the whole setup prompt, address included.",
      },
      {
        q: "The sign-in link never arrives",
        a: "Use the API key route above. Muse asks for the key in its secure credential prompt and connects with that instead.",
      },
      ...SHARED_TROUBLESHOOTING,
    ],
    faq: [
      ...faqWithApproval("Muse", "Keep Muse's approvals switched on for anything that posts. For an extra safety net, tell it to keep everything as drafts and approve them yourself in ViewsMax; a Read-only connection can't publish at all."),
      {
        q: "Is ViewsMax in Muse's connector directory?",
        a: "Not yet. Until it is, the setup prompt adds ViewsMax as a custom connector. It gives Muse the same tools; you sign in once and Muse keeps the connection as a skill.",
      },
      {
        q: "Do I have to paste the prompt every time?",
        a: "No. The prompt asks Muse to save the connection as a skill, so ViewsMax is there in later conversations.",
      },
    ],
  },

  "chatgpt-dots": {
    key: "chatgpt-dots",
    group: "openai",
    addTime: "≈1 min · one-time (skip if ChatGPT has it)",
    slug: "/chatgpt-dots",
    name: "ChatGPT Dots",
    card: "Same plugin as ChatGPT, for your always-on dot",
    transport: "mcp",
    seo: {
      title: `${title("ChatGPT Dots")} | ViewsMax`,
      description:
        "Add ViewsMax to your ChatGPT dot and have it schedule posts to YouTube, TikTok, Instagram, X, LinkedIn, Threads and Bluesky, watch your offers and report sales while you're away.",
      keywords:
        "chatgpt dots social media, openai dots plugin, dots schedule posts, chatgpt dot connector, openai dots mcp, viewsmax dots",
    },
    h1: title("ChatGPT Dots"),
    subhead:
      "Your dot is an always-on agent in ChatGPT with its own computer. With ViewsMax installed it can line up the week's posts, check which offers are selling and pull outlier videos in your niche, then come back when it needs a yes.",
    setupTime: "Around 2 minutes — nothing to do if ChatGPT already has ViewsMax.",
    listing: CHATGPT_DOTS_LISTING,
    install: chatgptCustomServerSteps.map((s, i) =>
      i === 0
        ? { ...s, body: `${s.body} OpenAI only documents directory plugins for dots, so prefer the plugin above when you can.` }
        : s,
    ),
    firstTask: FIRST_TASK,
    firstReply: FIRST_REPLY,
    prompts: SHARED_PROMPTS,
    troubleshooting: [
      {
        q: "My dot can't see ViewsMax",
        a: "Open your dot's profile, go to Customize → Plugins and check ViewsMax is connected there. Plugins are shared with ChatGPT, so installing it in either place covers both.",
      },
      ...SHARED_TROUBLESHOOTING.slice(1),
    ],
    faq: [
      ...faqWithApproval(
        "Dots",
        "Not by default. Your dot asks you to confirm actions that change something, and you can add your own rules under Customize → Custom rules. For an extra safety net, " + DRAFTS_TIP,
      ),
      {
        q: "Do I need to set ViewsMax up separately for my dot?",
        a: "No. Plugin permissions are shared across dots, ChatGPT and Codex, so one install covers them.",
      },
      {
        q: "Who can use dots?",
        a: "Dots are rolling out to ChatGPT Pro and some Plus accounts for adults outside the EEA, the UK and Switzerland, and to Business, Enterprise and Edu workspaces. They work in the ChatGPT mobile and desktop apps and on desktop web.",
      },
    ],
  },

  gemini: {
    key: "gemini",
    group: "assistants",
    addTime: "≈2 min · one-time",
    slug: "/gemini",
    name: "Gemini",
    card: "Custom app in Gemini, or one command in the CLI",
    transport: "mcp",
    seo: {
      title: `${title("Gemini")} | ViewsMax`,
      description:
        "Connect ViewsMax to Google Gemini as a custom app, or to Gemini CLI with one command, and schedule posts to YouTube, TikTok, Instagram, X, LinkedIn, Threads and Bluesky by asking.",
      keywords:
        "gemini social media, gemini custom app mcp, gemini connected apps, gemini cli mcp, antigravity cli mcp, schedule posts with gemini, viewsmax gemini",
    },
    h1: title("Gemini"),
    subhead:
      "Ask Gemini for the post, the channels and the time. With ViewsMax added as a custom app it publishes across your channels, tracks your offers, finds outlier videos worth copying and tells you what's selling.",
    setupTime: "Around 5 minutes in your browser.",
    install: [
      {
        title: "Open Connected Apps",
        body: "On gemini.google.com, open Settings → Connected Apps. Under Custom apps, click Add a custom app.",
        note: "Custom apps are added on the web with a personal Google Account, in the US and in English for now. Once added, ViewsMax also works in the Gemini mobile app.",
      },
      {
        title: "Paste the ViewsMax address",
        body: "Enter the address below and click Next.",
        code: { label: "ViewsMax MCP address", text: MCP_ENDPOINT },
      },
      {
        title: "Approve access",
        body: "Follow the prompts. When Gemini sends you to ViewsMax, log in, pick Full access or Read-only, and press Approve.",
      },
    ],
    apiKeyRoute: {
      title: "Using Gemini CLI or Antigravity?",
      body: "Gemini CLI: run the first command, then type /mcp auth viewsmax inside the CLI to sign in. Antigravity CLI: add the JSON below to ~/.gemini/config/mcp_config.json and it signs in on first use.",
      code: {
        label: "Terminal, or mcp_config.json",
        text: `gemini mcp add --transport http --scope user viewsmax ${MCP_ENDPOINT}\n\n{ "mcpServers": { "viewsmax": { "serverUrl": "${MCP_ENDPOINT}" } } }`,
      },
    },
    firstTask: FIRST_TASK,
    firstReply: FIRST_REPLY,
    prompts: SHARED_PROMPTS,
    troubleshooting: [
      {
        q: "There's no Custom apps section",
        a: "Custom apps are limited to personal Google Accounts in the US, in English, with Gemini activity history on, and are added on the web. Work and school accounts don't have it; use the CLI route instead.",
      },
      ...SHARED_TROUBLESHOOTING,
    ],
    faq: [
      ...faqWithApproval(
        "Gemini",
        "Gemini asks you to confirm an action that changes something before it runs. For an extra safety net, " + DRAFTS_TIP,
      ),
      {
        q: "Does it work in Gemini CLI and Antigravity?",
        a: "Yes. Both read a config that points at the ViewsMax MCP address and sign in over OAuth. The steps are under the app setup above.",
      },
      {
        q: "Who can add custom apps in Gemini?",
        a: "For now, adults in the US using Gemini in English with a personal Google Account. Google hasn't said which plans include it. Work and school accounts are managed by an admin instead.",
      },
    ],
  },

  perplexity: {
    key: "perplexity",
    group: "assistants",
    addTime: "≈2 min · one-time",
    slug: "/perplexity",
    name: "Perplexity Computer",
    card: "Custom connector on the Plugins page",
    transport: "mcp",
    seo: {
      title: `${title("Perplexity Computer")} | ViewsMax`,
      description:
        "Add ViewsMax to Perplexity Computer as a custom connector and have it schedule posts to YouTube, TikTok, Instagram, X, LinkedIn, Threads and Bluesky, track offers and research outlier videos.",
      keywords:
        "perplexity computer social media, perplexity computer mcp, perplexity custom connector, perplexity plugins mcp, perplexity schedule posts, viewsmax perplexity",
    },
    h1: title("Perplexity Computer"),
    subhead:
      "Hand Computer a content brief and let it work. With ViewsMax connected it can study the outlier videos in your niche, line up posts across your channels, create tracked links and come back with the numbers.",
    setupTime: "Around 5 minutes in your browser.",
    install: [
      {
        title: "Open Plugins",
        body: "In Perplexity, open Plugins in the sidebar, choose Add custom → Add MCP connector, and select Remote.",
        note: "Custom connectors need a Pro, Max or Enterprise plan. In an organisation, an admin has to allow members to add them.",
      },
      {
        title: "Paste the ViewsMax address",
        body: "Name it ViewsMax and paste the address below as the MCP server URL. Under Advanced, keep Authentication on OAuth and Transport on Streamable HTTP, tick the acknowledgement, and select Add.",
        code: { label: "ViewsMax MCP address", text: MCP_ENDPOINT },
      },
      {
        title: "Approve access",
        body: "Select the ViewsMax card to sign in. Log in to ViewsMax, pick Full access or Read-only, and press Approve.",
        note: "In a task, type / and pick ViewsMax under Plugins, or @-mention it.",
      },
    ],
    apiKeyRoute: {
      title: "Sign-in won't complete?",
      body: `Set Authentication to API Key instead and paste a key created in ViewsMax under ${API_KEY_PATH}.`,
    },
    firstTask: FIRST_TASK,
    firstReply: FIRST_REPLY,
    prompts: SHARED_PROMPTS,
    troubleshooting: [
      {
        q: "Computer doesn't use ViewsMax",
        a: "In the task, type / and pick ViewsMax under Plugins, or @-mention it. Check that the connector card shows as connected.",
      },
      {
        q: "New ViewsMax tools don't show up",
        a: "Open the ViewsMax connector, choose Disconnect, then Connect again. Perplexity re-reads a connector's tools when it reconnects.",
      },
      ...SHARED_TROUBLESHOOTING.slice(1),
    ],
    faq: [
      ...faqWithApproval(
        "Perplexity Computer",
        "Assume it can: Perplexity doesn't describe an approval step for connector actions. To stay in control, " + DRAFTS_TIP,
      ),
      {
        q: "Which Perplexity plans can add ViewsMax?",
        a: "Pro, Max, Enterprise Pro and Enterprise Max. Custom connectors aren't available on the free plan.",
      },
      {
        q: "Is ViewsMax in Perplexity's plugin directory?",
        a: "Not yet. The Discover tab lists plugins published by Perplexity, so ViewsMax is added as a custom connector. It gives Computer the same tools.",
      },
    ],
  },

  paperclip: {
    key: "paperclip",
    group: "builders",
    addTime: "≈2 min · one-time",
    slug: "/paperclip",
    name: "Paperclip",
    card: "Add ViewsMax under Connectors, for the agents you pick",
    transport: "mcp",
    seo: {
      title: `${title("Paperclip")} | ViewsMax`,
      description:
        "Connect ViewsMax to Paperclip and let the AI agents you manage schedule posts to YouTube, TikTok, Instagram, X, LinkedIn, Threads and Bluesky, track offers and research outlier videos.",
      keywords:
        "paperclip ai agents social media, paperclip connectors, paperclip mcp server, paperclip custom mcp, paperclip schedule posts, viewsmax paperclip",
    },
    h1: title("Paperclip"),
    subhead:
      "Paperclip manages the AI agents that do your work. Add ViewsMax as a connector and the agents you pick can line up posts across your channels, report what your offers earned and pull outlier videos worth copying, with each action allowed, asked first or switched off.",
    setupTime: "Around 5 minutes.",
    install: [
      {
        title: "Open Connectors",
        body: "In Paperclip, choose Connectors in the left sidebar, then Connect your own MCP server.",
        note: "You need a company you can administer.",
      },
      {
        title: "Paste the ViewsMax address",
        body: "Paste the address below. Paperclip checks the server and starts the sign-in.",
        code: { label: "ViewsMax MCP address", text: MCP_ENDPOINT },
      },
      {
        title: "Approve access",
        body: "In the browser window that opens, log in to ViewsMax, pick Full access or Read-only, and press Approve.",
      },
      {
        title: "Choose who can use it",
        body: "Use Change to share ViewsMax with just you or with the agents you pick. On the Permissions tab, set the publishing actions to Ask first if you want to approve each post.",
        note: "Actions start as Allowed, including the ones that publish. ViewsMax shows as an Unverified server because it isn't in Paperclip's catalog yet.",
      },
    ],
    firstTask: FIRST_TASK,
    firstReply: FIRST_REPLY,
    prompts: SHARED_PROMPTS,
    troubleshooting: [
      {
        q: "My agent can't see ViewsMax",
        a: "Check the connector is shared with that agent, then press Refresh actions. A server added inside an agent's own runtime, for example with claude mcp add, isn't loaded by Paperclip's default runner, so add ViewsMax under Connectors.",
      },
      {
        q: "ViewsMax asks me to sign in again",
        a: "Press Reconnect on the connector and approve ViewsMax again.",
      },
      ...SHARED_TROUBLESHOOTING.slice(1),
    ],
    faq: [
      ...faqWithApproval(
        "Paperclip",
        "Only if you let it. Each ViewsMax action in Paperclip is Allowed, Ask first or Off, and actions start as Allowed. Set the publishing actions to Ask first to approve every post, or connect ViewsMax as Read-only so it can't publish at all.",
      ),
      {
        q: "Which agents does it work with?",
        a: "Any agent you run through Paperclip, such as Claude Code, Codex, Cursor, Gemini CLI, Hermes or OpenClaw. The connector belongs to Paperclip, so you add ViewsMax once and choose which agents get it.",
      },
      {
        q: "Is ViewsMax in Paperclip's connector catalog?",
        a: "Not yet, so it is added with Connect your own MCP server and labelled Unverified server. It gives your agents the same tools.",
      },
    ],
  },

  instinct: {
    key: "instinct",
    group: "assistants",
    addTime: "≈2 min · one-time",
    slug: "/instinct",
    name: "Instinct",
    card: "Text it one setup prompt",
    transport: "mcp",
    seo: {
      title: `${title("Instinct")} | ViewsMax`,
      description:
        "Ask Instinct to use ViewsMax with one setup prompt and have it schedule posts to YouTube, TikTok, Instagram, X, LinkedIn, Threads and Bluesky, track offers and find outlier videos.",
      keywords:
        "instinct ai social media, instinct assistant connector, instinct ai mcp, instinct schedule posts, viewsmax instinct",
    },
    h1: title("Instinct"),
    subhead:
      "Instinct is an assistant you text or call that gets things done through your accounts. Ask it to use ViewsMax and it can line up posts across your channels, check what your offers earned and pull the outlier videos worth copying.",
    setupTime: "Around 5 minutes.",
    manualHeading: "Setup prompt",
    install: [
      {
        title: "Send the setup prompt",
        body: "Send this to Instinct the way you normally message it.",
        code: { label: "Setup prompt for Instinct", text: INSTINCT_SETUP_PROMPT },
        note: "Instinct has no connector settings and hasn't published how it connects to outside tools, so this relies on Instinct accepting the request. It may not work on every account yet.",
      },
      {
        title: "Approve access",
        body: `If Instinct sends a ViewsMax sign-in link, open it, log in, pick Full access or Read-only, and press Approve. If it asks for an API key instead, create one under ${API_KEY_PATH} and enter it in Instinct's vault, not in the chat.`,
      },
    ],
    firstTask: FIRST_TASK,
    firstReply: FIRST_REPLY,
    prompts: SHARED_PROMPTS,
    troubleshooting: [
      {
        q: "Instinct says it can't connect",
        a: "Instinct doesn't officially support outside connectors yet, so it may decline. ViewsMax works today in Claude, ChatGPT, Gemini and the other agents listed on this site.",
      },
      ...SHARED_TROUBLESHOOTING.slice(1),
    ],
    faq: [
      ...faqWithApproval("Instinct", "Ask it not to. To stay in control, " + DRAFTS_TIP),
      {
        q: "Does Instinct officially support ViewsMax?",
        a: "Not yet. Instinct has no connector directory or settings screen, so the setup prompt asks it to connect on its own. This page will change when Instinct offers a direct route.",
      },
      {
        q: "Do I have to send the prompt every time?",
        a: "You shouldn't need to: once Instinct has the connection it can reuse it. If it loses track, send the prompt again.",
      },
    ],
  },

  deepseek: {
    key: "deepseek",
    group: "assistants",
    addTime: "≈3 min · one-time",
    slug: "/deepseek",
    name: "DeepSeek",
    card: "DeepSeek Harness with a ViewsMax API key",
    transport: "mcp",
    seo: {
      title: `${title("DeepSeek")} | ViewsMax`,
      description:
        "Connect ViewsMax to DeepSeek Harness with an API key and let DeepSeek schedule posts to YouTube, TikTok, Instagram, X, LinkedIn, Threads and Bluesky, track offers and research outlier videos.",
      keywords:
        "deepseek social media, deepseek mcp server, deepseek harness mcp, dsh mcp, deepseek schedule posts, viewsmax deepseek",
    },
    h1: title("DeepSeek"),
    subhead:
      "Run DeepSeek as an agent and give it ViewsMax. Through DeepSeek Harness it can draft and schedule posts across your channels, report what your offers earned and dig up outlier videos for your next idea.",
    setupTime: "Around 5 minutes.",
    install: [
      {
        title: "Generate an API key",
        body: `In ViewsMax, open ${API_KEY_PATH} and create a full-access key. It begins with vmx_ and is only displayed once.`,
      },
      {
        title: "Add ViewsMax to DeepSeek Harness",
        body: "Add this to cordis.patch.yml in your DeepSeek Harness home folder, put the key in the MCP_TOKEN environment variable, then restart dsh web.",
        code: {
          label: "cordis.patch.yml",
          text:
            "- insert:\n    - id: mcp-viewsmax\n      name: '@deepseek-ai/dsh-mcp-client'\n      config:\n        serverName: viewsmax\n        transport: streamable-http\n        url: " +
            MCP_ENDPOINT +
            "\n        headers:\n          Authorization: !!js '`Bearer ${process.env.MCP_TOKEN}`'",
        },
        note: "DeepSeek Harness is a preview and its config format can change. The DeepSeek chat app (chat.deepseek.com and the mobile apps) has no connectors, so ViewsMax can't be added there.",
      },
    ],
    apiKeyRoute: {
      title: "Using a DeepSeek model in another agent?",
      body: "DeepSeek's API also runs inside Claude Code, Codex, OpenClaw and Hermes. Set ViewsMax up in that agent with its own guide; the model behind it doesn't change the steps.",
    },
    firstTask: FIRST_TASK,
    firstReply: FIRST_REPLY,
    prompts: SHARED_PROMPTS,
    troubleshooting: [
      {
        q: "I only use the DeepSeek chat app",
        a: "The chat app can't connect to outside tools. Use DeepSeek Harness, or run a DeepSeek model inside an agent that supports MCP, such as Claude Code or Codex.",
      },
      ...SHARED_TROUBLESHOOTING.slice(1),
    ],
    faq: [
      ...faqWithApproval("DeepSeek", "Assume it can. To stay in control, " + DRAFTS_TIP),
      {
        q: "Can I add ViewsMax to chat.deepseek.com?",
        a: "Not today. DeepSeek's chat app has no connectors or plugins. ViewsMax connects through DeepSeek Harness, DeepSeek's open-source agent app, or through any MCP-capable agent running a DeepSeek model.",
      },
      {
        q: "Does it use OAuth or an API key?",
        a: "An API key. DeepSeek Harness only offers browser sign-in for servers installed through its plugins, so a server you add yourself signs in with a ViewsMax key in the Authorization header.",
      },
    ],
  },

  cursor: {
    key: "cursor",
    group: "builders",
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
      "Read the README, then use ViewsMax to find outlier videos in this product's niche and break down the top one's hook. Save it to my library.",
    firstReply:
      "README says it's a screenshot tool for developers, so I pulled dev-tooling outliers from ViewsMax. The top one, 27× its channel average, shows the finished result before explaining anything. Saved to your library. Want a demo-video script in that shape?",
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
    group: "builders",
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
      "Ask me my niche, then find this month's top YouTube outliers in it through ViewsMax, break down why the best one worked, and save it to my library.",
    firstReply:
      "Found 12 outliers in personal finance this month. The top one did 41× its channel's average: it opens with a contradiction (\"You don't need a budget\") and then a three-step list. Saved it to your ViewsMax library tagged \"hooks\". Want that hook rewritten for your channel?",
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
    group: "builders",
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
      "Ask me my niche, then find this month's top YouTube outliers in it through ViewsMax, break down why the best one worked, and save it to my library.",
    firstReply:
      "Found 12 outliers in personal finance this month. The top one did 41× its channel's average: it opens with a contradiction (\"You don't need a budget\") and then a three-step list. Saved it to your ViewsMax library tagged \"hooks\". Want that hook rewritten for your channel?",
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
  AGENTS["claude-duet"],
  AGENTS.chatgpt,
  AGENTS["chatgpt-dots"],
  AGENTS.codex,
  AGENTS.gemini,
  AGENTS.perplexity,
  AGENTS.muse,
  AGENTS.deepseek,
  AGENTS.instinct,
  AGENTS.cursor,
  AGENTS.paperclip,
  AGENTS.openclaw,
  AGENTS.hermes,
];

/** Label for the directory ("plugin") route on the page. */
export function listingHeading(agent: AgentConfig): string {
  return agent.listing?.heading ?? "Plugin (easiest)";
}

/** Label for the manual steps. MCP agents add a custom connector; skill agents set up by hand. */
export function manualHeading(agent: AgentConfig): string {
  return agent.manualHeading ?? (agent.transport === "mcp" ? "Custom connector" : "Set up by hand");
}

/** The agent's directory listing URL, or undefined for agents with no directory. */
export function agentListingUrl(agent: AgentConfig): string | undefined {
  return agent.listing?.url;
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
export function buildAgentMarkdown(agent: AgentConfig): string {
  const listing = agentListingUrl(agent);
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
