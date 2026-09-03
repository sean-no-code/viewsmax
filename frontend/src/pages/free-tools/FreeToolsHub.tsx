import { Link } from "react-router-dom";
import { Youtube, Instagram, Music2, Calculator, Image, type LucideIcon } from "lucide-react";
import { LandingNav, LandingFooter } from "@/pages/landing/LandingChrome";
import { useSeo } from "@/hooks/useSeo";
import { PLATFORM_LIST, HUB_SEO, type PlatformKey } from "@/lib/transcript-tools";

const ICONS: Record<PlatformKey, LucideIcon> = { youtube: Youtube, tiktok: Music2, instagram: Instagram };

export default function FreeToolsHub() {
  useSeo({ ...HUB_SEO, canonicalPath: "/free-tools", ogImage: "https://viewsmax.com/og-image.png" });

  const card = "rounded-2xl border bg-card p-6 transition hover:shadow-md";
  const iconWrap = "flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10 text-primary";

  return (
    <div data-prerender-ready className="min-h-screen bg-background flex flex-col">
      <LandingNav />
      <main className="mx-auto max-w-4xl flex-1 px-4 py-16">
        <h1 className="text-center text-4xl font-bold tracking-tight text-foreground">Free tools for creators</h1>
        <p className="mx-auto mt-4 max-w-2xl text-center text-lg text-muted-foreground">
          Fast, free, no-login tools to transcribe, plan, and grow your content.
        </p>

        <div className="mt-10 grid gap-5 sm:grid-cols-2">
          {PLATFORM_LIST.map((p) => {
            const Icon = ICONS[p.key];
            return (
              <Link key={p.key} to={p.slug} className={card}>
                <div className={iconWrap}><Icon className="h-5 w-5" /></div>
                <div className="mt-4 text-lg font-bold text-foreground">{p.name} Transcript Generator</div>
                <div className="mt-1 text-sm text-muted-foreground">Turn any {p.name} video into text — copy or download, free.</div>
              </Link>
            );
          })}
          <Link to="/youtube-monetization-calculator" className={card}>
            <div className={iconWrap}><Calculator className="h-5 w-5" /></div>
            <div className="mt-4 text-lg font-bold text-foreground">YouTube Revenue Calculator</div>
            <div className="mt-1 text-sm text-muted-foreground">Estimate your channel's earnings and 12-month growth.</div>
          </Link>
          <Link to="/thumbnail-preview" className={card}>
            <div className={iconWrap}><Image className="h-5 w-5" /></div>
            <div className="mt-4 text-lg font-bold text-foreground">Thumbnail Preview</div>
            <div className="mt-1 text-sm text-muted-foreground">See your thumbnail on YouTube's home page before you publish.</div>
          </Link>
        </div>
      </main>
      <LandingFooter />
    </div>
  );
}
