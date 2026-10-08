// Dummy data for the Analytics → Profile / Post performance pages.
//
// Ported from the Claude Design prototype (frontend/design-import/Analytics.dc.html).
// Everything is derived from a seeded PRNG so the numbers are stable between
// renders and tests. Replace `buildHistory` with the real per-account daily
// snapshot feed when the backend pipeline lands; the derivation functions
// below only depend on the `History` shape.

export type NetId = "youtube" | "tiktok" | "instagram" | "linkedin" | "x";

export interface Network {
  id: NetId;
  name: string;
  /** Chart colour (design-system data-viz token). */
  color: string;
  /** Brand colour for the small platform badge. */
  badge: string;
}

export const NETWORKS: Network[] = [
  { id: "youtube", name: "YouTube", color: "var(--data-red)", badge: "#FF0033" },
  { id: "tiktok", name: "TikTok", color: "var(--data-indigo)", badge: "#111111" },
  { id: "instagram", name: "Instagram", color: "var(--data-pink)", badge: "#E1306C" },
  { id: "linkedin", name: "LinkedIn", color: "var(--data-blue)", badge: "#0A66C2" },
  { id: "x", name: "X", color: "var(--ink-on-paper-2)", badge: "#000000" },
];
export const NETWORK: Record<NetId, Network> = Object.fromEntries(NETWORKS.map((n) => [n.id, n])) as Record<NetId, Network>;

export interface Account {
  id: string;
  net: NetId;
  initials: string;
  handle: string;
  /** Avatar fallback tint. */
  tint: string;
  // Generator inputs
  base: number;
  mean: number;
  eng: number;
  imp: number;
}

const TINTS = ["var(--vm-volt-tint-l)", "var(--paper-3)", "var(--paper-2)"];
const RAW_ACCOUNTS: Omit<Account, "tint">[] = [
  { id: "yt1", net: "youtube", initials: "VM", handle: "@viewsmax", base: 15200, mean: 50, eng: 420, imp: 9 },
  { id: "yt2", net: "youtube", initials: "VS", handle: "@viewsmax.shorts", base: 4600, mean: 14, eng: 120, imp: 6 },
  { id: "tt1", net: "tiktok", initials: "VM", handle: "@viewsmax", base: 11200, mean: 52, eng: 430, imp: 7 },
  { id: "ig1", net: "instagram", initials: "VM", handle: "@viewsmax", base: 6100, mean: 16, eng: 190, imp: 4 },
  { id: "ig2", net: "instagram", initials: "ST", handle: "@viewsmax.studio", base: 2200, mean: 5, eng: 60, imp: 2 },
  { id: "li1", net: "linkedin", initials: "VM", handle: "ViewsMax", base: 2400, mean: 9, eng: 95, imp: 1.4 },
  { id: "x1", net: "x", initials: "VM", handle: "@viewsmax", base: 2700, mean: 2.5, eng: 45, imp: 1 },
  { id: "x2", net: "x", initials: "VH", handle: "@viewsmax_help", base: 600, mean: 0.6, eng: 10, imp: 0.4 },
];
export const ACCOUNTS: Account[] = RAW_ACCOUNTS.map((a, i) => ({ ...a, tint: TINTS[i % TINTS.length] }));
export const ACCOUNT: Record<string, Account> = Object.fromEntries(ACCOUNTS.map((a) => [a.id, a]));
export const ALL_ACCOUNT_IDS = ACCOUNTS.map((a) => a.id);

const CAPTIONS: [string, string, string][] = [
  ["yt1", "Video", "I tested 5 thumbnail styles for 30 days. Here is what won."],
  ["tt1", "Short video", "The 3-second hook rule nobody talks about"],
  ["ig1", "Reel", "Behind the edit: how we cut a 40 min shoot into 60 seconds"],
  ["yt2", "Short", "Stop uploading at 5pm. Do this instead."],
  ["li1", "Document", "Our Q3 creator report: what grew, what stalled, what we cut"],
  ["tt1", "Short video", "Replying to every comment for a week changed this"],
  ["ig1", "Carousel", "7 title formulas we use on every upload"],
  ["x1", "Thread", "A thread on retention graphs and the 30-second cliff"],
  ["yt1", "Video", "Full channel audit: 10K to 100K in one year"],
  ["ig2", "Image", "New studio setup is finally done"],
  ["tt1", "Short video", "POV: your first video hits 1M views"],
  ["x2", "Post", "Upload issues are fixed. Thanks for your patience."],
  ["li1", "Text", "Hiring our first full-time editor. What we look for."],
  ["yt2", "Short", "One lighting change, twice the watch time"],
  ["x1", "Post", "Hot take: posting daily is not the reason you are growing"],
  ["ig2", "Reel", "Recording a whole week of content in one afternoon"],
  ["tt1", "Short video", "Things I would never do as a new creator"],
  ["yt1", "Video", "We let our audience pick every video for a month"],
];

/* ---------- dates ---------- */

/** Days of history generated before `end` (covers a year-to-date range plus its comparison period). */
export const HISTORY_DAYS = 800;
const DAY = 86400000;
const MON = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];

export const startOfDay = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate());
export const addDays = (d: Date, n: number) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; };
export const sameDay = (a?: Date, b?: Date) => !!a && !!b && a.toDateString() === b.toDateString();
/** "Oct 5" */
export const dayLabel = (d: Date) => `${MON[d.getMonth()]} ${d.getDate()}`;
/** "Oct 5, 2026" */
export const dayLabelYear = (d: Date) => `${dayLabel(d)}, ${d.getFullYear()}`;
/** YYYY-MM-DD in local time (URL state). */
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

/* ---------- formatting ---------- */

export function fmt(n: number): string {
  const a = Math.abs(n);
  if (a >= 1e6) return (n / 1e6).toFixed(1) + "M";
  if (a >= 1e4) return (n / 1e3).toFixed(1) + "K";
  return Math.round(n).toLocaleString("en-US");
}
export const signed = (n: number) => (n > 0 ? "+" : "") + fmt(n);
export const pct = (ratio: number) => (ratio * 100).toFixed(1) + "%";

export interface Delta { label: string; dir: "up" | "down" | "flat" }
export function delta(cur: number, prev: number): Delta {
  if (!prev || !Number.isFinite(prev) || !Number.isFinite(cur)) return { label: "–", dir: "flat" };
  const p = ((cur - prev) / Math.abs(prev)) * 100;
  const up = p >= 0;
  return { label: (up ? "▲ " : "▼ ") + Math.abs(p).toFixed(1) + "%", dir: up ? "up" : "down" };
}

/* ---------- seeded history ---------- */

function rng(seed: number) {
  return function () {
    seed |= 0; seed = (seed + 0x6d2b79f5) | 0;
    let t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

export interface AccountSeries { net: number[]; eng: number[]; imp: number[]; fol: number[] }
export interface History {
  end: Date;
  /** First day with data (calendar lower bound). */
  start: Date;
  dates: Date[];
  acc: Record<string, AccountSeries>;
}

const historyCache = new Map<string, History>();

/** Daily per-account series for the HISTORY_DAYS days ending on `end` (inclusive). Memoised per day. */
export function buildHistory(end: Date = new Date()): History {
  const e = startOfDay(end);
  const key = e.toDateString();
  const cached = historyCache.get(key);
  if (cached) return cached;

  const H = HISTORY_DAYS;
  const r = rng(11);
  const spikes = Array.from({ length: H }, () => (r() > 0.93 ? 1 : 0));
  const dates = Array.from({ length: H }, (_, i) => addDays(e, i - (H - 1)));
  const acc: Record<string, AccountSeries> = {};
  for (const a of ACCOUNTS) {
    const net: number[] = [], eng: number[] = [], imp: number[] = [], fol: number[] = [];
    let run = a.base * 0.55;
    for (let i = 0; i < H; i++) {
      const g = 0.6 + 0.7 * (i / H);
      const s = spikes[i] && r() > 0.3 ? 1 : 0;
      const v = Math.round(a.mean * g * (r() * 1.5 - 0.2) + s * a.mean * (3 + r() * 3));
      run += v; net.push(v); fol.push(Math.round(run));
      const ev = Math.round(a.eng * g * (0.5 + r()) + s * a.eng * (2 + r() * 2));
      eng.push(ev); imp.push(Math.round(ev * (19 + r() * 8)));
    }
    acc[a.id] = { net, eng, imp, fol };
  }
  const hist: History = { end: e, start: dates[0], dates, acc };
  historyCache.set(key, hist);
  return hist;
}

/** Index of a calendar day in `hist.dates` (clamped to the available history). */
export function indexOf(hist: History, d: Date): number {
  const i = HISTORY_DAYS - 1 - Math.round((hist.end.getTime() - startOfDay(d).getTime()) / DAY);
  return Math.max(0, Math.min(HISTORY_DAYS - 1, i));
}

/** Clamp a range to the history window and order it. */
export function clampRange(hist: History, range: DateRange): DateRange {
  let from = startOfDay(range.from), to = startOfDay(range.to);
  if (from > to) [from, to] = [to, from];
  if (from < hist.start) from = hist.start;
  if (to > hist.end) to = hist.end;
  if (from > to) from = to;
  return { from, to };
}

/* ---------- derivations ---------- */

interface Window { a0: number; a1: number; L: number; p0: number }
function windowOf(hist: History, range: DateRange): Window {
  const r = clampRange(hist, range);
  const a0 = indexOf(hist, r.from), a1 = indexOf(hist, r.to), L = a1 - a0 + 1;
  return { a0, a1, L, p0: Math.max(0, a0 - L) };
}
const sum = (arr: number[], from: number, to: number) => { let s = 0; for (let i = Math.max(0, from); i <= to; i++) s += arr[i]; return s; };

export function selectedAccounts(sel: string[]): Account[] {
  return ACCOUNTS.filter((a) => sel.includes(a.id));
}
/** Networks that have at least one selected account, in canonical order. */
export function selectedNetworks(sel: string[]): Network[] {
  const accs = selectedAccounts(sel);
  return NETWORKS.filter((n) => accs.some((a) => a.net === n.id));
}

export interface Kpi { label: string; value: string; delta: Delta }
export interface AccountRow { account: Account; net: Network }
export interface FollowerRow extends AccountRow { followers: string; growth: string; delta: Delta }
export interface EngagementRow extends AccountRow { engagements: string; rate: string; /** 0..1 share of selected engagements */ share: number; delta: Delta }
export interface NetSeries { net: Network; values: number[] }

export interface ProfileData {
  dates: Date[];
  kpis: Kpi[];
  /** Daily totals across the selected accounts. */
  followers: { total: number[]; net: number[] };
  followerRows: FollowerRow[];
  engagement: { totals: number[]; byNet: NetSeries[] };
  engagementRows: EngagementRow[];
}

export function profileData(hist: History, range: DateRange, sel: string[]): ProfileData {
  const { a0, a1, L, p0 } = windowOf(hist, range);
  const accs = selectedAccounts(sel);
  const nets = selectedNetworks(sel);
  const idx = Array.from({ length: L }, (_, i) => a0 + i);
  const dates = idx.map((i) => hist.dates[i]);
  const S = (id: string, k: keyof AccountSeries, from: number, to: number) => sum(hist.acc[id][k], from, to);
  const SA = (k: keyof AccountSeries, from: number, to: number, list = accs) => list.reduce((t, a) => t + S(a.id, k, from, to), 0);
  const day = (k: keyof AccountSeries, i: number, list = accs) => list.reduce((t, a) => t + hist.acc[a.id][k][i], 0);

  const folEnd = day("fol", a1), folPrevEnd = a1 - L >= 0 ? day("fol", a1 - L) : 0;
  const netCur = SA("net", a0, a1), netPrev = SA("net", p0, a0 - 1);
  const engCur = SA("eng", a0, a1), engPrev = SA("eng", p0, a0 - 1);
  const impCur = SA("imp", a0, a1), impPrev = SA("imp", p0, a0 - 1);
  const rate = impCur ? engCur / impCur : 0;
  const kpis: Kpi[] = [
    { label: "Total followers", value: fmt(folEnd), delta: delta(folEnd, folPrevEnd) },
    { label: "Net follower growth", value: signed(netCur), delta: delta(netCur, netPrev) },
    { label: "Engagements", value: fmt(engCur), delta: delta(engCur, engPrev) },
    { label: "Engagement rate (per impression)", value: pct(rate), delta: delta(rate, impPrev ? engPrev / impPrev : 0) },
  ];

  const followers = { total: idx.map((i) => day("fol", i)), net: idx.map((i) => day("net", i)) };
  const followerRows: FollowerRow[] = accs.map((a) => {
    const c = S(a.id, "net", a0, a1), p = S(a.id, "net", p0, a0 - 1);
    return { account: a, net: NETWORK[a.net], followers: fmt(hist.acc[a.id].fol[a1]), growth: signed(c), delta: delta(c, p) };
  });

  const totals = idx.map((i) => day("eng", i));
  const byNet: NetSeries[] = nets.map((n) => {
    const list = accs.filter((a) => a.net === n.id);
    return { net: n, values: idx.map((i) => day("eng", i, list)) };
  });
  const engagementRows: EngagementRow[] = accs.map((a) => {
    const c = S(a.id, "eng", a0, a1), p = S(a.id, "eng", p0, a0 - 1), im = S(a.id, "imp", a0, a1);
    return { account: a, net: NETWORK[a.net], engagements: fmt(c), rate: pct(im ? c / im : 0), share: engCur ? c / engCur : 0, delta: delta(c, p) };
  });

  return { dates, kpis, followers, followerRows, engagement: { totals, byNet }, engagementRows };
}

export type PostSort = "rate" | "eng" | "imp" | "shares" | "date";
export const POST_SORTS: { id: PostSort; label: string }[] = [
  { id: "rate", label: "Engagement rate" },
  { id: "eng", label: "Engagements" },
  { id: "imp", label: "Impressions" },
  { id: "shares", label: "Shares" },
  { id: "date", label: "Published date" },
];

export interface PostStat {
  id: string;
  account: Account;
  net: Network;
  type: string;
  caption: string;
  date: Date;
  imp: number;
  eng: number;
  /** engagements / impressions, 0..1 */
  rate: number;
  likes: number;
  comments: number;
  shares: number;
  saves: number;
}

/** Posts published in the range from the selected accounts, sorted. */
export function postsData(hist: History, range: DateRange, sel: string[], sort: PostSort): PostStat[] {
  const { a0, a1, L } = windowOf(hist, range);
  const pr = rng(99 + a0 * 7 + L);
  const count = Math.min(CAPTIONS.length, Math.max(4, Math.round(L * 0.6)));
  const posts = CAPTIONS.slice(0, count).map(([aid, type, caption], i): PostStat & { off: number } => {
    const a = ACCOUNT[aid];
    const imp = Math.round(a.imp * 1000 * (0.4 + pr() * 2.2)), rate = 0.015 + pr() * 0.09, eng = Math.round(imp * rate);
    const comments = Math.round(eng * (0.05 + pr() * 0.08)), shares = Math.round(eng * (0.04 + pr() * 0.1)), saves = Math.round(eng * (0.03 + pr() * 0.08));
    const off = Math.min(L - 1, Math.floor((i * L) / count + pr() * (L / count)));
    return { id: `${aid}-${i}`, account: a, net: NETWORK[a.net], type, caption, imp, eng, rate: imp ? eng / imp : 0, likes: eng - comments - shares - saves, comments, shares, saves, off, date: hist.dates[a1 - off] };
  }).filter((p) => sel.includes(p.account.id));
  posts.sort((p, q) => (sort === "date" ? p.off - q.off : q[sort] - p[sort]));
  return posts.map(({ off: _off, ...p }) => p);
}

/** Trigger label for the sources picker. */
export function sourcesLabel(sel: string[]): string {
  if (sel.length === ACCOUNTS.length) return "All sources";
  const accs = selectedAccounts(sel);
  const nets = selectedNetworks(sel);
  if (accs.length === 1) return `${NETWORK[accs[0].net].name} ${accs[0].handle}`;
  const fullNets = NETWORKS.filter((n) => ACCOUNTS.filter((a) => a.net === n.id).every((a) => sel.includes(a.id)));
  if (nets.length === 1 && fullNets.length === 1) return `All ${nets[0].name} accounts`;
  return `${accs.length} of ${ACCOUNTS.length} accounts`;
}
