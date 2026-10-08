// Analytics → Profile / Post performance: view-model over GET /api/analytics/performance.
//
// The API returns, per connected account, a per-day follower count and per-day
// engagement growth for the previous period followed by the current one
// (`days` has 2×L entries). Everything on the two pages is derived here from
// that payload plus the posts published in the range.
import type { PerformanceAccount, PerformanceData, PerformancePost } from "@/lib/api-service";

/* ---------- networks ---------- */

export interface Network {
  id: string;
  name: string;
  /** Chart colour (design-system data-viz token). */
  color: string;
  /** Brand colour for the small platform badge. */
  badge: string;
}

const NETWORK_LIST: Network[] = [
  { id: "youtube", name: "YouTube", color: "var(--data-red)", badge: "#FF0033" },
  { id: "tiktok", name: "TikTok", color: "var(--data-indigo)", badge: "#111111" },
  { id: "instagram", name: "Instagram", color: "var(--data-pink)", badge: "#E1306C" },
  { id: "linkedin", name: "LinkedIn", color: "var(--data-blue)", badge: "#0A66C2" },
  { id: "x", name: "X", color: "var(--ink-on-paper-2)", badge: "#000000" },
  { id: "threads", name: "Threads", color: "var(--data-violet)", badge: "#000000" },
  { id: "facebook", name: "Facebook", color: "var(--data-teal)", badge: "#1877F2" },
  { id: "bluesky", name: "Bluesky", color: "var(--data-amber)", badge: "#0085FF" },
  { id: "google_business", name: "Google Business", color: "var(--data-blue)", badge: "#4285F4" },
];
export const NETWORKS: Record<string, Network> = Object.fromEntries(NETWORK_LIST.map((n) => [n.id, n]));
export const network = (platform: string): Network =>
  NETWORKS[platform] ?? { id: platform, name: platform, color: "var(--ink-on-paper-3)", badge: "#76767F" };

/* ---------- dates ---------- */

const MON = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];

export const startOfDay = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate());
export const addDays = (d: Date, n: number) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; };
export const sameDay = (a?: Date, b?: Date) => !!a && !!b && a.toDateString() === b.toDateString();
/** "Oct 5" */
export const dayLabel = (d: Date) => `${MON[d.getMonth()]} ${d.getDate()}`;
/** "Oct 5, 2026" */
export const dayLabelYear = (d: Date) => `${dayLabel(d)}, ${d.getFullYear()}`;
/** YYYY-MM-DD in local time (URL state + API params). */
export const toISODate = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
export const fromISODate = (s: string | null): Date | null => {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s ?? "");
  if (!m) return null;
  const d = new Date(Number(m[1]), Number(m[2]) - 1, Number(m[3]));
  return Number.isNaN(d.getTime()) ? null : d;
};

export interface DateRange { from: Date; to: Date }

export type PresetId = "7d" | "28d" | "90d" | "tm" | "lm" | "ytd";
export const PRESETS: { id: PresetId; label: string }[] = [
  { id: "7d", label: "Last 7 days" },
  { id: "28d", label: "Last 28 days" },
  { id: "90d", label: "Last 90 days" },
  { id: "tm", label: "This month" },
  { id: "lm", label: "Last month" },
  { id: "ytd", label: "Year to date" },
];
export const DEFAULT_PRESET: PresetId = "28d";

export function presetRange(id: PresetId, end: Date): DateRange {
  const e = startOfDay(end);
  switch (id) {
    case "7d": return { from: addDays(e, -6), to: e };
    case "28d": return { from: addDays(e, -27), to: e };
    case "90d": return { from: addDays(e, -89), to: e };
    case "tm": return { from: new Date(e.getFullYear(), e.getMonth(), 1), to: e };
    case "lm": return { from: new Date(e.getFullYear(), e.getMonth() - 1, 1), to: new Date(e.getFullYear(), e.getMonth(), 0) };
    case "ytd": return { from: new Date(e.getFullYear(), 0, 1), to: e };
  }
}

/** The preset a range corresponds to, if any. */
export function presetFor(range: DateRange, end: Date): PresetId | null {
  return PRESETS.find((p) => { const r = presetRange(p.id, end); return sameDay(r.from, range.from) && sameDay(r.to, range.to); })?.id ?? null;
}

/** "Oct 5 – Nov 2, 2026" (year on the start only when it differs). */
export function rangeLabel(range: DateRange): string {
  const start = range.from.getFullYear() !== range.to.getFullYear() ? dayLabelYear(range.from) : dayLabel(range.from);
  return `${start} – ${dayLabelYear(range.to)}`;
}

/** Order a range and keep it inside [min, max]. */
export function clampRange(range: DateRange, min: Date, max: Date): DateRange {
  let from = startOfDay(range.from), to = startOfDay(range.to);
  if (from > to) [from, to] = [to, from];
  if (from < min) from = min;
  if (to > max) to = max;
  if (from > to) from = to;
  return { from, to };
}

/* ---------- formatting ---------- */

export function fmt(n: number): string {
  const a = Math.abs(n);
  if (a >= 1e6) return (n / 1e6).toFixed(1) + "M";
  if (a >= 1e4) return (n / 1e3).toFixed(1) + "K";
  return Math.round(n).toLocaleString("en-US");
}
export const signed = (n: number) => (n > 0 ? "+" : "") + fmt(n);
export const pct = (ratio: number) => (ratio * 100).toFixed(1) + "%";
/** engagements ÷ views as a percentage, or "–" when there are no views to divide by. */
export const rateLabel = (rate: number | null) => (rate === null ? "–" : pct(rate));

export interface Delta { label: string; dir: "up" | "down" | "flat" }
export function delta(cur: number | null, prev: number | null): Delta {
  if (cur === null || prev === null || !prev || !Number.isFinite(prev) || !Number.isFinite(cur)) return { label: "–", dir: "flat" };
  const p = ((cur - prev) / Math.abs(prev)) * 100;
  const up = p >= 0;
  return { label: (up ? "▲ " : "▼ ") + Math.abs(p).toFixed(1) + "%", dir: up ? "up" : "down" };
}

/* ---------- view model ---------- */

export interface Account {
  id: string;
  net: Network;
  handle: string;
  initials: string;
  avatarUrl: string | null;
  profileUrl: string | null;
  /** Avatar fallback tint. */
  tint: string;
  status: string;
  lastError: string | null;
  supported: boolean;
  followerError: string | null;
  postError: string | null;
  hasFollowerData: boolean;
  hasPostData: boolean;
}

export interface AccountSeries {
  /** Follower count per day (null before the first snapshot). */
  fol: (number | null)[];
  /** Followers gained per day (0 where either side is unknown). */
  net: number[];
  /** likes + comments + shares gained per day. */
  eng: number[];
  views: number[];
}
export interface History {
  /** 2×L days: the previous period, then the current one. */
  dates: Date[];
  L: number;
  acc: Record<string, AccountSeries>;
}

export interface PostStat {
  id: string;
  account: Account;
  net: Network;
  caption: string;
  url: string | null;
  date: Date;
  views: number;
  eng: number;
  /** engagements ÷ views, null when there are no views. */
  rate: number | null;
  likes: number;
  comments: number;
  shares: number;
}

export interface Performance { accounts: Account[]; hist: History; posts: PostStat[] }

const TINTS = ["var(--vm-volt-tint-l)", "var(--paper-3)", "var(--paper-2)"];
export const initialsOf = (handle: string) => {
  const words = handle.replace(/^@/, "").split(/[\s._-]+/).filter(Boolean);
  const s = words.length >= 2 ? words[0][0] + words[1][0] : (words[0] ?? "?").slice(0, 2);
  return s.toUpperCase();
};

function toAccount(a: PerformanceAccount, i: number, posts: PerformancePost[]): Account {
  const handle = a.username || a.name || a.platform;
  return {
    id: String(a.id),
    net: network(a.platform),
    handle,
    initials: initialsOf(handle),
    avatarUrl: a.avatar_url,
    profileUrl: a.profile_url,
    tint: TINTS[i % TINTS.length],
    status: a.status,
    lastError: a.last_error,
    supported: a.supported,
    followerError: a.follower_stats_error,
    postError: a.post_stats_error,
    hasFollowerData: a.followers.some((v) => v !== null),
    hasPostData: posts.some((p) => p.account_id === a.id) || a.engagement.likes.some(Boolean) || a.engagement.views.some(Boolean),
  };
}

export function fromApi(data: PerformanceData): Performance {
  const accounts = data.accounts.map((a, i) => toAccount(a, i, data.posts));
  const byId = Object.fromEntries(accounts.map((a) => [a.id, a]));
  const dates = data.days.map((d) => fromISODate(d) ?? new Date(d));
  const acc: Record<string, AccountSeries> = {};
  data.accounts.forEach((a) => {
    const fol = a.followers;
    acc[String(a.id)] = {
      fol,
      net: fol.map((v, i) => (i > 0 && v !== null && fol[i - 1] !== null ? v - (fol[i - 1] as number) : 0)),
      eng: a.engagement.likes.map((l, i) => l + a.engagement.comments[i] + a.engagement.shares[i]),
      views: a.engagement.views,
    };
  });
  const posts: PostStat[] = data.posts.flatMap((p) => {
    const account = byId[String(p.account_id)];
    if (!account) return [];
    const eng = p.likes + p.comments + p.shares;
    return [{
      id: p.id, account, net: account.net, caption: p.caption || `${account.net.name} post`, url: p.url,
      date: new Date(p.published_at), views: p.views, eng, rate: p.views > 0 ? eng / p.views : null,
      likes: p.likes, comments: p.comments, shares: p.shares,
    }];
  });
  return { accounts, hist: { dates, L: Math.floor(dates.length / 2), acc }, posts };
}

export const selectedAccounts = (accounts: Account[], sel: string[]) => accounts.filter((a) => sel.includes(a.id));
/** Networks with at least one selected account, in canonical order. */
export function selectedNetworks(accounts: Account[], sel: string[]): Network[] {
  const accs = selectedAccounts(accounts, sel);
  const present = new Set(accs.map((a) => a.net.id));
  const known = NETWORK_LIST.filter((n) => present.has(n.id));
  const extra = accs.map((a) => a.net).filter((n) => !NETWORKS[n.id]);
  return [...known, ...extra.filter((n, i) => extra.findIndex((m) => m.id === n.id) === i)];
}

/* ---------- derivations ---------- */

export interface Kpi { label: string; value: string; delta: Delta }
export interface FollowerRow { account: Account; net: Network; followers: string; growth: string; delta: Delta }
export interface EngagementRow { account: Account; net: Network; engagements: string; rate: string; /** 0..1 share of selected engagements */ share: number; delta: Delta }
export interface NetSeries { net: Network; values: number[] }

export interface ProfileData {
  /** The current period's days. */
  dates: Date[];
  kpis: Kpi[];
  /** Daily totals across the selected accounts (total is null on days with no follower data). */
  followers: { total: (number | null)[]; net: number[] };
  followerRows: FollowerRow[];
  engagement: { totals: number[]; byNet: NetSeries[] };
  engagementRows: EngagementRow[];
}

const sum = (arr: number[], from: number, to: number) => { let s = 0; for (let i = Math.max(0, from); i <= to; i++) s += arr[i] ?? 0; return s; };

export function profileData(hist: History, accounts: Account[], sel: string[]): ProfileData {
  const { L } = hist;
  const a0 = L, a1 = 2 * L - 1, p0 = 0;
  const accs = selectedAccounts(accounts, sel).filter((a) => hist.acc[a.id]);
  const nets = selectedNetworks(accounts, sel);
  const idx = Array.from({ length: L }, (_, i) => a0 + i);
  const dates = idx.map((i) => hist.dates[i]);
  const S = (id: string, k: "net" | "eng" | "views", from: number, to: number) => sum(hist.acc[id][k], from, to);
  const SA = (k: "net" | "eng" | "views", from: number, to: number, list = accs) => list.reduce((t, a) => t + S(a.id, k, from, to), 0);
  const day = (k: "net" | "eng" | "views", i: number, list = accs) => list.reduce((t, a) => t + (hist.acc[a.id][k][i] ?? 0), 0);
  const folAt = (i: number, list = accs): number | null => {
    if (i < 0) return null;
    const known = list.map((a) => hist.acc[a.id].fol[i]).filter((v): v is number => v !== null);
    return known.length ? known.reduce((t, v) => t + v, 0) : null;
  };

  const folEnd = folAt(a1);
  // Period-over-period comparisons only use accounts that already had data at
  // the end of the previous period, so an account whose snapshots start
  // mid-window doesn't show up as a follower spike.
  const established = accs.filter((a) => hist.acc[a.id].fol[a0 - 1] !== null);
  const folEndCmp = folAt(a1, established), folPrevEnd = folAt(a0 - 1, established);
  const netCur = SA("net", a0, a1), netPrev = SA("net", p0, a0 - 1);
  const netCurCmp = SA("net", a0, a1, established);
  const engCur = SA("eng", a0, a1), engPrev = SA("eng", p0, a0 - 1);
  const viewsCur = SA("views", a0, a1), viewsPrev = SA("views", p0, a0 - 1);
  const rateCur = viewsCur ? engCur / viewsCur : null, ratePrev = viewsPrev ? engPrev / viewsPrev : null;
  const kpis: Kpi[] = [
    { label: "Total followers", value: folEnd === null ? "–" : fmt(folEnd), delta: delta(folEndCmp, folPrevEnd) },
    { label: "Net follower growth", value: signed(netCur), delta: delta(netCurCmp, netPrev) },
    { label: "Engagements", value: fmt(engCur), delta: delta(engCur, engPrev) },
    { label: "Engagement rate (per view)", value: rateLabel(rateCur), delta: delta(rateCur, ratePrev) },
  ];

  const followers = { total: idx.map((i) => folAt(i)), net: idx.map((i) => day("net", i)) };
  const followerRows: FollowerRow[] = accs.map((a) => {
    const c = S(a.id, "net", a0, a1), p = S(a.id, "net", p0, a0 - 1);
    const end = hist.acc[a.id].fol[a1];
    return { account: a, net: a.net, followers: end === null ? "–" : fmt(end), growth: signed(c), delta: delta(c, p) };
  });

  const totals = idx.map((i) => day("eng", i));
  const byNet: NetSeries[] = nets.map((n) => {
    const list = accs.filter((a) => a.net.id === n.id);
    return { net: n, values: idx.map((i) => day("eng", i, list)) };
  });
  const engagementRows: EngagementRow[] = accs.map((a) => {
    const c = S(a.id, "eng", a0, a1), p = S(a.id, "eng", p0, a0 - 1), v = S(a.id, "views", a0, a1);
    return { account: a, net: a.net, engagements: fmt(c), rate: rateLabel(v ? c / v : null), share: engCur ? c / engCur : 0, delta: delta(c, p) };
  });

  return { dates, kpis, followers, followerRows, engagement: { totals, byNet }, engagementRows };
}

export type PostSort = "rate" | "eng" | "views" | "shares" | "date";
export const POST_SORTS: { id: PostSort; label: string }[] = [
  { id: "rate", label: "Engagement rate" },
  { id: "eng", label: "Engagements" },
  { id: "views", label: "Views" },
  { id: "shares", label: "Shares" },
  { id: "date", label: "Published date" },
];

/** Posts from the selected accounts, sorted (posts without a rate sort last). */
export function postsData(posts: PostStat[], sel: string[], sort: PostSort): PostStat[] {
  const out = posts.filter((p) => sel.includes(p.account.id));
  out.sort((p, q) => {
    if (sort === "date") return q.date.getTime() - p.date.getTime();
    if (sort === "rate") return (q.rate ?? -1) - (p.rate ?? -1);
    return q[sort] - p[sort];
  });
  return out;
}

/** Trigger label for the sources picker. */
export function sourcesLabel(accounts: Account[], sel: string[]): string {
  if (sel.length >= accounts.length) return "All sources";
  const accs = selectedAccounts(accounts, sel);
  const nets = selectedNetworks(accounts, sel);
  if (accs.length === 1) return `${accs[0].net.name} ${accs[0].handle}`;
  const fullNets = nets.filter((n) => accounts.filter((a) => a.net.id === n.id).every((a) => sel.includes(a.id)));
  if (nets.length === 1 && fullNets.length === 1) return `All ${nets[0].name} accounts`;
  return `${accs.length} of ${accounts.length} accounts`;
}

/* ---------- data alerts ---------- */

/** One line per account (or platform) we can't get data from, for the top of the page. */
export interface DataAlert { key: string; tone: "warn" | "info"; text: string; action?: "connections" }

const who = (a: Account) => `${a.handle} (${a.net.name})`;
const list = (items: string[]) => items.length <= 2 ? items.join(" and ") : `${items.slice(0, -1).join(", ")} and ${items[items.length - 1]}`;

export function dataAlerts(accounts: Account[]): DataAlert[] {
  const out: DataAlert[] = [];

  const reconnect = accounts.filter((a) => a.status !== "connected");
  if (reconnect.length) {
    out.push({ key: "reconnect", tone: "warn", action: "connections", text: `Reconnect ${list(reconnect.map(who))} to resume collecting stats.` });
  }

  const unsupported = accounts.filter((a) => a.status === "connected" && !a.supported);
  const byNet = new Map<string, Account[]>();
  for (const a of unsupported) byNet.set(a.net.id, [...(byNet.get(a.net.id) ?? []), a]);
  for (const [, accs] of byNet) {
    out.push({ key: `unsupported-${accs[0].net.id}`, tone: "info", text: `${accs[0].net.name} doesn't expose follower or post stats through its API yet, so ${list(accs.map((a) => a.handle))} ${accs.length > 1 ? "are" : "is"} not included.` });
  }

  for (const a of accounts.filter((a) => a.status === "connected" && a.supported)) {
    if (a.followerError) out.push({ key: `fol-${a.id}`, tone: "warn", text: `Couldn't fetch the follower count for ${who(a)}: ${a.followerError}` });
    if (a.postError) out.push({ key: `post-${a.id}`, tone: "warn", text: `Couldn't fetch post metrics for ${who(a)}: ${a.postError}` });
    if (!a.followerError && !a.postError && !a.hasFollowerData && !a.hasPostData) {
      out.push({ key: `pending-${a.id}`, tone: "info", text: `No data yet for ${who(a)}. The first daily snapshot is still to run.` });
    }
  }

  return out;
}
