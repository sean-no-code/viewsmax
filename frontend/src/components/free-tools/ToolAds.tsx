// ViewsMax house ads for the free tools. On wide screens (≥1240px) they sit in
// the sticky rails either side of the tool (AdRail). Below that the rails are
// hidden and the ads ride along with the transcript instead, as compact rows
// (AdStrip) the reader meets on the way down.
import type { CSSProperties } from "react";
import { Link } from "react-router-dom";
import { ArrowRight, Bot, CalendarCheck, Share2, TrendingUp, type LucideIcon } from "lucide-react";

export interface ToolAd {
  icon: LucideIcon;
  title: string;
  body: string;
  cta: string;
  href: string;
  /** Pastel card colours from the design: surface, hairline, and icon/CTA accent. */
  bg: string;
  border: string;
  accent: string;
}

/** `{platform}` in a title is replaced with the current tool's platform name. */
export const TOOL_ADS: ToolAd[] = [
  {
    icon: TrendingUp,
    title: "Grow your {platform} with outliers",
    body: "Spot the videos beating a channel’s average by 10x and see exactly why.",
    cta: "Find outliers",
    href: "/dashboard/outliers",
    bg: "#FFE7EA",
    border: "#FFC9D1",
    accent: "#D60B27",
  },
  {
    icon: CalendarCheck,
    title: "Grow your {platform} with scheduling and automation",
    body: "Queue a month of posts and let ViewsMax publish at your best hours.",
    cta: "Set up a schedule",
    href: "/dashboard/post",
    bg: "#FFF3D6",
    border: "#F5DFA6",
    accent: "#8A5A00",
  },
  {
    icon: Bot,
    title: "Use ViewsMax with your AI agents",
    body: "Post, track offers and research outliers from Claude, ChatGPT or any MCP client.",
    cta: "Connect an agent",
    href: "/mcp",
    bg: "#E9E6FF",
    border: "#CFC9F7",
    accent: "#4B3FBF",
  },
  {
    icon: Share2,
    title: "Grow your social media accounts",
    body: "YouTube, Instagram and X, tracked in one dashboard with the same trend engine.",
    cta: "Grow every channel",
    href: "/#channels",
    bg: "#D9F8F2",
    border: "#A9EBDD",
    accent: "#0B7F70",
  },
];

/** The card's pastel palette, handed to Tailwind as custom properties. */
const adVars = (ad: ToolAd) =>
  ({ "--ad-bg": ad.bg, "--ad-border": ad.border, "--ad-accent": ad.accent }) as CSSProperties;

export function AdCard({ ad, platform }: { ad: ToolAd; platform: string }) {
  const Icon = ad.icon;
  return (
    <Link
      to={ad.href}
      style={adVars(ad)}
      className="flex flex-col items-center gap-2.5 rounded-[18px] border border-[color:var(--ad-border)] bg-[color:var(--ad-bg)] px-[18px] py-6 text-center text-ink-on-paper-1 transition-[box-shadow,transform,border-color] duration-200 ease-[cubic-bezier(.2,.7,.2,1)] hover:-translate-x-0.5 hover:-translate-y-0.5 hover:border-ink-on-paper-1 hover:shadow-[4px_4px_0_0_var(--vm-red)]"
    >
      <span className="flex h-12 w-12 items-center justify-center rounded-xl bg-paper-0 text-[color:var(--ad-accent)]">
        <Icon className="h-6 w-6" strokeWidth={2} aria-hidden />
      </span>
      <span className="font-display text-[17px] font-extrabold leading-[1.15] tracking-[-0.02em] [text-wrap:balance]">
        {ad.title.replace("{platform}", platform)}
      </span>
      <span className="text-[13.5px] leading-[1.45] text-ink-on-paper-2 [text-wrap:pretty]">{ad.body}</span>
      <span className="mt-1 inline-flex items-center gap-1 text-[13px] font-bold text-[color:var(--ad-accent)]">
        {ad.cta} <ArrowRight className="h-3.5 w-3.5" aria-hidden />
      </span>
    </Link>
  );
}

/** Compact horizontal variant used by the mobile/tablet strip above the tool. */
export function AdRow({ ad, platform }: { ad: ToolAd; platform: string }) {
  const Icon = ad.icon;
  return (
    <Link
      to={ad.href}
      style={adVars(ad)}
      className="flex min-h-[44px] items-center gap-3.5 rounded-[18px] border border-[color:var(--ad-border)] bg-[color:var(--ad-bg)] p-4 text-left text-ink-on-paper-1 transition-transform duration-[120ms] active:translate-y-px"
    >
      <span className="flex h-11 w-11 flex-none items-center justify-center rounded-xl bg-paper-0 text-[color:var(--ad-accent)]">
        <Icon className="h-[22px] w-[22px]" strokeWidth={2} aria-hidden />
      </span>
      <span className="flex min-w-0 flex-col gap-[3px]">
        <span className="font-display text-[15.5px] font-extrabold leading-[1.15] tracking-[-0.02em] [text-wrap:balance]">
          {ad.title.replace("{platform}", platform)}
        </span>
        <span className="text-[13px] leading-[1.4] text-ink-on-paper-2 [text-wrap:pretty]">{ad.body}</span>
        <span className="text-[13px] font-bold text-[color:var(--ad-accent)]">{ad.cta} →</span>
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

/**
 * One or more ads placed in the mobile reading flow — only below 1240px, where
 * the side rails are hidden. A single ad spans the full width; several pair up
 * once there's room for two columns.
 */
export function AdStrip({ ads, platform, className = "" }: { ads: ToolAd[]; platform: string; className?: string }) {
  if (!ads.length) return null;
  return (
    <div
      className={`grid w-full max-w-[740px] grid-cols-1 gap-3 min-[1240px]:hidden ${
        ads.length > 1 ? "min-[720px]:grid-cols-2" : ""
      } ${className}`}
    >
      {ads.map((ad) => (
        <AdRow key={ad.cta} ad={ad} platform={platform} />
      ))}
    </div>
  );
}
