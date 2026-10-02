// Shared page for the per-agent setup guides (/claude, /chatgpt, /cursor, ...).
// Content comes from src/lib/agent-pages.ts. While ViewsMax isn't listed in an
// agent's app directory, step 2 shows the manual setup; once the listing's env
// var is set, step 2 leads with the directory button and the manual setup moves
// into a collapsed "Rather set it up by hand?" section.
import type { ReactNode } from "react";
import { Link } from "react-router-dom";
import { Check, ChevronRight, Copy, ExternalLink, FileText, Plus } from "lucide-react";
import { LandingNav, LandingFooter } from "@/pages/landing/LandingChrome";
import { Accordion, CARD, CodeBlock, H2, OUTLINE_BTN, PRIMARY_BTN, PromptBox, useCopy, type CopyFn } from "@/components/agents/primitives";
import { BrandIcon } from "@/components/post/brand-icons";
import { useSeo } from "@/hooks/useSeo";
import {
  AGENTS,
  AGENT_LIST,
  CHECK_PROMPT,
  CONNECT_STEP,
  MCP_TOOLS,
  MULTI_ACCOUNT_TIP,
  PLATFORMS,
  STEP_TIMES,
  WHAT_HAPPENS,
  TEST_STEP_TITLE,
  TRY_STEP_TITLE,
  addStepTitle,
  agentListingUrl,
  briefPath,
  buildAgentJsonLd,
  buildAgentMarkdown,
  type AgentKey,
  type InstallStep,
} from "@/lib/agent-pages";

function SubSteps({ steps, idPrefix, copied, copy }: { steps: InstallStep[]; idPrefix: string; copied: string | null; copy: CopyFn }) {
  return (
    <ol className="mt-4 space-y-5">
      {steps.map((s, i) => (
        <li key={s.title} className="flex gap-3">
          <span className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-line-2 text-xs font-bold text-ink-on-paper-2">
            {String.fromCharCode(97 + i)}
          </span>
          <div className="min-w-0 flex-1">
            <div className="font-bold">{s.title}</div>
            <p className="mt-1 text-[15px] leading-[1.5] text-ink-on-paper-2">{s.body}</p>
            {s.code && <CodeBlock id={`${idPrefix}-${i}`} label={s.code.label} text={s.code.text} copied={copied} copy={copy} />}
            {s.note && <p className="mt-2 text-[13px] leading-[1.45] text-ink-on-paper-3">{s.note}</p>}
          </div>
        </li>
      ))}
    </ol>
  );
}

function Step({ n, title, time, children }: { n: number; title: string; time?: string; children: ReactNode }) {
  return (
    <li className={`${CARD} p-5 sm:p-6`}>
      <div className="flex items-center gap-3">
        <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-[color:var(--vm-red-tint-l)] font-display text-sm font-extrabold text-vm-red">{n}</span>
        <h3 className="flex-1 font-display text-[19px] font-bold leading-tight tracking-[-0.01em]">{title}</h3>
        {time && <span className="hidden shrink-0 text-xs font-semibold text-ink-on-paper-3 sm:inline">{time}</span>}
      </div>
      <div className="mt-3 sm:pl-11">{children}</div>
    </li>
  );
}

export default function AgentPage({ agent }: { agent: AgentKey }) {
  const cfg = AGENTS[agent];
  const env = import.meta.env as Record<string, string | undefined>;
  const listing = agentListingUrl(cfg, env);
  const { copied, copy } = useCopy();

  useSeo({
    title: cfg.seo.title,
    description: cfg.seo.description,
    keywords: cfg.seo.keywords,
    canonicalPath: cfg.slug,
    ogImage: "https://viewsmax.com/og-image.png",
    jsonLd: buildAgentJsonLd(cfg),
  });

  const others = AGENT_LIST.filter((a) => a.key !== agent);
  const manual = <SubSteps steps={cfg.install} idPrefix="install" copied={copied} copy={copy} />;

  return (
    <div data-prerender-ready className="flex min-h-screen flex-col bg-paper-1 font-body text-ink-on-paper-1">
      <LandingNav />

      <main className="mx-auto w-full max-w-[820px] flex-1 px-4 pb-20 pt-12 sm:px-6 sm:pt-16">
        {/* Hero */}
        <nav aria-label="Breadcrumb" className="mb-5 text-[13px] font-semibold text-ink-on-paper-3">
          <Link to="/ai" className="hover:text-ink-on-paper-1">AI agents</Link>
          <span className="mx-1.5" aria-hidden>/</span>
          <span className="text-ink-on-paper-2" aria-current="page">{cfg.name}</span>
        </nav>
        <div className="flex flex-wrap items-center gap-3">
          <span className="inline-flex items-center gap-1.5 rounded-full border border-line-1 bg-paper-0 px-3.5 py-[7px] text-xs font-bold text-ink-on-paper-2">
            {cfg.name}
            <Plus className="h-2.5 w-2.5 text-ink-on-paper-3" strokeWidth={3} aria-hidden />
            <span className="sr-only">+</span>
            ViewsMax
          </span>
          <span className="flex gap-1.5" aria-label={`Posts to ${PLATFORMS.map((p) => p.name).join(", ")}`}>
            {PLATFORMS.map((p) => (
              <span key={p.name} title={p.name} className="grid h-7 w-7 place-items-center rounded-lg text-white shadow-sm" style={{ background: p.color }}>
                <BrandIcon platform={p.icon} size={14} />
              </span>
            ))}
          </span>
        </div>
        <h1 className="mb-4 mt-[22px] font-display text-[clamp(34px,5vw,56px)] font-extrabold leading-[1.02] tracking-[-0.03em] [text-wrap:balance]">
          {cfg.h1}
        </h1>
        <p className="max-w-[720px] text-[19px] leading-[1.5] text-ink-on-paper-2 [text-wrap:pretty]">{cfg.subhead}</p>

        <div className="mt-8 flex flex-wrap gap-3">
          {listing ? (
            <a href={listing} target="_blank" rel="noreferrer" className={PRIMARY_BTN}>
              {cfg.listing!.label} <ExternalLink className="h-4 w-4" aria-hidden />
            </a>
          ) : (
            <a href="#setup" className={PRIMARY_BTN}>See the setup steps</a>
          )}
          <Link to="/auth" className={OUTLINE_BTN}>Create a free ViewsMax account</Link>
        </div>
        <p className="mt-4 text-[13px] font-semibold text-ink-on-paper-3">
          {cfg.setupTime}
        </p>

        {/* Setup brief for AI agents */}
          <aside className="mt-8 flex flex-wrap items-center justify-between gap-4 rounded-[18px] border border-dashed border-line-2 px-5 py-4">
            <div className="min-w-0 flex-1">
              <div className="flex items-center gap-2 font-bold"><FileText className="h-4 w-4 text-vm-red" aria-hidden /> Getting an AI to help with setup?</div>
              <p className="mt-1 text-sm leading-[1.45] text-ink-on-paper-2">Give it the setup brief: these steps, prompts and fixes as plain text.</p>
            </div>
            <div className="flex flex-wrap items-center gap-3 text-sm">
              <button
                type="button"
                onClick={() => copy(buildAgentMarkdown(cfg, env), "brief", "Setup brief copied")}
                className="inline-flex items-center gap-1.5 rounded-lg border border-line-2 bg-paper-0 px-3 py-2 font-bold hover:border-ink-on-paper-1"
              >
                {copied === "brief" ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />} Copy setup brief
              </button>
              <a href={briefPath(cfg)} className="font-semibold text-ink-on-paper-2 underline underline-offset-2">View plain text</a>
            </div>
          </aside>

        {/* Setup */}
        <section id="setup" className="scroll-mt-24 pt-16">
          <h2 className={H2}>How to connect ViewsMax to {cfg.name}</h2>
          <ol className="mt-8 space-y-4">
            <Step n={1} title={CONNECT_STEP.title} time={STEP_TIMES.connect}>
              <p className="text-[15px] leading-[1.5] text-ink-on-paper-2">
                <Link to="/auth" className="font-semibold text-ink-on-paper-1 underline underline-offset-2">Sign up for ViewsMax</Link>
                {CONNECT_STEP.body.replace(/^Sign up for ViewsMax/, "")}
              </p>
            </Step>

            <Step n={2} title={addStepTitle(cfg)} time={cfg.addTime}>
              {listing ? (
                <>
                  <p className="text-[15px] leading-[1.5] text-ink-on-paper-2">{cfg.listing!.steps}</p>
                  {cfg.listing!.code && (
                    <CodeBlock id="listing" label={cfg.listing!.code.label} text={cfg.listing!.code.text} copied={copied} copy={copy} />
                  )}
                  <a href={listing} target="_blank" rel="noreferrer" className={`${PRIMARY_BTN} mt-4 h-11 text-sm`}>
                    {cfg.listing!.label} <ExternalLink className="h-4 w-4" aria-hidden />
                  </a>
                  <details className="group mt-5">
                    <summary className="flex cursor-pointer list-none items-center gap-1.5 text-sm font-bold [&::-webkit-details-marker]:hidden">
                      <ChevronRight className="h-4 w-4 transition-transform group-open:rotate-90" aria-hidden /> Rather set it up by hand?
                    </summary>
                    {manual}
                  </details>
                </>
              ) : (
                <>
                  {cfg.tip && (
                    <p className="rounded-xl border border-line-1 bg-paper-2 px-4 py-3 text-[15px] leading-[1.5] text-ink-on-paper-2">{cfg.tip}</p>
                  )}
                  {manual}
                </>
              )}
              {cfg.apiKeyRoute && (
                <details className="group mt-5">
                  <summary className="flex cursor-pointer list-none items-center gap-1.5 text-sm font-bold [&::-webkit-details-marker]:hidden">
                    <ChevronRight className="h-4 w-4 transition-transform group-open:rotate-90" aria-hidden /> {cfg.apiKeyRoute.title}
                  </summary>
                  <p className="mt-3 text-[15px] leading-[1.5] text-ink-on-paper-2">{cfg.apiKeyRoute.body}</p>
                  {cfg.apiKeyRoute.code && (
                    <CodeBlock id="api-key" label={cfg.apiKeyRoute.code.label} text={cfg.apiKeyRoute.code.text} copied={copied} copy={copy} />
                  )}
                </details>
              )}
            </Step>

            <Step n={3} title={TEST_STEP_TITLE} time={STEP_TIMES.test}>
              <p className="text-[15px] leading-[1.5] text-ink-on-paper-2">
                Open a new conversation and paste this. The reply proves the connection works and gives you your channel names to reuse in later requests.
              </p>
              <PromptBox id="check" text={CHECK_PROMPT} copied={copied} copy={copy} />
              <p className="mt-3 text-[13px] leading-[1.45] text-ink-on-paper-3">{MULTI_ACCOUNT_TIP}</p>
            </Step>

            <Step n={4} title={TRY_STEP_TITLE} time={STEP_TIMES.try}>
              <p className="text-[15px] leading-[1.5] text-ink-on-paper-2">
                {cfg.name} checks with you before touching your account. Want something live straight away? Just say so. Would rather look first? Ask for a draft.
              </p>
              <PromptBox id="first" text={cfg.firstTask} copied={copied} copy={copy} />
              <div className="mt-3 ml-6 rounded-xl rounded-tl-sm border border-line-1 bg-paper-0 px-4 py-3">
                <div className="text-[11px] font-bold uppercase tracking-[0.08em] text-ink-on-paper-3">Example reply</div>
                <p className="mt-1 text-[15px] leading-[1.5]">{cfg.firstReply}</p>
              </div>
              <div className="mt-5 text-sm font-bold">What happens next</div>
              <ul className="mt-2 space-y-1.5">
                {WHAT_HAPPENS.map((w) => (
                  <li key={w} className="flex gap-2 text-[15px] leading-[1.5] text-ink-on-paper-2">
                    <Check className="mt-1 h-4 w-4 shrink-0 text-vm-red" aria-hidden /> {w.replace("{name}", cfg.name)}
                  </li>
                ))}
              </ul>
            </Step>
          </ol>

        </section>

        {/* Prompts */}
        <section className="pt-16">
          <h2 className={H2}>What can you ask {cfg.name} to do?</h2>
          <div className="mt-8 grid gap-4 sm:grid-cols-2">
            {cfg.prompts.map((p, i) => (
              <div key={p.title} className={`${CARD} p-5`}>
                <div className="font-display text-[17px] font-bold leading-tight">{p.title}</div>
                <PromptBox id={`prompt-${i}`} text={p.prompt} copied={copied} copy={copy} />
              </div>
            ))}
          </div>
        </section>

        {/* Platforms */}
        <section className="pt-16">
          <h2 className={H2}>Which platforms can {cfg.name} post to?</h2>
          <div className={`${CARD} mt-8 divide-y divide-line-1`}>
            {PLATFORMS.map((p) => (
              <div key={p.name} className="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3.5">
                <span className="flex w-32 shrink-0 items-center gap-2.5 font-bold">
                  <span className="grid h-6 w-6 place-items-center rounded-md text-white" style={{ background: p.color }}>
                    <BrandIcon platform={p.icon} size={12} />
                  </span>
                  {p.name}
                </span>
                <span className="text-[15px] text-ink-on-paper-2">{p.note}</span>
              </div>
            ))}
          </div>
          <p className="mt-3 text-[13px] text-ink-on-paper-3">
            Media is added by link. A channel has to be linked in ViewsMax before {cfg.name} can use it.
          </p>
        </section>

        {/* Troubleshooting */}
        <section className="pt-16">
          <h2 className={H2}>Troubleshooting</h2>
          <Accordion items={cfg.troubleshooting} />
        </section>

        {/* FAQ */}
        <section className="pt-16">
          <h2 className={H2}>Frequently asked questions</h2>
          <Accordion items={cfg.faq} />
        </section>

        {/* Other agents */}
        <section className="pt-16">
          <h2 className={H2}>Using a different AI agent?</h2>
          <div className="mt-8 grid gap-3 sm:grid-cols-2">
            {others.map((a) => (
              <Link
                key={a.key}
                to={a.slug}
                className={`${CARD} block p-4 transition-[box-shadow,transform,border-color] duration-200 hover:-translate-x-0.5 hover:-translate-y-0.5 hover:border-ink-on-paper-1 hover:shadow-[4px_4px_0_0_var(--vm-red)]`}
              >
                <span className="block font-bold">{a.name}</span>
                <span className="mt-0.5 block text-sm text-ink-on-paper-2">{a.card}</span>
              </Link>
            ))}
            <Link to="/mcp" className={`${CARD} block p-4 hover:border-ink-on-paper-1`}>
              <span className="block font-bold">Another MCP client</span>
              <span className="mt-0.5 block text-sm text-ink-on-paper-2">The ViewsMax MCP server: address, auth, and all {MCP_TOOLS.length} tools</span>
            </Link>
          </div>
        </section>

        {/* Bottom CTA */}
        <section className="mt-16 rounded-[26px] bg-ink-900 px-6 py-10 text-center text-white sm:px-10">
          <h2 className="font-display text-[26px] font-extrabold leading-[1.1] sm:text-[32px]">From one chat to every channel</h2>
          <p className="mx-auto mt-3 max-w-[520px] text-[16px] leading-[1.5] text-white/70">
            Link your accounts once. After that, publishing, tracked links and sales reports are a message away.
          </p>
          <div className="mt-6 flex flex-wrap justify-center gap-3">
            <Link to="/auth" className={PRIMARY_BTN}>Start for $0</Link>
          </div>
        </section>
      </main>

      <LandingFooter />
    </div>
  );
}
