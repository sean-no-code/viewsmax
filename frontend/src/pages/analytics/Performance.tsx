// Analytics — Profile performance / Post performance (admin-only while the
// multi-platform data pipeline is finished). Fed by GET /api/analytics/performance;
// sources, date range, chart view, sort and layout live in the URL query string.
import { useCallback, useEffect, useMemo, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { AnalyticsShell, AnalyticsLoading } from "@/components/analytics/useAnalytics";
import { CARD } from "@/components/analytics/primitives";
import { SourcesPicker } from "@/components/analytics/performance/SourcesPicker";
import { RangePicker } from "@/components/analytics/performance/RangePicker";
import { KpiCards } from "@/components/analytics/performance/KpiCards";
import { FollowerGrowthCard, type FollowerView } from "@/components/analytics/performance/FollowerGrowthCard";
import { EngagementCard } from "@/components/analytics/performance/EngagementCard";
import { PostsSection, type PostLayout } from "@/components/analytics/performance/PostsSection";
import { DataAlerts } from "@/components/analytics/performance/DataAlerts";
import { downloadCsv } from "@/components/analytics/performance/csv";
import { viewsMaxApi, type PerformanceData } from "@/lib/api-service";
import {
  DEFAULT_PRESET, POST_SORTS, PRESETS,
  addDays, clampRange, fromApi, fromISODate, postsData, presetRange, profileData, startOfDay, toISODate,
  type DateRange, type PostSort, type PresetId,
} from "@/lib/analytics-performance";

export type PerformanceTab = "profile" | "posts";

const PRESET_IDS = new Set<string>(PRESETS.map((p) => p.id));
const SORT_IDS = new Set<string>(POST_SORTS.map((s) => s.id));
/** How far back the calendar lets you go. */
const HISTORY_DAYS = 365 * 2;

export default function Performance({ tab }: { tab: PerformanceTab }) {
  const [params, setParams] = useSearchParams();
  const today = useMemo(() => startOfDay(new Date()), []);
  const earliest = useMemo(() => addDays(today, -HISTORY_DAYS), [today]);

  // ---- URL state ----
  const range = useMemo<DateRange>(() => {
    const preset = params.get("range");
    if (preset && PRESET_IDS.has(preset)) return presetRange(preset as PresetId, today);
    const from = fromISODate(params.get("from")), to = fromISODate(params.get("to"));
    if (from && to) return clampRange({ from, to }, earliest, today);
    return presetRange(DEFAULT_PRESET, today);
  }, [params, today, earliest]);
  const view: FollowerView = params.get("view") === "net" ? "net" : "total";
  const sortParam = params.get("sort");
  const sort: PostSort = sortParam && SORT_IDS.has(sortParam) ? (sortParam as PostSort) : "rate";
  const layout: PostLayout = params.get("layout") === "list" ? "list" : "grid";

  const update = useCallback((patch: Record<string, string | null>) => {
    setParams((prev) => {
      const next = new URLSearchParams(prev);
      for (const [k, v] of Object.entries(patch)) { if (v === null) next.delete(k); else next.set(k, v); }
      return next;
    }, { replace: true });
  }, [setParams]);

  // ---- data ----
  const [data, setData] = useState<PerformanceData | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const rangeKey = `${toISODate(range.from)}_${toISODate(range.to)}`;
  useEffect(() => {
    let active = true;
    setLoading(true);
    viewsMaxApi.getAnalyticsPerformance({ from: range.from, to: range.to }).then((res) => {
      if (!active) return;
      if (res.success && Array.isArray(res.data?.days) && Array.isArray(res.data?.accounts)) { setData(res.data); setError(null); }
      else setError(res.error || "Couldn't load analytics.");
      setLoading(false);
    });
    return () => { active = false; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [rangeKey]);

  const perf = useMemo(() => (data ? fromApi(data) : null), [data]);
  const accounts = useMemo(() => perf?.accounts ?? [], [perf]);
  const allIds = useMemo(() => accounts.map((a) => a.id), [accounts]);
  const sel = useMemo(() => {
    const ids = (params.get("sources") ?? "").split(",").filter((id) => allIds.includes(id));
    return ids.length ? allIds.filter((id) => ids.includes(id)) : allIds;
  }, [params, allIds]);

  const profile = useMemo(() => (perf ? profileData(perf.hist, perf.accounts, sel) : null), [perf, sel]);
  const posts = useMemo(() => (perf ? postsData(perf.posts, sel, sort) : []), [perf, sel, sort]);
  const isProfile = tab === "profile";

  const exportCsv = () => {
    if (!profile) return;
    const stamp = `${toISODate(range.from)}_${toISODate(range.to)}`;
    if (isProfile) {
      downloadCsv(`profile-performance-${stamp}.csv`, [
        ["Account", "Network", "Followers", "Net growth", "Follower change", "Engagements", "Engagement rate", "Engagement change"],
        ...profile.followerRows.map((f, i) => {
          const e = profile.engagementRows[i];
          return [f.account.handle, f.net.name, f.followers, f.growth, f.delta.label, e.engagements, e.rate, e.delta.label];
        }),
      ]);
    } else {
      downloadCsv(`post-performance-${stamp}.csv`, [
        ["Published", "Network", "Account", "Caption", "URL", "Views", "Engagements", "Engagement rate", "Likes", "Comments", "Shares"],
        ...posts.map((p) => [toISODate(p.date), p.net.name, p.account.handle, p.caption, p.url ?? "", p.views, p.eng, p.rate === null ? "" : (p.rate * 100).toFixed(2) + "%", p.likes, p.comments, p.shares]),
      ]);
    }
  };

  return (
    <AnalyticsShell>
      <header style={{ display: "flex", flexWrap: "wrap", alignItems: "center", justifyContent: "space-between", gap: 16 }}>
        <h1 style={{ margin: 0, fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 32, letterSpacing: "-0.02em", color: "var(--ink-on-paper-1)" }}>
          {isProfile ? "Profile performance" : "Post performance"}
        </h1>
        <div style={{ display: "flex", alignItems: "center", gap: 8, flexWrap: "wrap" }}>
          {accounts.length > 0 && (
            <SourcesPicker accounts={accounts} value={sel} onChange={(ids) => update({ sources: ids.length === allIds.length ? null : ids.join(",") })} />
          )}
          <RangePicker
            value={range}
            min={earliest}
            max={today}
            onChange={(r, preset) => update(preset ? { range: preset, from: null, to: null } : { range: null, from: toISODate(r.from), to: toISODate(r.to) })}
          />
          <Button variant="outline" size="sm" onClick={exportCsv} disabled={!perf}>Export CSV</Button>
        </div>
      </header>

      {loading && !perf ? (
        <AnalyticsLoading />
      ) : error && !perf ? (
        <div role="alert" style={{ ...CARD, padding: 28, textAlign: "center", fontSize: 13, color: "var(--down)" }}>{error}</div>
      ) : perf && accounts.length === 0 ? (
        <div style={{ ...CARD, padding: 28, textAlign: "center", fontSize: 13.5, color: "var(--ink-on-paper-3)", lineHeight: 1.5 }}>
          No connected accounts yet. <Link to="/dashboard/connections" style={{ fontWeight: 600, color: "var(--vm-red-deep)" }}>Connect a platform</Link> to start tracking followers and post engagement.
        </div>
      ) : perf && profile ? (
        <>
          <DataAlerts accounts={accounts} />
          {isProfile ? (
            <>
              <KpiCards kpis={profile.kpis} />
              <FollowerGrowthCard data={profile} view={view} onViewChange={(v) => update({ view: v === "total" ? null : v })} />
              <EngagementCard data={profile} />
            </>
          ) : (
            <PostsSection
              posts={posts}
              sort={sort}
              onSortChange={(s) => update({ sort: s === "rate" ? null : s })}
              layout={layout}
              onLayoutChange={(l) => update({ layout: l === "grid" ? null : l })}
            />
          )}
        </>
      ) : null}
    </AnalyticsShell>
  );
}
