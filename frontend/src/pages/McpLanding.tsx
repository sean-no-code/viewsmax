// The /mcp landing page: what the ViewsMax MCP server is, what it exposes, and
// how each AI client connects. Content lives in src/lib/mcp-page.ts so this page,
// /mcp.md and the JSON-LD are built from one source. Companion to /ai (the install
// hub, which stays the breadcrumb parent) and the per-agent guides (/claude, ...).
import type { ReactNode } from "react";
import { Link } from "react-router-dom";
import {
  ArrowRight, CalendarClock, Check, Copy, FileText, KeyRound, Link2, ListChecks, LockKeyhole, Radar, Share2, type LucideIcon,
} from "lucide-react";
import { LandingNav, LandingFooter } from "@/pages/landing/LandingChrome";
import { BrandIcon } from "@/components/post/brand-icons";
import { Accordion, CARD, CodeBlock, H2, OUTLINE_BTN, PRIMARY_BTN, PromptBox, useCopy } from "@/components/agents/primitives";
import { useSeo } from "@/hooks/useSeo";
import { AGENT_LIST, API_KEY_PATH, MCP_ENDPOINT, MCP_TOOLS, PLATFORMS } from "@/lib/agent-pages";
import {
  AUTH_MODES,
  CAPABILITIES,
  CLIENT_MATRIX,
  DISCOVERY_URL,
  DOCS_URL,
  EXAMPLE_PROMPTS,
  MCP_FAQ,
  MCP_MD_PATH,
  MCP_PAGE_SEO,
  MCP_PATH,
  NOT_SUPPORTED,
  OAUTH_AS_URL,
  OAUTH_PR_URL,
  OAUTH_REGISTER_URL,
  RATE_LIMITS,
  TOOL_GROUPS,
  buildMcpJsonLd,
  buildMcpMarkdown,
  clientSnippet,
} from "@/lib/mcp-page";

const H3 = "font-display text-[19px] font-bold leading-tight tracking-[-0.01em]";
const PROSE = "max-w-[720px] text-[16px] leading-[1.55] text-ink-on-paper-2 [text-wrap:pretty]";
const LINK = "font-semibold text-ink-on-paper-1 underline underline-offset-2";
const TH = "px-4 py-2.5 text-left text-xs font-bold uppercase tracking-wide text-ink-on-paper-3";
const TD = "px-4 py-3 align-top text-[15px] leading-[1.45]";

const CALLOUTS: { icon: LucideIcon; title: string; body: string }[] = [
  { icon: Share2, title: `One connection, ${PLATFORMS.length} platforms`, body: "Link each channel once in ViewsMax. Your agent gets them all, including ones you add later." },
  { icon: LockKeyhole, title: "Read-only or full access", body: "You choose on the approval screen. Read-only credentials can't see write tools at all." },
  { icon: ListChecks, title: "Every call logged", body: `Each tool call lands in your audit log under ${API_KEY_PATH}, so you can see what the agent did.` },
  { icon: CalendarClock, title: "Draft, schedule or publish", body: "Save a draft to review, queue it for a time, or send it now. Each platform reports its own result." },
  { icon: Link2, title: "Offers and tracked links", body: "Set up a promotion, mint a link per placement, and read clicks, conversions and revenue." },
  { icon: Radar, title: "Outlier research", body: "Find videos beating their channel's average, get an AI breakdown of why, and save the best." },
];

const Code = ({ children }: { children: ReactNode }) => (
  <code className="rounded bg-paper-2 px-1.5 py-0.5 font-mono text-[12.5px] text-ink-on-paper-1">{children}</code>
);

function Table({ head, children }: { head: string[]; children: ReactNode }) {
  return (
    <div className={`mt-6 overflow-x-auto ${CARD}`}>
      <table className="w-full min-w-[560px] border-collapse">
        <thead>
          <tr className="border-b border-line-1">
            {head.map((h) => (
              <th key={h} scope="col" className={TH}>{h}</th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-line-1">{children}</tbody>
      </table>
    </div>
  );
}

export default function McpLanding() {
  const { copied, copy } = useCopy();

  useSeo({
    ...MCP_PAGE_SEO,
    canonicalPath: MCP_PATH,
    ogImage: "https://viewsmax.com/og-image.png",
    jsonLd: buildMcpJsonLd(),
  });

  return (
    <div data-prerender-ready className="flex min-h-screen flex-col bg-paper-1 font-body text-ink-on-paper-1">
      <LandingNav />

      <main className="mx-auto w-full max-w-[980px] flex-1 px-4 pb-20 pt-12 sm:px-6 sm:pt-16">
        {/* Hero */}
        <nav aria-label="Breadcrumb" className="mb-5 text-[13px] font-semibold text-ink-on-paper-3">
          <Link to="/ai" className="hover:text-ink-on-paper-1">AI agents</Link>
          <span className="mx-1.5" aria-hidden>/</span>
          <span className="text-ink-on-paper-2" aria-current="page">MCP server</span>
        </nav>
        <div className="flex flex-wrap items-center gap-3">
          <span className="inline-flex items-center gap-1.5 rounded-full border border-line-1 bg-paper-0 px-3.5 py-[7px] text-xs font-bold text-ink-on-paper-2">
            Streamable HTTP · {MCP_TOOLS.length} tools · OAuth or API key
          </span>
          <span className="flex gap-1.5" aria-label={`Posts to ${PLATFORMS.map((p) => p.name).join(", ")}`}>
            {PLATFORMS.map((p) => (
              <span key={p.name} title={p.name} className="grid h-7 w-7 place-items-center rounded-lg text-white shadow-sm" style={{ background: p.color }}>
                <BrandIcon platform={p.icon} size={14} />
              </span>
            ))}
          </span>
        </div>
        <h1 className="mb-4 mt-[22px] max-w-[860px] font-display text-[clamp(34px,5vw,56px)] font-extrabold leading-[1.02] tracking-[-0.03em] [text-wrap:balance]">
          A social media MCP server for Claude, ChatGPT, Cursor and any AI agent
        </h1>
        <p className="max-w-[720px] text-[19px] leading-[1.5] text-ink-on-paper-2 [text-wrap:pretty]">
          ViewsMax runs a remote MCP server. Point your AI client at one address and it can draft, schedule and publish to{" "}
          {PLATFORMS.length} platforms, track offers and revenue, and research outlier videos — with your permission, and with every call logged.
        </p>

        <div className="mt-8 flex flex-wrap gap-3">
          <button type="button" onClick={() => copy(MCP_ENDPOINT, "hero", "MCP address copied")} className={PRIMARY_BTN}>
            {copied === "hero" ? <Check className="h-4 w-4" aria-hidden /> : <Copy className="h-4 w-4" aria-hidden />} Copy the MCP address
          </button>
          <Link to="/auth" className={OUTLINE_BTN}>Start for $0</Link>
        </div>
        <p className="mt-4 font-mono text-[13px] text-ink-on-paper-3">{MCP_ENDPOINT}</p>

        <aside className="mt-8 flex flex-wrap items-center justify-between gap-4 rounded-[18px] border border-dashed border-line-2 px-5 py-4">
          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-2 font-bold"><FileText className="h-4 w-4 text-vm-red" aria-hidden /> Reading this with an AI?</div>
            <p className="mt-1 text-sm leading-[1.45] text-ink-on-paper-2">This page as plain text: the address, every tool, auth modes, limits and setup per client.</p>
          </div>
          <div className="flex flex-wrap items-center gap-3 text-sm">
            <button
              type="button"
              onClick={() => copy(buildMcpMarkdown(), "md", "Page copied as markdown")}
              className="inline-flex items-center gap-1.5 rounded-lg border border-line-2 bg-paper-0 px-3 py-2 font-bold hover:border-ink-on-paper-1"
            >
              {copied === "md" ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />} Copy as markdown
            </button>
            <a href={MCP_MD_PATH} className="font-semibold text-ink-on-paper-2 underline underline-offset-2">View plain text</a>
          </div>
        </aside>

        {/* What is it */}
        <section className="pt-16">
          <h2 className={H2}>What is a social media MCP server?</h2>
          <p className={`mt-4 ${PROSE}`}>
            MCP (Model Context Protocol) is the open standard AI apps use to call outside tools. A social media MCP server exposes posting,
            scheduling and analytics as tools, so an agent like Claude or ChatGPT can run them for you instead of you copying things between
            tabs. With ViewsMax the platform logins stay inside ViewsMax — the agent only ever holds a ViewsMax permission that you can scope
            to read-only and revoke at any time.
          </p>
          <div className="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {CALLOUTS.map(({ icon: Icon, title, body }) => (
              <div key={title} className={`${CARD} p-5`}>
                <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-[color:var(--vm-red-tint-l)] text-vm-red">
                  <Icon className="h-5 w-5" aria-hidden />
                </span>
                <h3 className={`mt-3 ${H3}`}>{title}</h3>
                <p className="mt-1.5 text-sm leading-[1.45] text-ink-on-paper-2">{body}</p>
              </div>
            ))}
          </div>
        </section>

        {/* Capabilities */}
        <section className="pt-16">
          <h2 className={H2}>What can an AI agent do through ViewsMax?</h2>
          <p className={`mt-4 ${PROSE}`}>
            Everything below is a tool call the agent makes on your behalf. Rows marked <strong>write</strong> change something and need full access;
            read-only credentials only see the <strong>read</strong> rows.
          </p>
          <Table head={["Task", "Tools", "Access"]}>
            {CAPABILITIES.map((c) => (
              <tr key={c.task}>
                <td className={TD}>{c.task}</td>
                <td className={`${TD} space-x-1.5 whitespace-nowrap`}>{c.tools.map((t) => <Code key={t}>{t}</Code>)}</td>
                <td className={`${TD} font-semibold ${c.access === "write" ? "text-vm-red" : "text-ink-on-paper-2"}`}>{c.access}</td>
              </tr>
            ))}
          </Table>
          <div className={`mt-6 ${CARD} border-dashed p-5`}>
            <h3 className={H3}>Not through the MCP</h3>
            <ul className="mt-2 list-disc space-y-1 pl-5 text-[15px] leading-[1.5] text-ink-on-paper-2">
              {NOT_SUPPORTED.map((n) => <li key={n}>{n}</li>)}
            </ul>
          </div>
        </section>

        {/* Connect */}
        <section id="connect" className="scroll-mt-24 pt-16">
          <h2 className={H2}>How do I connect an AI agent to ViewsMax?</h2>
          <p className={`mt-4 ${PROSE}`}>
            Link your channels once under Dashboard → Connections, then give your AI client this address. Chat apps sign you in with OAuth;
            headless agents use an API key from {API_KEY_PATH}. The transport is Streamable HTTP — there is no SSE endpoint and nothing to run locally.
          </p>
          <div className="max-w-[720px]">
            <CodeBlock id="endpoint" label="ViewsMax MCP server address" text={MCP_ENDPOINT} copied={copied} copy={copy} />
          </div>
          <p className="mt-3 text-sm leading-[1.5] text-ink-on-paper-2">
            Machine-readable description of the server: <a href={DISCOVERY_URL} className={LINK}>{DISCOVERY_URL}</a>. REST reference:{" "}
            <a href={DOCS_URL} className={LINK}>{DOCS_URL}</a>.
          </p>

          <div className="mt-10 grid grid-cols-1 gap-4 md:grid-cols-2">
            {AGENT_LIST.map((a) => {
              const code = clientSnippet(a);
              const row = CLIENT_MATRIX[a.key];
              return (
                <div key={a.key} className={`${CARD} flex flex-col p-5`}>
                  <h3 className={H3}>{a.name}</h3>
                  <p className="mt-1 text-sm leading-[1.45] text-ink-on-paper-2">{row.method} · {row.auth}</p>
                  {code && <CodeBlock id={`client-${a.key}`} label={code.label} text={code.text} copied={copied} copy={copy} />}
                  <Link to={a.slug} className="mt-auto inline-flex items-center gap-1 pt-4 text-[13px] font-bold text-vm-red">
                    Full {a.name} guide <ArrowRight className="h-3.5 w-3.5" aria-hidden />
                  </Link>
                </div>
              );
            })}
          </div>

          <h3 className={`mt-12 ${H3}`}>Which clients support which connection method?</h3>
          <Table head={["Client", "How it connects", "Credential", "Guide"]}>
            {AGENT_LIST.map((a) => (
              <tr key={a.key}>
                <td className={`${TD} font-semibold`}>{a.name}</td>
                <td className={TD}>{CLIENT_MATRIX[a.key].method}</td>
                <td className={TD}>{CLIENT_MATRIX[a.key].auth}</td>
                <td className={TD}><Link to={a.slug} className={LINK}>Setup steps</Link></td>
              </tr>
            ))}
          </Table>
          <p className="mt-3 text-sm leading-[1.5] text-ink-on-paper-2">
            Using something else? Any MCP client that speaks Streamable HTTP works with the address above — see the{" "}
            <Link to="/ai" className={LINK}>install hub</Link> for the generic setup and the REST API.
          </p>
        </section>

        {/* Platforms */}
        <section className="pt-16">
          <h2 className={H2}>Which platforms can an AI agent post to?</h2>
          <p className={`mt-4 ${PROSE}`}>Whichever of these you have linked in ViewsMax. Each one is published separately and keeps its own status.</p>
          <ul className="mt-8 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {PLATFORMS.map((p) => (
              <li key={p.name} className={`${CARD} flex items-start gap-3 p-4`}>
                <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg text-white shadow-sm" style={{ background: p.color }}>
                  <BrandIcon platform={p.icon} size={18} />
                </span>
                <span>
                  <span className="block font-bold">{p.name}</span>
                  <span className="mt-0.5 block text-sm leading-[1.45] text-ink-on-paper-2">{p.note}</span>
                </span>
              </li>
            ))}
          </ul>
        </section>

        {/* Security */}
        <section className="pt-16">
          <h2 className={H2}>How does authentication and security work?</h2>
          <div className="mt-8 grid grid-cols-1 gap-4 md:grid-cols-2">
            {AUTH_MODES.map((m) => (
              <div key={m.name} className={`${CARD} p-5`}>
                <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-[color:var(--vm-volt-tint-l)] text-vm-volt-deep">
                  {m.name === "API key" ? <KeyRound className="h-5 w-5" aria-hidden /> : <LockKeyhole className="h-5 w-5" aria-hidden />}
                </span>
                <h3 className={`mt-3 ${H3}`}>{m.name}</h3>
                <p className="mt-1 text-[13px] font-semibold text-ink-on-paper-3">Best for {m.bestFor}</p>
                <p className="mt-2 text-[15px] leading-[1.5] text-ink-on-paper-2 [overflow-wrap:anywhere]">{m.how}</p>
              </div>
            ))}
          </div>
          <div className={`mt-4 ${CARD} p-5`}>
            <h3 className={H3}>Scopes and the audit log</h3>
            <p className="mt-2 text-[15px] leading-[1.5] text-ink-on-paper-2 [overflow-wrap:anywhere]">
              Credentials carry <Code>mcp:read</Code> or <Code>mcp:read mcp:write</Code>. A read-only credential doesn't just get refused on
              writes — write tools are left out of its tool list entirely. Every <Code>tools/call</Code> is recorded with the tool, its
              arguments and whether it failed, and you can review the log under {API_KEY_PATH}. OAuth metadata lives at{" "}
              <a href={OAUTH_AS_URL} className={LINK}>{OAUTH_AS_URL.replace("https://", "")}</a> and{" "}
              <a href={OAUTH_PR_URL} className={LINK}>{OAUTH_PR_URL.replace("https://", "")}</a>; clients register themselves at{" "}
              <Code>POST {OAUTH_REGISTER_URL.replace("https://", "")}</Code>.
            </p>
          </div>
          <h3 className={`mt-10 ${H3}`}>Rate limits</h3>
          <Table head={["Scope", "Limit"]}>
            {RATE_LIMITS.map((r) => (
              <tr key={r.scope}>
                <td className={`${TD} font-semibold`}>{r.scope === "All MCP requests" ? r.scope : <Code>{r.scope}</Code>}</td>
                <td className={TD}>{r.limit}</td>
              </tr>
            ))}
          </Table>
          <p className="mt-3 text-sm leading-[1.5] text-ink-on-paper-2">
            A limited call comes back with a retry-after hint. There is no separate charge for the MCP server; plan limits such as monthly posts apply as they do in the app.
          </p>
        </section>

        {/* Example prompts */}
        <section className="pt-16">
          <h2 className={H2}>What can you ask your AI agent?</h2>
          <div className="mt-8 space-y-6">
            {EXAMPLE_PROMPTS.map((p, i) => (
              <div key={p.title} className={`${CARD} p-5`}>
                <h3 className={H3}>{p.title}</h3>
                <PromptBox id={`prompt-${i}`} text={p.prompt} copied={copied} copy={copy} />
                <p className="mt-3 text-[13px] font-semibold text-ink-on-paper-3">Example reply</p>
                <p className="mt-1 text-[15px] leading-[1.5] text-ink-on-paper-2">{p.reply}</p>
              </div>
            ))}
          </div>
        </section>

        {/* Tools */}
        <section id="tools" className="scroll-mt-24 pt-16">
          <h2 className={H2}>Which ViewsMax MCP tools exist?</h2>
          <p className={`mt-4 ${PROSE}`}>
            {MCP_TOOLS.length} tools, grouped by job. The server exposes tools only — no MCP resources or prompts — and its own instructions
            tell the agent the typical posting and research flows.
          </p>
          <div className="mt-8 grid grid-cols-1 gap-4 md:grid-cols-2">
            {TOOL_GROUPS.map((g) => (
              <div key={g.key} className={`${CARD} p-5`}>
                <h3 className={H3}>
                  {g.name} <span className="text-ink-on-paper-3">· {g.tools.length}</span>
                </h3>
                <p className="mt-1.5 text-sm leading-[1.45] text-ink-on-paper-2">{g.blurb}</p>
                <ul className="mt-3 flex flex-wrap gap-1.5">
                  {g.tools.map((t) => (
                    <li key={t}><Code>{t}</Code></li>
                  ))}
                </ul>
              </div>
            ))}
          </div>
        </section>

        {/* FAQ */}
        <section className="pt-16">
          <h2 className={H2}>Frequently asked questions</h2>
          <Accordion items={MCP_FAQ} />
        </section>

        {/* Guides */}
        <section className="pt-16">
          <h2 className={H2}>Setup guides for each AI agent</h2>
          <div className="mt-8 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {AGENT_LIST.map((a) => (
              <Link
                key={a.key}
                to={a.slug}
                className={`${CARD} block p-4 transition-[box-shadow,transform,border-color] duration-200 hover:-translate-x-0.5 hover:-translate-y-0.5 hover:border-ink-on-paper-1 hover:shadow-[4px_4px_0_0_var(--vm-red)]`}
              >
                <span className="block font-bold">{a.name}</span>
                <span className="mt-0.5 block text-sm text-ink-on-paper-2">{a.card}</span>
              </Link>
            ))}
            <Link to="/ai" className={`${CARD} block p-4 hover:border-ink-on-paper-1`}>
              <span className="block font-bold">Another MCP client</span>
              <span className="mt-0.5 block text-sm text-ink-on-paper-2">Generic MCP setup, API keys and the REST API</span>
            </Link>
          </div>
        </section>

        {/* Bottom CTA */}
        <section className="mt-16 rounded-[26px] bg-ink-900 px-6 py-10 text-center text-white sm:px-10">
          <h2 className="font-display text-[26px] font-extrabold leading-[1.1] sm:text-[32px]">Let your AI agent run the posting</h2>
          <p className="mx-auto mt-3 max-w-[520px] text-[16px] leading-[1.5] text-white/70">
            Link your accounts once. After that, publishing, tracked links and revenue reports are a message away in whichever agent you use.
          </p>
          <div className="mt-6 flex flex-wrap justify-center gap-3">
            <Link to="/auth" className={PRIMARY_BTN}>Start for $0</Link>
            <a href="#connect" className="inline-flex h-[50px] items-center justify-center rounded-xl border border-white/30 px-[26px] font-body text-[15px] font-bold text-white transition-colors hover:border-white">
              See the setup steps
            </a>
          </div>
        </section>
      </main>

      <LandingFooter />
    </div>
  );
}
