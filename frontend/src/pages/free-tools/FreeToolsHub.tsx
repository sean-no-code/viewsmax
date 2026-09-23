import { Link } from "react-router-dom";
import { Youtube, Instagram, Music2, Calculator, Image, ArrowRight, type LucideIcon } from "lucide-react";
import { LandingNav, LandingFooter } from "@/pages/landing/LandingChrome";
import BookmarkBar from "@/components/free-tools/BookmarkBar";
import { useSeo } from "@/hooks/useSeo";
import { PLATFORM_LIST, HUB_SEO, type PlatformKey } from "@/lib/transcript-tools";

const ICONS: Record<PlatformKey, LucideIcon> = { youtube: Youtube, tiktok: Music2, instagram: Instagram };

const CARD =
  "group flex flex-col rounded-[18px] border border-line-1 bg-paper-0 p-6 text-ink-on-paper-1 transition-[box-shadow,transform,border-color] duration-200 ease-[cubic-bezier(.2,.7,.2,1)] hover:-translate-x-0.5 hover:-translate-y-0.5 hover:border-ink-on-paper-1 hover:shadow-[4px_4px_0_0_var(--vm-red)]";
const ICON_WRAP = "flex h-12 w-12 items-center justify-center rounded-xl bg-[color:var(--vm-red-tint-l)] text-vm-red";
const TITLE = "mt-4 font-display text-[19px] font-extrabold leading-[1.15] tracking-[-0.02em]";
const DESC = "mt-1.5 text-sm leading-[1.45] text-ink-on-paper-2";
const CTA = "mt-4 inline-flex items-center gap-1 text-[13px] font-bold text-vm-red";

export default function FreeToolsHub() {
  useSeo({ ...HUB_SEO, canonicalPath: "/free-tools", ogImage: "https://viewsmax.com/og-image.png" });

  return (
    <div data-prerender-ready className="flex min-h-screen flex-col bg-paper-1 font-body text-ink-on-paper-1">
      <LandingNav />
      <BookmarkBar />
      <main className="mx-auto w-full max-w-4xl flex-1 px-4 pb-20 pt-10 sm:px-6 sm:pt-12">
        <div className="flex flex-col items-center">
          <span className="inline-flex items-center gap-2 rounded-full border border-line-1 bg-paper-0 px-3.5 py-[7px] text-xs font-bold text-ink-on-paper-2">
            Free tools · No login
          </span>
          <h1 className="mb-4 mt-[22px] text-center font-display text-[clamp(34px,5vw,56px)] font-extrabold leading-[1.02] tracking-[-0.03em] [text-wrap:balance]">
            Free tools for creators
          </h1>
          <p className="max-w-[660px] text-center text-[19px] leading-[1.5] text-ink-on-paper-2 [text-wrap:pretty]">
            Fast, free, no-login tools to transcribe, plan, and grow your content.
          </p>
        </div>

        <div className="mt-10 grid gap-4 sm:grid-cols-2">
          {PLATFORM_LIST.map((p) => {
            const Icon = ICONS[p.key];
            return (
              <Link key={p.key} to={p.slug} className={CARD}>
                <div className={ICON_WRAP}><Icon className="h-6 w-6" aria-hidden /></div>
                <div className={TITLE}>{p.name} Transcript Generator</div>
                <div className={DESC}>Turn any {p.name} video into text — copy or download, free.</div>
                <span className={CTA}>Open tool <ArrowRight className="h-3.5 w-3.5" aria-hidden /></span>
              </Link>
            );
          })}
          <Link to="/youtube-monetization-calculator" className={CARD}>
            <div className={ICON_WRAP}><Calculator className="h-6 w-6" aria-hidden /></div>
            <div className={TITLE}>YouTube Revenue Calculator</div>
            <div className={DESC}>Estimate your channel's earnings and 12-month growth.</div>
            <span className={CTA}>Open tool <ArrowRight className="h-3.5 w-3.5" aria-hidden /></span>
          </Link>
          <Link to="/thumbnail-preview" className={CARD}>
            <div className={ICON_WRAP}><Image className="h-6 w-6" aria-hidden /></div>
            <div className={TITLE}>Thumbnail Preview</div>
            <div className={DESC}>See your thumbnail on YouTube's home page before you publish.</div>
            <span className={CTA}>Open tool <ArrowRight className="h-3.5 w-3.5" aria-hidden /></span>
          </Link>
        </div>
      </main>
      <LandingFooter />
    </div>
  );
}
