// Analytics (Funnl) data model.
// Maps the existing tracking API (tracking_events / tracking_links / clicks /
// conversions) into the shapes the Analytics views render. The backend already
// rolls up per-event and per-link metrics in GET /api/tracking-events; this
// module flattens + groups them by platform and formats values.

import { API_BASE_URL, type ReferrerStat, type TrackingEvent, type TrackingLink, type GoalType } from "@/lib/api-service";

/* ---------- formatting helpers (ported from the design) ---------- */
export const fmtMoney = (n: number, dp = 0) =>
  "$" + (n || 0).toLocaleString("en-US", { minimumFractionDigits: dp, maximumFractionDigits: dp });
export const fmtMoneyK = (n: number) =>
  n >= 1000 ? "$" + (n / 1000).toFixed(n >= 10000 ? 1 : 2).replace(/\.0$/, "") + "K" : "$" + (n || 0);
export const fmtNum = (n: number) =>
  n >= 1000 ? (n / 1000).toFixed(n >= 10000 ? 0 : 1).replace(/\.0$/, "") + "K" : String(n || 0);
export const fmtFull = (n: number) => (n || 0).toLocaleString("en-US");
export const fmtPct = (n: number, dp = 2) => (n || 0).toFixed(dp) + "%";

/* ---------- platform (placement) metadata ---------- */
// Backend `placement` values → display name, brand colour (data-viz palette),
// and the glyph id PlatformGlyph knows how to draw.
export interface PlatformMeta {
  name: string;
  color: string;
  glyph: string; // youtube | email | instagram | tiktok | x | facebook | generic
}

export const PLACEMENT_META: Record<string, PlatformMeta> = {
  video: { name: "YouTube", color: "#FF1F3D", glyph: "youtube" },
  email: { name: "Email", color: "#FFB020", glyph: "email" },
  instagram: { name: "Instagram", color: "#FF6BB3", glyph: "instagram" },
  tiktok: { name: "TikTok", color: "#5A53E0", glyph: "tiktok" },
  x: { name: "X / Twitter", color: "#0A0A0C", glyph: "x" },
  linkedin: { name: "LinkedIn", color: "#4D8DFF", glyph: "generic" },
  facebook: { name: "Facebook", color: "#4D8DFF", glyph: "facebook" },
  blog: { name: "Blog", color: "#9B6BFF", glyph: "generic" },
  website: { name: "Website", color: "#16E0C4", glyph: "generic" },
  podcast: { name: "Podcast", color: "#5A53E0", glyph: "generic" },
  ad: { name: "Ads", color: "#FFB020", glyph: "generic" },
  beehiiv: { name: "Beehiiv", color: "#2551F5", glyph: "generic" },
  other: { name: "Other", color: "#76767F", glyph: "generic" },
};

export const platformMeta = (placement: string): PlatformMeta =>
  PLACEMENT_META[placement] || { name: placement || "Other", color: "#76767F", glyph: "generic" };

/* ---------- view-model shapes ---------- */
export interface PlatformMetric {
  id: string; // placement key
  name: string;
  color: string;
  glyph: string;
  views: number;
  viewsSince: number; // reach gained since the link(s) were created
  clicks: number;
  visitors: number;
  topReferrers: ReferrerStat[];
  calls: number;
  emails: number;
  conv: number;
  rev: number;
  cr: number; // click-based: conversions / clicks
  viewCr: number | null; // reach-based: conversions / viewsSince (null = no reach data)
  epc: number;
  aov: number;
}

export interface ConvGoal {
  url: string;
  value: number;
  label: string;
}

// A single conversion goal in editable form (drives the Conversion-events modal).
export interface GoalItem {
  id: number | null; // null = not yet persisted
  path: string; // conversion_url
  type: string; // backend event_type (conversion | call booked | email-signup)
  value: number; // conversion_value
}

export interface PageMetric {
  id: string;
  name: string;
  url: string; // offer_url
  live: boolean;
  conv: ConvGoal;
  goals: GoalItem[];
  visits: number;
  clicks: number; // total tracking-link clicks across the offer
  conversions: number;
  rev: number;
  cr: number;
  viewsSince: number; // total reach across the offer's videos since link creation
  viewCr: number | null; // reach-based conversion rate (null = no reach data)
  platforms: string[]; // unique placements of the offer's links (index logos)
  createdAt: string | null;
  raw: TrackingEvent;
}

export interface LinkMetric {
  id: string;
  label: string;
  contentTitle: string | null; // linked content's title (video/newsletter/tweet)
  createdAt: string | null;
  platform: string; // placement key
  page: string; // event id (string)
  short: string; // shareable tracking url
  detail: string; // "<Platform> · <description>"
  views: number; // impressions / video views on the placement
  viewsSince: number; // reach gained since the link was created (clean delta)
  totalViews: number; // the linked content's TOTAL views (what tables display)
  clicks: number;
  visitors: number; // distinct people behind the clicks
  topReferrers: ReferrerStat[]; // where clicks actually came from
  platformBreakdown: Record<string, number>; // clicks by inferred platform
  placements: string[]; // every declared placement (multi-platform links)
  calls: number; // call bookings
  emails: number; // email signups
  conv: number; // sales / conversions count
  rev: number;
  cr: number; // click-based: conversions / clicks
  viewCr: number | null; // reach-based: conversions / viewsSince (null = no reach data)
  epc: number;
  aov: number;
  lastClickAt: string | null;
  spark: number[];
  raw: TrackingLink;
}

export interface FunnlTotals {
  views: number;
  viewsSince: number;
  clicks: number;
  visitors: number;
  calls: number;
  emails: number;
  conv: number;
  rev: number;
  cr: number; // click-based
  viewCr: number | null; // reach-based (null = no reach data)
  epc: number;
  aov: number;
}

export interface FunnlModel {
  pages: PageMetric[];
  links: LinkMetric[];
  platforms: PlatformMetric[];
  totals: FunnlTotals;
  goalTypes: GoalType[]; // seeded reference list from GET /api/goal-types
}

const num = (v: unknown): number => {
  const n = typeof v === "string" ? parseFloat(v) : (v as number);
  return Number.isFinite(n) ? n : 0;
};

/* ---------- tracking url / snippet helpers ---------- */
const TRACKER_BASE = API_BASE_URL || "https://api.viewsmax.ai";

export const buildTrackingUrl = (offerUrl: string, parameterId: string): string => {
  const base = (offerUrl || "").replace(/^https?:\/\//, "").replace(/\/$/, "");
  return `${base}?trk=${parameterId}`;
};

export const buildSnippet = (publicId: string): string =>
  `<!-- ViewsMax Universal Tracking -->
<meta name="viewsmax-user" content="${publicId || "YOUR_PUBLIC_ID"}">
<script src="${TRACKER_BASE}/tracker.js" defer></script>
<!-- End ViewsMax -->`;

/* ---------- mappers ---------- */
function mapPage(e: TrackingEvent, labels: Map<string, string>): PageMetric {
  const goal = e.goals && e.goals.length ? e.goals[0] : undefined;
  const conversions = num(e.conversions_count);
  const visits = num(e.total_video_views) || num(e.total_clicks);
  const rev = num(e.sales_amount);
  return {
    id: String(e.id),
    name: e.name || e.offer_url || "Untitled offer",
    url: (e.offer_url || "").replace(/^https?:\/\//, ""),
    live: num(e.total_clicks) > 0,
    conv: {
      url: goal?.conversion_url || "/thank-you",
      value: num(goal?.conversion_value) || num(e.conversion_value),
      label: goal?.event_type ? goalLabel(goal.event_type, labels) : "Conversion",
    },
    goals: (e.goals || []).map((g) => ({
      id: g.id ?? null,
      path: g.conversion_url || "",
      type: g.event_type || "conversion",
      value: num(g.conversion_value),
    })),
    visits,
    clicks: num(e.total_clicks),
    conversions,
    rev,
    cr: visits ? (conversions / visits) * 100 : 0,
    viewsSince: num(e.total_video_views),
    viewCr: e.view_conversion_rate ?? null,
    platforms: [...new Set((e.links || []).map((l) => l.placement || "other"))],
    createdAt: e.created_at ?? null,
    raw: e,
  };
}

// event_type -> display label. The known types come from the DB (GET /api/goal-types),
// passed in as `labels`. Anything outside it is a user-defined custom event: we echo
// its own name (de-slugged + title-cased).
export function goalLabel(eventType: string, labels?: Map<string, string>): string {
  if (!eventType) return "Purchase";
  const known = labels?.get(eventType);
  if (known) return known;
  return eventType.replace(/[-_]+/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
}

// Build the event_type -> label lookup from the seeded goal-types list.
export const goalLabelMap = (goalTypes: GoalType[] = []): Map<string, string> =>
  new Map(goalTypes.map((t) => [t.value, t.label]));

function mapLink(l: TrackingLink, event: TrackingEvent): LinkMetric {
  const meta = platformMeta(l.placement);
  const clicks = num(l.clicks_count);
  const conv = num(l.conversions_count);
  const rev = num(l.sales_amount);
  return {
    id: String(l.id),
    label: l.name || l.description || `${meta.name} link`,
    contentTitle: l.content_title ?? null,
    createdAt: l.created_at ?? null,
    platform: l.placement || "other",
    page: String(l.tracking_event_id ?? event.id),
    short: buildTrackingUrl(event.offer_url, l.parameter_id),
    detail: `${meta.name}${l.description ? " · " + l.description : ""}`,
    views: num(l.views) || num(l.initial_view_count),
    viewsSince: num(l.views), // clean delta only (no initial-count fallback) for reach CR
    totalViews: num(l.total_views) || num(l.current_view_count) || num(l.initial_view_count),
    clicks,
    visitors: num(l.visitors_count),
    topReferrers: l.top_referrers ?? [],
    platformBreakdown: l.platform_breakdown ?? {},
    placements: l.placements?.length ? l.placements : (l.placement ? [l.placement] : []),
    calls: num(l.calls_booked_count),
    emails: num(l.email_signups_count),
    conv,
    rev,
    cr: clicks ? (conv / clicks) * 100 : 0,
    viewCr: l.view_conversion_rate ?? null,
    epc: clicks ? rev / clicks : 0,
    aov: conv ? rev / conv : 0,
    lastClickAt: l.last_click_at ?? null,
    spark: l.clicks_spark || [],
    raw: l,
  };
}

/** Roll a (possibly filtered) link set up into per-platform metrics. */
export function platformsFromLinks(links: LinkMetric[]): PlatformMetric[] {
  const byPlatform = new Map<string, PlatformMetric>();
  for (const l of links) {
    const meta = platformMeta(l.platform);
    const p =
      byPlatform.get(l.platform) ||
      ({ id: l.platform, name: meta.name, color: meta.color, glyph: meta.glyph, views: 0, viewsSince: 0, clicks: 0, visitors: 0, topReferrers: [], calls: 0, emails: 0, conv: 0, rev: 0, cr: 0, viewCr: null, epc: 0, aov: 0 } as PlatformMetric);
    p.views += l.views;
    p.viewsSince += l.viewsSince;
    p.clicks += l.clicks;
    p.visitors += l.visitors;
    p.calls += l.calls;
    p.emails += l.emails;
    p.conv += l.conv;
    p.rev += l.rev;
    // Merge referrer stats across the platform's links (top 3 by count).
    const merged = new Map(p.topReferrers.map((r) => [r.host, { ...r }]));
    for (const r of l.topReferrers) {
      const cur = merged.get(r.host);
      if (cur) cur.count += r.count;
      else merged.set(r.host, { ...r });
    }
    p.topReferrers = [...merged.values()].sort((a, b) => b.count - a.count).slice(0, 3);
    byPlatform.set(l.platform, p);
  }
  return Array.from(byPlatform.values()).map((p) => ({
    ...p,
    cr: p.clicks ? (p.conv / p.clicks) * 100 : 0,
    // Reach CR only where the platform actually has view data; else null → "—".
    viewCr: p.viewsSince > 0 ? (p.conv / p.viewsSince) * 100 : null,
    epc: p.clicks ? p.rev / p.clicks : 0,
    aov: p.conv ? p.rev / p.conv : 0,
  }));
}

/** Sum a (possibly filtered) link set into top-line totals. */
export function totalsFromLinks(links: LinkMetric[]): FunnlTotals {
  const sum = (k: keyof LinkMetric) => links.reduce((s, l) => s + (l[k] as number), 0);
  const views = sum("views"), clicks = sum("clicks"), calls = sum("calls");
  const emails = sum("emails"), conv = sum("conv"), rev = sum("rev");
  const viewsSince = sum("viewsSince");
  // Per-link distinct visitors summed — a person clicking two different links
  // counts once per link, which keeps totals consistent under filtering.
  const visitors = sum("visitors");
  return {
    views, viewsSince, clicks, visitors, calls, emails, conv, rev,
    cr: clicks ? (conv / clicks) * 100 : 0,
    viewCr: viewsSince > 0 ? (conv / viewsSince) * 100 : null,
    epc: clicks ? rev / clicks : 0,
    aov: conv ? rev / conv : 0,
  };
}

/**
 * Transform the raw tracking-events list (with injected aggregates) into the
 * Funnl view model: landing pages, flattened links, platform rollups, totals.
 */
export function toFunnlModel(events: TrackingEvent[], goalTypes: GoalType[] = []): FunnlModel {
  const labels = goalLabelMap(goalTypes);
  const pages = events.map((e) => mapPage(e, labels));

  const links: LinkMetric[] = [];
  for (const e of events) {
    for (const l of e.links || []) links.push(mapLink(l, e));
  }

  const platforms = platformsFromLinks(links);
  const totals = totalsFromLinks(links);

  return { pages, links, platforms, totals, goalTypes };
}
