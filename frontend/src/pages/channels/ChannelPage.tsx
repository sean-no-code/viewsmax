// Shared page for the per-channel guides (/channels/pinterest, /channels/youtube, ...).
// Content comes from src/lib/channel-pages.ts; the layout is the house style
// (tokens in index.css, primitives shared with the agent guides). Prerendered at
// build time with its head tags and body, so crawlers get the text without JS.
import { Link } from "react-router-dom";
import { ArrowRight, Check, Link2 } from "lucide-react";
import { LandingNav, LandingFooter } from "@/pages/landing/LandingChrome";
import { Accordion, CARD, H2, OUTLINE_BTN, PRIMARY_BTN } from "@/components/agents/primitives";
import { BrandIcon } from "@/components/post/brand-icons";
import { useSeo } from "@/hooks/useSeo";
import { CHANNELS, CHANNEL_LIST, CHANNELS_PATH, buildChannelJsonLd, type ChannelConfig, type ChannelKey } from "@/lib/channel-pages";

const SIGNUP = "/auth?tab=signup";
const OG_IMAGE = "https://viewsmax.com/og-image.png";

export function ChannelTile({ cfg, size = 56 }: { cfg: ChannelConfig; size?: number }) {
  return (
    <span className="grid shrink-0 place-items-center rounded-2xl text-white shadow-md" style={{ width: size, height: size, background: cfg.color }} aria-hidden>
      <BrandIcon platform={cfg.key} size={Math.round(size * 0.5)} />
    </span>
  );
}

/** The "what you can post" card: the limits the composer enforces, as a quick read. */
function SpecCard({ cfg }: { cfg: ChannelConfig }) {
  return (
    <aside className={`${CARD} overflow-hidden`} aria-label={`What you can post to ${cfg.name}`}>
      <div className="h-1.5" style={{ background: cfg.color }} />
      <div className="p-6">
        <div className="text-xs font-bold uppercase tracking-[0.08em] text-ink-on-paper-3">What you can post</div>
        <ul className="mt-3 flex flex-wrap gap-2" aria-label="Formats">
          {cfg.formats.map((f) => (
            <li key={f} className="rounded-full border border-line-2 bg-paper-1 px-3 py-1 text-[13px] font-bold">{f}</li>
          ))}
        </ul>
        <div className="mt-6 flex items-end gap-3">
          <span className="font-display text-[44px] font-black leading-none tracking-[-0.03em]">{cfg.captionLimit.toLocaleString("en-US")}</span>
          <span className="pb-1 text-sm leading-tight text-ink-on-paper-2">
            character caption
            {cfg.captionNote && <><br /><span className="text-ink-on-paper-3">{cfg.captionNote}</span></>}
          </span>
        </div>
        <dl className="mt-6 space-y-4 text-sm">
          <div>
            <dt className="flex items-center gap-1.5 font-bold"><Link2 className="h-3.5 w-3.5 text-vm-red" aria-hidden /> Connects with</dt>
            <dd className="mt-1 text-ink-on-paper-2">{cfg.connect}</dd>
          </div>
          <div>
            <dt className="font-bold">{cfg.name} options</dt>
            <dd>
              <ul className="mt-1.5 space-y-1.5">
                {cfg.options.map((o) => (
                  <li key={o} className="flex items-start gap-2 text-ink-on-paper-2"><Check className="mt-[3px] h-3.5 w-3.5 shrink-0 text-vm-red" aria-hidden />{o}</li>
                ))}
              </ul>
            </dd>
          </div>
        </dl>
      </div>
    </aside>
  );
}

export default function ChannelPage({ channel }: { channel: ChannelKey }) {
  const cfg = CHANNELS[channel];
  useSeo({ ...cfg.seo, canonicalPath: cfg.slug, ogImage: OG_IMAGE, jsonLd: buildChannelJsonLd(cfg) });
  const others = CHANNEL_LIST.filter((c) => c.key !== channel);

  return (
    <div data-prerender-ready className="flex min-h-screen flex-col bg-paper-1 font-body text-ink-on-paper-1">
      <LandingNav />

      <main className="mx-auto w-full max-w-[1040px] flex-1 px-4 pb-20 pt-10 sm:px-6 sm:pt-14">
        <nav aria-label="Breadcrumb" className="mb-6 text-[13px] font-semibold text-ink-on-paper-3">
          <Link to={CHANNELS_PATH} className="hover:text-ink-on-paper-1">Channels</Link>
          <span className="mx-1.5" aria-hidden>/</span>
          <span className="text-ink-on-paper-2" aria-current="page">{cfg.name}</span>
        </nav>

        {/* Hero: copy on the left, the channel's real limits on the right */}
        <section className="grid gap-10 lg:grid-cols-[1.3fr_1fr] lg:items-center">
          <div>
            <div className="flex items-center gap-4">
              <ChannelTile cfg={cfg} />
              <span className="inline-flex items-center rounded-full border border-line-1 bg-paper-0 px-3.5 py-[7px] text-xs font-bold text-ink-on-paper-2">
                {cfg.name} scheduler
              </span>
            </div>
            <h1 className="mt-6 font-display text-[clamp(34px,5vw,54px)] font-extrabold leading-[1.02] tracking-[-0.03em] [text-wrap:balance]">{cfg.h1}</h1>
            <p className="mt-5 max-w-[620px] text-[19px] leading-[1.5] text-ink-on-paper-2 [text-wrap:pretty]">{cfg.subhead}</p>
            <div className="mt-8 flex flex-wrap gap-3">
              <Link to={SIGNUP} className={PRIMARY_BTN}>Start for $0</Link>
              <a href="#how-it-works" className={OUTLINE_BTN}>See how it works</a>
            </div>
            <p className="mt-4 text-[13px] font-semibold text-ink-on-paper-3">Free plan. No card. Connect {cfg.name} in about a minute.</p>
          </div>
          <SpecCard cfg={cfg} />
        </section>

        {/* Steps */}
        <section id="how-it-works" className="mt-20 scroll-mt-24">
          <h2 className={H2}>How {cfg.name} scheduling works</h2>
          <ol className="mt-8 grid gap-4 sm:grid-cols-3">
            {cfg.steps.map((s, i) => (
              <li key={s.title} className={`${CARD} p-6`}>
                <span className="flex h-9 w-9 items-center justify-center rounded-full bg-[color:var(--vm-red-tint-l)] font-display text-sm font-extrabold text-vm-red">{i + 1}</span>
                <h3 className="mt-4 font-display text-[19px] font-bold leading-tight tracking-[-0.01em]">{s.title}</h3>
                <p className="mt-2 text-[15px] leading-[1.5] text-ink-on-paper-2">{s.body}</p>
              </li>
            ))}
          </ol>
        </section>

        {/* Features */}
        <section className="mt-20">
          <h2 className={H2}>Built for {cfg.name}</h2>
          <div className="mt-8 grid gap-4 sm:grid-cols-2">
            {cfg.features.map((f) => (
              <article key={f.title} className={`${CARD} p-6`}>
                <h3 className="font-display text-[19px] font-bold leading-tight tracking-[-0.01em]">{f.title}</h3>
                <p className="mt-2 text-[15px] leading-[1.5] text-ink-on-paper-2">{f.body}</p>
              </article>
            ))}
          </div>
        </section>

        {/* FAQ */}
        <section className="mt-20">
          <h2 className={H2}>{cfg.name} scheduling questions</h2>
          <Accordion items={cfg.faq} />
        </section>

        {/* Other channels */}
        <section className="mt-20">
          <div className="flex flex-wrap items-end justify-between gap-3">
            <h2 className={H2}>Post to {cfg.name} and everywhere else</h2>
            <Link to={CHANNELS_PATH} className="inline-flex items-center gap-1 text-[14px] font-bold text-vm-red">All channels <ArrowRight className="h-4 w-4" aria-hidden /></Link>
          </div>
          <ul className="mt-8 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            {others.map((o) => (
              <li key={o.key}>
                <Link to={o.slug} className={`${CARD} flex h-full items-center gap-3 p-4 transition-[border-color,box-shadow] hover:border-ink-on-paper-1 hover:shadow-[4px_4px_0_0_var(--vm-red)]`}>
                  <ChannelTile cfg={o} size={40} />
                  <span className="min-w-0">
                    <span className="block font-bold">{o.name}</span>
                    <span className="block truncate text-[12.5px] text-ink-on-paper-2">{o.card}</span>
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        </section>

        {/* CTA */}
        <section className="mt-20 rounded-[24px] bg-ink-900 px-6 py-10 text-center text-white sm:px-10 sm:py-14">
          <h2 className="font-display text-[clamp(26px,4vw,40px)] font-extrabold leading-[1.05] tracking-[-0.025em] [text-wrap:balance]">
            Schedule your next {cfg.name} post and see what it sells.
          </h2>
          <p className="mx-auto mt-4 max-w-[520px] text-[16px] leading-[1.5] text-white/70">Connect {cfg.name}, queue the week, and watch the revenue per post come in.</p>
          <Link to={SIGNUP} className={`${PRIMARY_BTN} mt-8 shadow-none`}>Start for $0</Link>
        </section>
      </main>

      <LandingFooter />
    </div>
  );
}
