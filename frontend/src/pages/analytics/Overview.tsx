// Analytics — Overview. A filter bar, a unified metric strip, the revenue chart
// (clicks bars + revenue line), and a conversion-events breakdown by content or
// channel. Fed by the real tracking API.
import { useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { useTranslation } from "react-i18next";
import type { TFunction } from "i18next";
import { CARD } from "@/components/analytics/primitives";
import { Empty } from "@/components/analytics/shared";
import { FilterBar, type Opt } from "@/components/analytics/FilterBar";
import { RevenueChart, type ChartPoint } from "@/components/analytics/RevenueChart";
import { ConversionEventsTable } from "@/components/analytics/ConversionEventsTable";
import { TrafficSources } from "@/components/analytics/TrafficSources";
import { AnalyticsShell, AnalyticsLoading, useFunnlModel, useTimeseries, useTrafficSources, type Range } from "@/components/analytics/useAnalytics";
import { fmtFull, fmtMoney, fmtNum, fmtPct, totalsFromLinks, type LinkMetric } from "@/lib/analytics-model";
import type { TrackingTimeseriesPoint } from "@/lib/api-service";

type Gran = "daily" | "weekly" | "monthly";

const rangeOpts = (t: TFunction): Opt[] => [
  { id: "7d", label: t("analytics.overview.rangeLast7Days", "Last 7 days") },
  { id: "28d", label: t("analytics.overview.rangeLast28Days", "Last 28 days") },
  { id: "90d", label: t("analytics.overview.rangeLast90Days", "Last 90 days") },
];
// Same ranges, as used mid-sentence ("Conversion events · last 28 days").
const rangeInline = (t: TFunction): Record<Range, string> => ({
  "7d": t("analytics.overview.rangeLast7DaysInline", "last 7 days"),
  "28d": t("analytics.overview.rangeLast28DaysInline", "last 28 days"),
  "90d": t("analytics.overview.rangeLast90DaysInline", "last 90 days"),
});
const RANGE_LIST: Range[] = ["7d", "28d", "90d"];
const granOpts = (t: TFunction): Opt[] => [
  { id: "daily", label: t("analytics.overview.granDaily", "Daily") },
  { id: "weekly", label: t("analytics.overview.granWeekly", "Weekly") },
  { id: "monthly", label: t("analytics.overview.granMonthly", "Monthly") },
];
const eventOpts = (t: TFunction): Opt[] => [
  { id: "all", label: t("analytics.overview.eventAll", "All events") },
  { id: "sales", label: t("analytics.overview.eventSales", "Sales") },
  { id: "calls", label: t("analytics.overview.eventCalls", "Call bookings") },
  { id: "emails", label: t("analytics.overview.eventEmails", "Email signups") },
];

const MONTHS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
const dayLabel = (iso: string) => { const d = new Date(iso); return `${String(d.getUTCDate()).padStart(2, "0")} ${MONTHS[d.getUTCMonth()]}`; };
const monthLabel = (iso: string) => { const d = new Date(iso); return `${MONTHS[d.getUTCMonth()]} ${String(d.getUTCFullYear()).slice(2)}`; };

// Bucket the daily tracking series into the chart's chosen granularity.
function bucket(points: TrackingTimeseriesPoint[], gran: Gran): ChartPoint[] {
  if (gran === "daily") return points.map((p) => ({ label: dayLabel(p.date), clicks: p.clicks, visitors: p.visitors ?? 0, views: p.views ?? 0, revenue: p.revenue }));
  if (gran === "weekly") {
    const out: ChartPoint[] = [];
    for (let i = 0; i < points.length; i += 7) {
      const slice = points.slice(i, i + 7);
      out.push({ label: dayLabel(slice[0].date), clicks: slice.reduce((s, p) => s + p.clicks, 0), visitors: slice.reduce((s, p) => s + (p.visitors ?? 0), 0), views: slice.reduce((s, p) => s + (p.views ?? 0), 0), revenue: slice.reduce((s, p) => s + p.revenue, 0) });
    }
    return out;
  }
  const byMonth = new Map<string, ChartPoint>();
  for (const p of points) {
    const key = p.date.slice(0, 7);
    const cur = byMonth.get(key) || { label: monthLabel(p.date), clicks: 0, visitors: 0, views: 0, revenue: 0 };
    cur.clicks += p.clicks; cur.visitors = (cur.visitors ?? 0) + (p.visitors ?? 0); cur.views += p.views ?? 0; cur.revenue += p.revenue;
    byMonth.set(key, cur);
  }
  return Array.from(byMonth.values());
}

// Period-over-period momentum: trailing half vs leading half of the series.
const pctDelta = (from: number, to: number): number | null => (from > 0 ? Math.round(((to - from) / from) * 100) : null);
function halfDeltas(points: TrackingTimeseriesPoint[]) {
  if (points.length < 4) return null;
  const mid = Math.floor(points.length / 2);
  const sum = (arr: TrackingTimeseriesPoint[], k: keyof TrackingTimeseriesPoint) => arr.reduce((s, p) => s + (p[k] as number), 0);
  const A = points.slice(0, mid), B = points.slice(mid);
  const cA = sum(A, "clicks"), cB = sum(B, "clicks"), rA = sum(A, "revenue"), rB = sum(B, "revenue"), vA = sum(A, "conversions"), vB = sum(B, "conversions");
  return {
    clicks: pctDelta(cA, cB),
    revenue: pctDelta(rA, rB),
    cr: pctDelta(cA ? (vA / cA) * 100 : 0, cB ? (vB / cB) * 100 : 0),
    epc: pctDelta(cA ? rA / cA : 0, cB ? rB / cB : 0),
  };
}

const eventCount = (l: LinkMetric, ev: string) => ev === "sales" ? l.conv : ev === "calls" ? l.calls : ev === "emails" ? l.emails : 1;

function MetricStrip({ cells }: { cells: { label: string; value: string; delta: number | null }[] }) {
  return (
    <div style={{ ...CARD, borderRadius: 16, padding: "18px 4px", display: "flex" }}>
      {cells.map((m, i) => {
        const up = (m.delta ?? 0) >= 0;
        return (
          <div key={m.label} style={{ flex: 1, minWidth: 0, padding: "0 22px", borderLeft: i ? "1px solid var(--line-1)" : "none" }}>
            <div style={{ fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 11.5, color: "var(--ink-on-paper-3)", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>{m.label}</div>
            <div style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 28, lineHeight: 1.05, letterSpacing: "-.02em", fontVariantNumeric: "tabular-nums", color: "var(--ink-on-paper-1)", marginTop: 6 }}>{m.value}</div>
            <div style={{ marginTop: 5, fontFamily: "var(--font-mono)", fontWeight: 600, fontSize: 11.5, color: m.delta == null ? "var(--ink-on-paper-3)" : up ? "var(--up)" : "var(--down)" }}>
              {m.delta == null ? "—" : `${up ? "▲" : "▼"} ${Math.abs(m.delta)}%`}
            </div>
          </div>
        );
      })}
    </div>
  );
}

export default function AnalyticsOverview() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const [range, setRange] = useState<Range>("28d");
  const [gran, setGran] = useState<Gran>("daily");
  const [page, setPage] = useState("all");
  const [channel, setChannel] = useState("all");
  const [convEvent, setConvEvent] = useState("all");
  const [link, setLink] = useState("all");

  const { model, loading, reload } = useFunnlModel();
  // When a single offer is picked, scope the trend series to it too.
  const { points, loading: tsLoading } = useTimeseries(range, page !== "all" ? page : undefined);
  const { sources: trafficSources, loading: sourcesLoading } = useTrafficSources(range, page !== "all" ? page : undefined);
  const [refreshing, setRefreshing] = useState(false);

  const refresh = () => { setRefreshing(true); reload().finally(() => setTimeout(() => setRefreshing(false), 500)); };

  // Offer + channel + link scope drives the strip + table; the event filter
  // further narrows the table rows. The chart follows the offer scope.
  const scoped = useMemo(() => {
    if (!model) return [] as LinkMetric[];
    let ls = model.links;
    if (page !== "all") ls = ls.filter((l) => l.page === page);
    if (channel !== "all") ls = ls.filter((l) => l.platform === channel);
    if (link !== "all") ls = ls.filter((l) => l.id === link);
    return ls;
  }, [model, page, channel, link]);

  const tableLinks = useMemo(() => convEvent === "all" ? scoped : scoped.filter((l) => eventCount(l, convEvent) > 0), [scoped, convEvent]);

  const filtersActive = channel !== "all" || link !== "all";
  const deltas = !filtersActive ? halfDeltas(points) : null;

  const stepRange = (dir: number) => setRange((r) => RANGE_LIST[Math.min(RANGE_LIST.length - 1, Math.max(0, RANGE_LIST.indexOf(r) + dir))]);

  if (loading || !model) return <AnalyticsShell><AnalyticsLoading /></AnalyticsShell>;

  const totals = totalsFromLinks(scoped);
  const chartPoints = bucket(points, gran);
  const revUp = deltas?.revenue ?? null;
  const selectedPage = page !== "all" ? model.pages.find((p) => p.id === page) : null;
  const domain = (selectedPage?.url || model.pages[0]?.url || t("analytics.overview.allSites", "All sites")).split("/")[0];

  const siteOpts: Opt[] = [{ id: "all", label: t("analytics.overview.allOffers", "All offers") }, ...model.pages.map((p) => ({ id: p.id, label: p.url || p.name }))];
  const channelOpts: Opt[] = [{ id: "all", label: t("analytics.overview.allChannels", "All channels") }, ...model.platforms.map((p) => ({ id: p.id, label: p.name }))];
  const linkOpts: Opt[] = [{ id: "all", label: t("analytics.overview.allLinks", "All links") }, ...model.links.filter((l) => page === "all" || l.page === page).map((l) => ({ id: l.id, label: l.label }))];

  const cells = [
    { label: t("analytics.overview.metricVisitors", "Visitors"), value: fmtFull(totals.visitors), delta: null as number | null },
    { label: t("analytics.overview.metricContentViews", "Content Views"), value: totals.viewsSince > 0 ? fmtNum(totals.viewsSince) : "—", delta: null as number | null },
    { label: t("analytics.overview.metricClicks", "Clicks"), value: fmtFull(totals.clicks), delta: deltas?.clicks ?? null },
    { label: t("analytics.overview.metricRevenue", "Revenue"), value: fmtMoney(totals.rev), delta: deltas?.revenue ?? null },
    { label: t("analytics.overview.metricConvRate", "Conv. rate"), value: totals.viewCr == null ? "—" : fmtPct(totals.viewCr), delta: null as number | null },
    { label: t("analytics.overview.metricRevenuePerClick", "Revenue/click"), value: fmtMoney(totals.epc, 2), delta: deltas?.epc ?? null },
  ];

  return (
    <AnalyticsShell>
      <div>
        <div style={{ display: "flex", alignItems: "baseline", gap: 14 }}>
          <h1 style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 26, letterSpacing: "-.03em", color: "var(--ink-on-paper-1)", margin: 0 }}>{t("analytics.overview.title", "Revenue growth")}</h1>
          <span style={{ fontFamily: "var(--font-mono)", fontSize: 12, color: "var(--ink-on-paper-3)" }}>{domain}</span>
        </div>
        <p style={{ fontFamily: "var(--font-body)", fontSize: 14, color: "var(--ink-on-paper-2)", margin: "6px 0 0", maxWidth: 780, lineHeight: 1.5 }}>
          {t("analytics.overview.intro", "Filter by channel, event or link up top. A unified metric strip, the revenue trend, and a conversion-events breakdown by content or channel.")}
        </p>
      </div>

      {model.pages.length === 0 ? (
        <div style={{ ...CARD, padding: 48 }}>
          <Empty label={t("analytics.overview.empty", "No tracked offers yet. Add one under Offers to start attributing revenue.")} />
        </div>
      ) : (
        <>
          <FilterBar
            site={page} siteOptions={siteOpts} onSite={(id) => { setPage(id); setLink("all"); }}
            channel={channel} channelOptions={channelOpts} onChannel={setChannel}
            convEvent={convEvent} eventOptions={eventOpts(t)} onEvent={setConvEvent}
            link={link} linkOptions={linkOpts} onLink={setLink}
            range={range} rangeOptions={rangeOpts(t)} onRange={(id) => setRange(id as Range)} onPrev={() => stepRange(-1)} onNext={() => stepRange(1)}
            gran={gran} granOptions={granOpts(t)} onGran={(id) => setGran(id as Gran)}
            onRefresh={refresh} refreshing={refreshing}
          />

          <MetricStrip cells={cells} />

          <div style={{ ...CARD, borderRadius: 18, padding: "16px 18px 10px" }}>
            <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", marginBottom: 6 }}>
              <div style={{ fontFamily: "var(--font-display)", fontWeight: 700, fontSize: 16, color: "var(--ink-on-paper-1)" }}>{t("analytics.overview.revenueChartTitle", "Revenue")}</div>
              {revUp != null && (
                <span style={{ display: "inline-flex", alignItems: "center", gap: 6, background: revUp >= 0 ? "var(--vm-volt-tint-l)" : "var(--vm-red-tint-l)", color: revUp >= 0 ? "var(--vm-volt-deep)" : "var(--vm-red-deep)", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 11.5, padding: "5px 11px", borderRadius: 999 }}>
                  {revUp >= 0
                    ? t("analytics.overview.revenueUp", "▲ Up {{pct}}%", { pct: Math.abs(revUp) })
                    : t("analytics.overview.revenueDown", "▼ Down {{pct}}%", { pct: Math.abs(revUp) })}
                </span>
              )}
            </div>
            {tsLoading ? <div style={{ height: 320 }} /> : <RevenueChart points={chartPoints} />}
          </div>

          {/* GA-style acquisition: sources (grouped visitors) + full referrer URLs. */}
          <TrafficSources data={trafficSources} loading={sourcesLoading} limit={5} moreHref="/dashboard/analytics/sources" />

          <ConversionEventsTable
            links={tableLinks}
            rangeLabel={rangeInline(t)[range] ?? t("analytics.overview.thisPeriod", "this period")}
            onOpenContent={(id) => navigate(`/dashboard/monetization/links/${id}`)}
          />
        </>
      )}
    </AnalyticsShell>
  );
}
