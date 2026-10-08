// Analytics — Profile performance / Post performance (admin-only, dummy data).
// Ported from the Claude Design prototype in frontend/design-import/Analytics.dc.html.
// Sources, date range, chart view, sort and layout live in the URL query string.
import { useCallback, useMemo } from "react";
import { useSearchParams } from "react-router-dom";
import { Button } from "@/components/ui/button";
import { AnalyticsShell } from "@/components/analytics/useAnalytics";
import { SourcesPicker } from "@/components/analytics/performance/SourcesPicker";
import { RangePicker } from "@/components/analytics/performance/RangePicker";
import { KpiCards } from "@/components/analytics/performance/KpiCards";
import { FollowerGrowthCard, type FollowerView } from "@/components/analytics/performance/FollowerGrowthCard";
import { EngagementCard } from "@/components/analytics/performance/EngagementCard";
import { PostsSection, type PostLayout } from "@/components/analytics/performance/PostsSection";
import { downloadCsv } from "@/components/analytics/performance/csv";
import {
  ACCOUNT, ALL_ACCOUNT_IDS, DEFAULT_PRESET, POST_SORTS, PRESETS,
  buildHistory, clampRange, fromISODate, postsData, presetRange, profileData, toISODate,
  type DateRange, type PostSort, type PresetId,
} from "@/lib/analytics-performance-mock";

export type PerformanceTab = "profile" | "posts";

const PRESET_IDS = new Set<string>(PRESETS.map((p) => p.id));
const SORT_IDS = new Set<string>(POST_SORTS.map((s) => s.id));

export default function Performance({ tab }: { tab: PerformanceTab }) {
  const [params, setParams] = useSearchParams();
  const hist = useMemo(() => buildHistory(), []);

  // ---- URL state ----
  const sel = useMemo(() => {
    const ids = (params.get("sources") ?? "").split(",").filter((id) => id in ACCOUNT);
    return ids.length ? ALL_ACCOUNT_IDS.filter((id) => ids.includes(id)) : ALL_ACCOUNT_IDS;
  }, [params]);
  const range = useMemo<DateRange>(() => {
    const preset = params.get("range");
    if (preset && PRESET_IDS.has(preset)) return presetRange(preset as PresetId, hist.end);
    const from = fromISODate(params.get("from")), to = fromISODate(params.get("to"));
    if (from && to) return clampRange(hist, { from, to });
    return presetRange(DEFAULT_PRESET, hist.end);
  }, [params, hist]);
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

  // ---- derived data ----
  const profile = useMemo(() => profileData(hist, range, sel), [hist, range, sel]);
  const posts = useMemo(() => postsData(hist, range, sel, sort), [hist, range, sel, sort]);
  const isProfile = tab === "profile";

  const exportCsv = () => {
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
        ["Published", "Network", "Account", "Type", "Caption", "Impressions", "Engagements", "Engagement rate", "Likes", "Comments", "Shares", "Saves"],
        ...posts.map((p) => [toISODate(p.date), p.net.name, p.account.handle, p.type, p.caption, p.imp, p.eng, (p.rate * 100).toFixed(2) + "%", p.likes, p.comments, p.shares, p.saves]),
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
          <SourcesPicker value={sel} onChange={(ids) => update({ sources: ids.length === ALL_ACCOUNT_IDS.length ? null : ids.join(",") })} />
          <RangePicker
            value={range}
            min={hist.start}
            max={hist.end}
            onChange={(r, preset) => update(preset ? { range: preset, from: null, to: null } : { range: null, from: toISODate(r.from), to: toISODate(r.to) })}
          />
          <Button variant="outline" size="sm" onClick={exportCsv}>Export CSV</Button>
        </div>
      </header>

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
    </AnalyticsShell>
  );
}
