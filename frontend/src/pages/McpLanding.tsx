// The /mcp landing page: what the ViewsMax MCP server is, what it exposes, and
// how each AI client connects. Content lives in src/lib/mcp-page.ts (words in
// src/lib/mcp-copy/<code>.json) so this page, /mcp.md and the JSON-LD are built
// from one source. Translated at /es/mcp, /ru/mcp, ... (`locale` prop); /mcp.md
// stays English. Companion to /ai (the install hub, the breadcrumb parent) and
// the per-agent guides (/claude, ...).
import { useEffect, useMemo, type ReactNode } from "react";
import { Link } from "react-router-dom";
import {
  ArrowRight, CalendarClock, Check, Copy, FileText, KeyRound, Link2, ListChecks, LockKeyhole, Radar, Share2, type LucideIcon,
} from "lucide-react";
import { LandingNav, LandingFooter } from "@/pages/landing/LandingChrome";
import { BrandIcon } from "@/components/post/brand-icons";
import { Accordion, CARD, CodeBlock, CopyLabelsContext, H2, OUTLINE_BTN, PRIMARY_BTN, PromptBox, useCopy } from "@/components/agents/primitives";
import { useSeo } from "@/hooks/useSeo";
import { AGENT_LIST, MCP_ENDPOINT, PLATFORMS } from "@/lib/agent-pages";
import {
  DISCOVERY_URL,
  DOCS_URL,
  MCP_MD_PATH,
  MCP_PATH,
  OAUTH_AS_URL,
  OAUTH_PR_URL,
  OAUTH_REGISTER_URL,
  buildMcpJsonLd,
  buildMcpMarkdown,
  clientSnippet,
  mcpContent,
  type McpContent,
} from "@/lib/mcp-page";
import { richText } from "@/lib/rich-text";
import { HomeLocaleContext, PagePathContext, homeLocale, localizedPath, useLandingCopy } from "@/pages/landing/locales";
import { useAppLocaleFor } from "@/pages/landing/useAppLocaleFor";

const H3 = "font-display text-[19px] font-bold leading-tight tracking-[-0.01em]";
const PROSE = "max-w-[720px] text-[16px] leading-[1.55] text-ink-on-paper-2 [text-wrap:pretty]";
const LINK = "font-semibold text-ink-on-paper-1 underline underline-offset-2";
const TH = "px-4 py-2.5 text-left text-xs font-bold uppercase tracking-wide text-ink-on-paper-3";
const TD = "px-4 py-3 align-top text-[15px] leading-[1.45]";

const CALLOUT_ICONS: { key: keyof McpContent["copy"]["callouts"]; icon: LucideIcon }[] = [
  { key: "platforms", icon: Share2 },
  { key: "access", icon: LockKeyhole },
  { key: "logged", icon: ListChecks },
  { key: "publish", icon: CalendarClock },
  { key: "offers", icon: Link2 },
  { key: "outliers", icon: Radar },
];

// English install-step labels (from agent-pages) -> their key in the copy.
const SNIPPET_LABEL_KEYS = Object.fromEntries(
  Object.entries(mcpContent().copy.snippetLabels).map(([key, english]) => [english, key]),
) as Record<string, keyof McpContent["copy"]["snippetLabels"]>;

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

// `locale` is a HOME_LOCALES code ("ru", "pt-br", ...); omitted = English at /mcp.
export default function McpLanding({ locale }: { locale?: string }) {
  const home = homeLocale(locale);
  const content = useMemo(() => mcpContent(home.code, home.htmlLang), [home]);
  const p = content.copy.page;
  const path = localizedPath(home, MCP_PATH);
  useAppLocaleFor(home);

  useSeo({
    ...content.seo,
    canonicalPath: path,
    ogImage: "https://viewsmax.com/og-image.png",
    jsonLd: buildMcpJsonLd(content, path, home.path),
  });

  // The prerendered file already has the right lang; this covers in-app
  // navigation, and hands the next page back what it had.
  useEffect(() => {
    const root = document.documentElement;
    const prev = root.lang;
    root.lang = home.htmlLang;
    return () => { root.lang = prev; };
  }, [home]);

  const copyLabels = useMemo(() => ({
    copy: p.copy,
    copyLabel: (label: string) => p.copyLabel.replace("{{label}}", label),
    copied: p.copied,
    copyFailed: p.copyFailed,
    copyPrompt: p.copyPrompt,
    promptCopied: p.promptCopied,
  }), [p]);

  return (
    <HomeLocaleContext.Provider value={home}>
      <PagePathContext.Provider value={MCP_PATH}>
        <CopyLabelsContext.Provider value={copyLabels}>
          <McpPage content={content} />
        </CopyLabelsContext.Provider>
      </PagePathContext.Provider>
    </HomeLocaleContext.Provider>
  );
}

function McpPage({ content }: { content: McpContent }) {
  const { copied, copy } = useCopy();
  const agentCards = useLandingCopy().agentCards;
  const c = content.copy;
  const p = c.page;
  const accessLabel = (access: "read" | "write") => (access === "write" ? p.accessWrite : p.accessRead);
  const snippetLabel = (english: string) => {
    const key = SNIPPET_LABEL_KEYS[english];
    return key ? c.snippetLabels[key] : english;
  };

  return (
    <div data-prerender-ready lang={content.lang} className="flex min-h-screen flex-col bg-paper-1 font-body text-ink-on-paper-1">
      <LandingNav />

      <main className="mx-auto w-full max-w-[980px] flex-1 px-4 pb-20 pt-12 sm:px-6 sm:pt-16">
        {/* Hero */}
        <nav aria-label="Breadcrumb" className="mb-5 text-[13px] font-semibold text-ink-on-paper-3">
          <Link to="/ai" className="hover:text-ink-on-paper-1">{p.breadcrumbAi}</Link>
          <span className="mx-1.5" aria-hidden>/</span>
          <span className="text-ink-on-paper-2" aria-current="page">{p.breadcrumbMcp}</span>
        </nav>
        <div className="flex flex-wrap items-center gap-3">
          <span className="inline-flex items-center gap-1.5 rounded-full border border-line-1 bg-paper-0 px-3.5 py-[7px] text-xs font-bold text-ink-on-paper-2">
            {p.badge}
          </span>
          <span className="flex gap-1.5" aria-label={p.platformsAria}>
            {PLATFORMS.map((pl) => (
              <span key={pl.name} title={pl.name} className="grid h-7 w-7 place-items-center rounded-lg text-white shadow-sm" style={{ background: pl.color }}>
                <BrandIcon platform={pl.icon} size={14} />
              </span>
            ))}
          </span>
        </div>
        <h1 className="mb-4 mt-[22px] max-w-[860px] font-display text-[clamp(34px,5vw,56px)] font-extrabold leading-[1.02] tracking-[-0.03em] [text-wrap:balance]">
          {p.h1}
        </h1>
        <p className="max-w-[720px] text-[19px] leading-[1.5] text-ink-on-paper-2 [text-wrap:pretty]">
          {p.lead}
        </p>

        <div className="mt-8 flex flex-wrap gap-3">
          <button type="button" onClick={() => copy(MCP_ENDPOINT, "hero", p.addressCopied)} className={PRIMARY_BTN}>
            {copied === "hero" ? <Check className="h-4 w-4" aria-hidden /> : <Copy className="h-4 w-4" aria-hidden />} {p.copyAddress}
          </button>
          <Link to="/auth" className={OUTLINE_BTN}>{p.start}</Link>
        </div>
        <p className="mt-4 font-mono text-[13px] text-ink-on-paper-3">{MCP_ENDPOINT}</p>

        <aside className="mt-8 flex flex-wrap items-center justify-between gap-4 rounded-[18px] border border-dashed border-line-2 px-5 py-4">
          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-2 font-bold"><FileText className="h-4 w-4 text-vm-red" aria-hidden /> {p.aiReaderTitle}</div>
            <p className="mt-1 text-sm leading-[1.45] text-ink-on-paper-2">{p.aiReaderBody}</p>
          </div>
          <div className="flex flex-wrap items-center gap-3 text-sm">
            <button
              type="button"
              onClick={() => copy(buildMcpMarkdown(), "md", p.markdownCopied)}
              className="inline-flex items-center gap-1.5 rounded-lg border border-line-2 bg-paper-0 px-3 py-2 font-bold hover:border-ink-on-paper-1"
            >
              {copied === "md" ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />} {p.copyMarkdown}
            </button>
            <a href={MCP_MD_PATH} className="font-semibold text-ink-on-paper-2 underline underline-offset-2">{p.viewPlainText}</a>
          </div>
        </aside>

        {/* What is it */}
        <section className="pt-16">
          <h2 className={H2}>{p.whatIsTitle}</h2>
          <p className={`mt-4 ${PROSE}`}>{p.whatIsBody}</p>
          <div className="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {CALLOUT_ICONS.map(({ key, icon: Icon }) => ({ key, Icon, ...c.callouts[key] })).map(({ key, Icon, title, body }) => (
              <div key={key} className={`${CARD} p-5`}>
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
          <h2 className={H2}>{p.capabilitiesTitle}</h2>
          <p className={`mt-4 ${PROSE}`}>
            {richText(p.capabilitiesIntro, { b: (t) => <strong>{t}</strong> })}
          </p>
          <Table head={[p.headTask, p.headTools, p.headAccess]}>
            {content.capabilities.map((cap) => (
              <tr key={cap.task}>
                <td className={TD}>{cap.task}</td>
                <td className={`${TD} space-x-1.5 whitespace-nowrap`}>{cap.tools.map((t) => <Code key={t}>{t}</Code>)}</td>
                <td className={`${TD} font-semibold ${cap.access === "write" ? "text-vm-red" : "text-ink-on-paper-2"}`}>{accessLabel(cap.access)}</td>
              </tr>
            ))}
          </Table>
          <div className={`mt-6 ${CARD} border-dashed p-5`}>
            <h3 className={H3}>{p.notSupportedTitle}</h3>
            <ul className="mt-2 list-disc space-y-1 pl-5 text-[15px] leading-[1.5] text-ink-on-paper-2">
              {content.notSupported.map((n) => <li key={n}>{n}</li>)}
            </ul>
          </div>
        </section>

        {/* Connect */}
        <section id="connect" className="scroll-mt-24 pt-16">
          <h2 className={H2}>{p.connectTitle}</h2>
          <p className={`mt-4 ${PROSE}`}>{p.connectBody}</p>
          <div className="max-w-[720px]">
            <CodeBlock id="endpoint" label={p.addressLabel} text={MCP_ENDPOINT} copied={copied} copy={copy} />
          </div>
          <p className="mt-3 text-sm leading-[1.5] text-ink-on-paper-2">
            {richText(p.discoveryLine, {
              discovery: () => <a href={DISCOVERY_URL} className={LINK}>{DISCOVERY_URL}</a>,
              docs: () => <a href={DOCS_URL} className={LINK}>{DOCS_URL}</a>,
            })}
          </p>

          <div className="mt-10 grid grid-cols-1 gap-4 md:grid-cols-2">
            {AGENT_LIST.map((a) => {
              const code = clientSnippet(a);
              const row = content.clientMatrix[a.key];
              return (
                <div key={a.key} className={`${CARD} flex flex-col p-5`}>
                  <h3 className={H3}>{a.name}</h3>
                  <p className="mt-1 text-sm leading-[1.45] text-ink-on-paper-2">{row.method} · {row.auth}</p>
                  {code && <CodeBlock id={`client-${a.key}`} label={snippetLabel(code.label)} text={code.text} copied={copied} copy={copy} />}
                  <Link to={a.slug} className="mt-auto inline-flex items-center gap-1 pt-4 text-[13px] font-bold text-vm-red">
                    {p.fullGuide.replace("{{name}}", a.name)} <ArrowRight className="h-3.5 w-3.5" aria-hidden />
                  </Link>
                </div>
              );
            })}
          </div>

          <h3 className={`mt-12 ${H3}`}>{p.matrixTitle}</h3>
          <Table head={[p.headClient, p.headHow, p.headCredential, p.headGuide]}>
            {AGENT_LIST.map((a) => (
              <tr key={a.key}>
                <td className={`${TD} font-semibold`}>{a.name}</td>
                <td className={TD}>{content.clientMatrix[a.key].method}</td>
                <td className={TD}>{content.clientMatrix[a.key].auth}</td>
                <td className={TD}><Link to={a.slug} className={LINK}>{p.setupSteps}</Link></td>
              </tr>
            ))}
          </Table>
          <p className="mt-3 text-sm leading-[1.5] text-ink-on-paper-2">
            {richText(p.otherClient, { hub: (t) => <Link to="/ai" className={LINK}>{t}</Link> })}
          </p>
        </section>

        {/* Platforms */}
        <section className="pt-16">
          <h2 className={H2}>{p.platformsTitle}</h2>
          <p className={`mt-4 ${PROSE}`}>{p.platformsBody}</p>
          <ul className="mt-8 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {PLATFORMS.map((pl) => (
              <li key={pl.name} className={`${CARD} flex items-start gap-3 p-4`}>
                <span className="grid h-9 w-9 shrink-0 place-items-center rounded-lg text-white shadow-sm" style={{ background: pl.color }}>
                  <BrandIcon platform={pl.icon} size={18} />
                </span>
                <span>
                  <span className="block font-bold">{pl.name}</span>
                  <span className="mt-0.5 block text-sm leading-[1.45] text-ink-on-paper-2">{c.platformNotes[pl.icon as keyof typeof c.platformNotes] ?? pl.note}</span>
                </span>
              </li>
            ))}
          </ul>
        </section>

        {/* Security */}
        <section className="pt-16">
          <h2 className={H2}>{p.securityTitle}</h2>
          <div className="mt-8 grid grid-cols-1 gap-4 md:grid-cols-2">
            {content.authModes.map((m) => (
              <div key={m.key} className={`${CARD} p-5`}>
                <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-[color:var(--vm-volt-tint-l)] text-vm-volt-deep">
                  {m.key === "apiKey" ? <KeyRound className="h-5 w-5" aria-hidden /> : <LockKeyhole className="h-5 w-5" aria-hidden />}
                </span>
                <h3 className={`mt-3 ${H3}`}>{m.name}</h3>
                <p className="mt-1 text-[13px] font-semibold text-ink-on-paper-3">{p.bestFor.replace("{{who}}", m.bestFor)}</p>
                <p className="mt-2 text-[15px] leading-[1.5] text-ink-on-paper-2 [overflow-wrap:anywhere]">{m.how}</p>
              </div>
            ))}
          </div>
          <div className={`mt-4 ${CARD} p-5`}>
            <h3 className={H3}>{p.scopesTitle}</h3>
            <p className="mt-2 text-[15px] leading-[1.5] text-ink-on-paper-2 [overflow-wrap:anywhere]">
              {richText(p.scopesBody, {
                code: (t) => <Code>{t}</Code>,
                as: () => <a href={OAUTH_AS_URL} className={LINK}>{OAUTH_AS_URL.replace("https://", "")}</a>,
                pr: () => <a href={OAUTH_PR_URL} className={LINK}>{OAUTH_PR_URL.replace("https://", "")}</a>,
                register: () => <Code>POST {OAUTH_REGISTER_URL.replace("https://", "")}</Code>,
              })}
            </p>
          </div>
          <h3 className={`mt-10 ${H3}`}>{p.rateLimitsTitle}</h3>
          <Table head={[p.headScope, p.headLimit]}>
            {content.rateLimits.map((rl) => (
              <tr key={rl.scope}>
                <td className={`${TD} font-semibold`}>{rl.tool ? <Code>{rl.scope}</Code> : rl.scope}</td>
                <td className={TD}>{rl.limit}</td>
              </tr>
            ))}
          </Table>
          <p className="mt-3 text-sm leading-[1.5] text-ink-on-paper-2">{p.rateLimitsAfter}</p>
        </section>

        {/* Example prompts */}
        <section className="pt-16">
          <h2 className={H2}>{p.promptsTitle}</h2>
          <div className="mt-8 space-y-6">
            {content.examplePrompts.map((ex, i) => (
              <div key={ex.title} className={`${CARD} p-5`}>
                <h3 className={H3}>{ex.title}</h3>
                <PromptBox id={`prompt-${i}`} text={ex.prompt} copied={copied} copy={copy} />
                <p className="mt-3 text-[13px] font-semibold text-ink-on-paper-3">{p.exampleReply}</p>
                <p className="mt-1 text-[15px] leading-[1.5] text-ink-on-paper-2">{ex.reply}</p>
              </div>
            ))}
          </div>
        </section>

        {/* Tools */}
        <section id="tools" className="scroll-mt-24 pt-16">
          <h2 className={H2}>{p.toolsTitle}</h2>
          <p className={`mt-4 ${PROSE}`}>{p.toolsIntro}</p>
          <div className="mt-8 grid grid-cols-1 gap-4 md:grid-cols-2">
            {content.toolGroups.map((g) => (
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
          <h2 className={H2}>{p.faqTitle}</h2>
          <Accordion items={content.faq} />
        </section>

        {/* Guides */}
        <section className="pt-16">
          <h2 className={H2}>{p.guidesTitle}</h2>
          <div className="mt-8 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {AGENT_LIST.map((a) => (
              <Link
                key={a.key}
                to={a.slug}
                className={`${CARD} block p-4 transition-[box-shadow,transform,border-color] duration-200 hover:-translate-x-0.5 hover:-translate-y-0.5 hover:border-ink-on-paper-1 hover:shadow-[4px_4px_0_0_var(--vm-red)]`}
              >
                <span className="block font-bold">{a.name}</span>
                <span className="mt-0.5 block text-sm text-ink-on-paper-2">{agentCards[a.key]}</span>
              </Link>
            ))}
            <Link to="/ai" className={`${CARD} block p-4 hover:border-ink-on-paper-1`}>
              <span className="block font-bold">{p.otherClientTitle}</span>
              <span className="mt-0.5 block text-sm text-ink-on-paper-2">{p.otherClientBody}</span>
            </Link>
          </div>
        </section>

        {/* Bottom CTA */}
        <section className="mt-16 rounded-[26px] bg-ink-900 px-6 py-10 text-center text-white sm:px-10">
          <h2 className="font-display text-[26px] font-extrabold leading-[1.1] sm:text-[32px]">{p.ctaTitle}</h2>
          <p className="mx-auto mt-3 max-w-[520px] text-[16px] leading-[1.5] text-white/70">{p.ctaBody}</p>
          <div className="mt-6 flex flex-wrap justify-center gap-3">
            <Link to="/auth" className={PRIMARY_BTN}>{p.start}</Link>
            <a href="#connect" className="inline-flex h-[50px] items-center justify-center rounded-xl border border-white/30 px-[26px] font-body text-[15px] font-bold text-white transition-colors hover:border-white">
              {p.ctaSetupSteps}
            </a>
          </div>
        </section>
      </main>

      <LandingFooter />
    </div>
  );
}
