// /channels: every posting channel ViewsMax schedules to, each linking to its
// own guide (src/lib/channel-pages.ts). Prerendered at build time.
import { Link } from "react-router-dom";
import { ArrowRight } from "lucide-react";
import { LandingNav, LandingFooter } from "@/pages/landing/LandingChrome";
import { CARD, PRIMARY_BTN } from "@/components/agents/primitives";
import { useSeo } from "@/hooks/useSeo";
import { CHANNEL_LIST, CHANNELS_HUB_SEO, CHANNELS_PATH, buildChannelsHubJsonLd } from "@/lib/channel-pages";
import { ChannelTile } from "./ChannelPage";

const SIGNUP = "/auth?tab=signup";

export default function ChannelsHub() {
  useSeo({ ...CHANNELS_HUB_SEO, canonicalPath: CHANNELS_PATH, ogImage: "https://viewsmax.com/og-image.png", jsonLd: buildChannelsHubJsonLd() });

  return (
    <div data-prerender-ready className="flex min-h-screen flex-col bg-paper-1 font-body text-ink-on-paper-1">
      <LandingNav />
      <main className="mx-auto w-full max-w-[1040px] flex-1 px-4 pb-20 pt-10 sm:px-6 sm:pt-14">
        <div className="flex flex-col items-center text-center">
          <span className="inline-flex items-center rounded-full border border-line-1 bg-paper-0 px-3.5 py-[7px] text-xs font-bold text-ink-on-paper-2">
            {CHANNEL_LIST.length} channels · one calendar
          </span>
          <h1 className="mb-4 mt-[22px] font-display text-[clamp(34px,5vw,56px)] font-extrabold leading-[1.02] tracking-[-0.03em] [text-wrap:balance]">
            Every channel you post to, scheduled from one place
          </h1>
          <p className="max-w-[660px] text-[19px] leading-[1.5] text-ink-on-paper-2 [text-wrap:pretty]">
            Connect the accounts once. Write a post, pick the channels, and ViewsMax trims each version to the channel's own limits, publishes on time and reports the sales back per post.
          </p>
          <Link to={SIGNUP} className={`${PRIMARY_BTN} mt-8`}>Start for $0</Link>
        </div>

        <ul className="mt-14 grid gap-4 sm:grid-cols-2">
          {CHANNEL_LIST.map((c) => (
            <li key={c.key}>
              <Link to={c.slug} className={`${CARD} group flex h-full gap-4 p-5 transition-[border-color,box-shadow] hover:border-ink-on-paper-1 hover:shadow-[4px_4px_0_0_var(--vm-red)]`}>
                <ChannelTile cfg={c} size={48} />
                <span className="min-w-0 flex-1">
                  <span className="flex items-baseline justify-between gap-3">
                    <span className="font-display text-[19px] font-extrabold tracking-[-0.02em]">{c.name}</span>
                    <span className="shrink-0 text-xs font-semibold text-ink-on-paper-3">{c.captionLimit.toLocaleString("en-US")} chars</span>
                  </span>
                  <span className="mt-1 block text-sm leading-[1.45] text-ink-on-paper-2">{c.card}</span>
                  <span className="mt-3 flex flex-wrap gap-1.5">
                    {c.formats.map((f) => <span key={f} className="rounded-full border border-line-1 bg-paper-1 px-2.5 py-0.5 text-[12px] font-semibold text-ink-on-paper-2">{f}</span>)}
                  </span>
                  <span className="mt-3 inline-flex items-center gap-1 text-[13px] font-bold text-vm-red">{c.name} scheduler <ArrowRight className="h-3.5 w-3.5 transition-transform group-hover:translate-x-0.5" aria-hidden /></span>
                </span>
              </Link>
            </li>
          ))}
        </ul>

        <section className="mt-16 grid gap-4 sm:grid-cols-3">
          {[
            ["One post, every channel", "Pick the channels when you schedule. Each version is checked against that channel's caption and media rules before it leaves."],
            ["Revenue per post", "Tracked links attribute clicks, sign-ups, booked calls and purchases to the post and channel that caused them."],
            ["Works with your AI", "Claude, ChatGPT, Cursor and other agents schedule and publish through the ViewsMax MCP server and REST API."],
          ].map(([t, d]) => (
            <article key={t} className={`${CARD} p-6`}>
              <h2 className="font-display text-[19px] font-bold leading-tight tracking-[-0.01em]">{t}</h2>
              <p className="mt-2 text-[15px] leading-[1.5] text-ink-on-paper-2">{d}</p>
            </article>
          ))}
        </section>
      </main>
      <LandingFooter />
    </div>
  );
}
