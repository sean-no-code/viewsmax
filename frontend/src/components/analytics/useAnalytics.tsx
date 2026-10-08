// Data hooks + page shell for the Analytics feature.
import { useCallback, useEffect, useState, type ReactNode } from "react";
import { viewsMaxApi, type TrackingEvent, type TrackingTimeseriesPoint, type TrafficSourcesData } from "@/lib/api-service";
import { toFunnlModel, type FunnlModel } from "@/lib/analytics-model";

export type Range = "7d" | "28d" | "90d";
export const RANGE_OPTS = [
  { id: "7d", label: "7d" },
  { id: "28d", label: "28d" },
  { id: "90d", label: "90d" },
];
const RANGE_DAYS: Record<Range, number> = { "7d": 7, "28d": 28, "90d": 90 };

export function rangeToDates(range: Range): { from: Date; to: Date } {
  const to = new Date();
  const from = new Date();
  from.setDate(from.getDate() - (RANGE_DAYS[range] - 1));
  return { from, to };
}

/** Fetch the user's tracking events and derive the Funnl view model. */
export function useFunnlModel() {
  const [events, setEvents] = useState<TrackingEvent[]>([]);
  const [model, setModel] = useState<FunnlModel | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const reload = useCallback(async () => {
    setLoading(true);
    const [res, goalRes] = await Promise.all([
      viewsMaxApi.getTrackingEvents(),
      viewsMaxApi.getGoalTypes(),
    ]);
    if (res.success && res.data) {
      setEvents(res.data);
      setModel(toFunnlModel(res.data, goalRes.success ? goalRes.data : []));
      setError(null);
    } else {
      setError(res.error || "Failed to load analytics");
    }
    setLoading(false);
  }, []);

  useEffect(() => { reload(); }, [reload]);

  return { events, model, loading, error, reload, setEvents };
}

/** Fetch the daily clicks/revenue series for a range (optionally one page). */
export function useTimeseries(range: Range, eventId?: number | string) {
  const [points, setPoints] = useState<TrackingTimeseriesPoint[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let active = true;
    setLoading(true);
    const { from, to } = rangeToDates(range);
    viewsMaxApi.getTrackingTimeseries({ from, to, eventId }).then((res) => {
      if (!active) return;
      setPoints(res.success && res.data ? res.data : []);
      setLoading(false);
    });
    return () => { active = false; };
  }, [range, eventId]);

  return { points, loading };
}

/** GA-style acquisition data (sources + full referrer URLs) for a range. */
export function useTrafficSources(range: Range, eventId?: number | string) {
  const [data, setData] = useState<TrafficSourcesData | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let active = true;
    setLoading(true);
    const { from, to } = rangeToDates(range);
    viewsMaxApi.getTrackingSources({ from, to, eventId }).then((res) => {
      if (!active) return;
      setData(res.success && res.data ? res.data : null);
      setLoading(false);
    });
    return () => { active = false; };
  }, [range, eventId]);

  return { sources: data, loading };
}

/** Warm full-bleed canvas that matches the design (paper-1 ground). */
export function AnalyticsShell({ children }: { children: ReactNode }) {
  return (
    <div style={{ margin: "-24px", padding: 24, background: "var(--paper-1)", minHeight: "calc(100vh - 4rem)" }}>
      <div style={{ display: "flex", flexDirection: "column", gap: 18, maxWidth: 1180, margin: "0 auto" }}>{children}</div>
    </div>
  );
}

export function AnalyticsLoading() {
  return (
    <div style={{ display: "flex", alignItems: "center", justifyContent: "center", minHeight: 400 }}>
      <div style={{ width: 28, height: 28, borderRadius: "50%", border: "3px solid var(--line-2)", borderTopColor: "var(--vm-red)", animation: "spin 0.8s linear infinite" }} />
    </div>
  );
}
