// ViewsMax house ads shown either side of the free tools on wide screens
// (≥1240px). Below that the rails are hidden, matching the design.
import { Link } from "react-router-dom";
import { ArrowRight, Bot, CalendarCheck, Share2, TrendingUp, type LucideIcon } from "lucide-react";

export interface ToolAd {
  icon: LucideIcon;
  title: string;
  body: string;
  cta: string;
  href: string;
}

/** `{platform}` in a title is replaced with the current tool's platform name. */
export const TOOL_ADS: ToolAd[] = [
  {
    icon: TrendingUp,
    title: "Grow your {platform} with outliers",
    body: "Spot the videos beating a channel’s average by 10x and see exactly why.",
    cta: "Find outliers",
    href: "/dashboard/outliers",
  },
  {
    icon: CalendarCheck,
    title: "Grow your {platform} with scheduling and automation",
    body: "Queue a month of posts and let ViewsMax publish at your best hours.",
    cta: "Set up a schedule",
    href: "/dashboard/post",
  },
  {
    icon: Bot,
    title: "Use ViewsMax with your AI agents",
    body: "Plug analytics, transcripts and trends into Claude, ChatGPT or any MCP-ready agent.",
    cta: "Connect an agent",
    href: "/ai",
  },
  {
    icon: Share2,
    title: "Grow your social media accounts",
    body: "YouTube, Instagram and X, tracked in one dashboard with the same trend engine.",
    cta: "Grow every channel",
    href: "/#channels",
  },
];

export function AdCard({ ad, platform }: { ad: ToolAd; platform: string }) {
  const Icon = ad.icon;
  return (
    <Link
      to={ad.href}
      className="flex flex-col items-center gap-2.5 rounded-[18px] border border-line-1 bg-paper-0 px-[18px] py-6 text-center text-ink-on-paper-1 transition-[box-shadow,transform,border-color] duration-200 ease-[cubic-bezier(.2,.7,.2,1)] hover:-translate-x-0.5 hover:-translate-y-0.5 hover:border-ink-on-paper-1 hover:shadow-[4px_4px_0_0_var(--vm-red)]"
    >
      <span className="flex h-12 w-12 items-center justify-center rounded-xl bg-[color:var(--vm-red-tint-l)] text-vm-red">
        <Icon className="h-6 w-6" strokeWidth={2} aria-hidden />
      </span>
      <span className="font-display text-[17px] font-extrabold leading-[1.15] tracking-[-0.02em] [text-wrap:balance]">
        {ad.title.replace("{platform}", platform)}
      </span>
      <span className="text-[13.5px] leading-[1.45] text-ink-on-paper-2 [text-wrap:pretty]">{ad.body}</span>
      <span className="mt-1 inline-flex items-center gap-1 text-[13px] font-bold text-vm-red">
        {ad.cta} <ArrowRight className="h-3.5 w-3.5" aria-hidden />
      </span>
    </Link>
  );
}

export function AdRail({ ads, platform, side }: { ads: ToolAd[]; platform: string; side: "left" | "right" }) {
  return (
    <aside
      aria-label="ViewsMax features"
      className={`sticky top-[92px] hidden w-full max-w-[280px] flex-col gap-4 min-[1240px]:flex ${side === "left" ? "justify-self-end" : ""}`}
    >
      {ads.map((ad) => (
        <AdCard key={ad.cta} ad={ad} platform={platform} />
      ))}
    </aside>
  );
}
