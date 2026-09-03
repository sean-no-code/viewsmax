// Per-platform follower-growth cards for the Audience Growth page. Each card
// shows the current follower count, the day-over-day delta across the range,
// and a small area chart of the series — degrading to a clear "no data yet" /
// "not supported" state when the platform can't (yet) supply follower counts.
import { type CSSProperties } from "react";
import { CARD, AreaChart } from "@/components/analytics/primitives";
import { PlacementIcon } from "@/components/analytics/PlacementIcon";
import { fmtFull } from "@/lib/analytics-model";
import type { AudiencePlatformSeries } from "@/lib/api-service";

const PLATFORM_LABEL: Record<string, string> = {
  youtube: "YouTube", tiktok: "TikTok", instagram: "Instagram",
  x: "X", linkedin: "LinkedIn", threads: "Threads", facebook: "Facebook",
};

const muted: CSSProperties = { fontFamily: "var(--font-body)", fontSize: 12.5, color: "var(--ink-on-paper-3)", lineHeight: 1.5 };

function DeltaPill({ value }: { value: number }) {
  const up = value > 0, flat = value === 0;
  const color = flat ? "var(--ink-on-paper-3)" : up ? "var(--up)" : "var(--vm-red)";
  const bg = flat ? "var(--paper-2)" : up ? "color-mix(in srgb, var(--up) 12%, transparent)" : "var(--vm-red-tint-l)";
  return (
    <span style={{ fontFamily: "var(--font-mono)", fontSize: 11.5, fontWeight: 700, color, background: bg, borderRadius: 999, padding: "3px 9px", whiteSpace: "nowrap" }}>
      {up ? "+" : ""}{fmtFull(value)}
    </span>
  );
}

export function AudiencePlatformCards({ platforms, loading }: { platforms: AudiencePlatformSeries[]; loading: boolean }) {
  if (loading) {
    return (
      <div style={{ ...CARD, padding: 28, textAlign: "center", ...muted }}>Loading audience…</div>
    );
  }

  if (!platforms.length) {
    return (
      <div style={{ ...CARD, padding: 28, textAlign: "center", ...muted }}>
        No connected accounts yet. Connect a platform to start tracking audience growth.
      </div>
    );
  }

  return (
    <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill, minmax(300px, 1fr))", gap: 16 }}>
      {platforms.map((p) => {
        const label = PLATFORM_LABEL[p.platform] ?? p.platform;
        const hasData = p.points.length > 0;
        return (
          <div key={p.account_id} style={{ ...CARD, padding: "16px 18px", display: "flex", flexDirection: "column", gap: 12, minWidth: 0 }}>
            <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 10 }}>
              <div style={{ display: "flex", alignItems: "center", gap: 10, minWidth: 0 }}>
                <PlacementIcon placement={p.platform} size={26} />
                <div style={{ minWidth: 0 }}>
                  <div style={{ fontFamily: "var(--font-display)", fontWeight: 700, fontSize: 15, color: "var(--ink-on-paper-1)" }}>{label}</div>
                  {p.account_name && <div style={{ fontFamily: "var(--font-mono)", fontSize: 10.5, color: "var(--ink-on-paper-3)", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>{p.account_name}</div>}
                </div>
              </div>
              {p.supported && hasData && <DeltaPill value={p.delta} />}
            </div>

            {p.supported && hasData ? (
              <>
                <div style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 32, lineHeight: 1, letterSpacing: "-.02em", fontVariantNumeric: "tabular-nums", color: "var(--ink-on-paper-1)" }}>
                  {fmtFull(p.current ?? 0)}
                  <span style={{ fontFamily: "var(--font-mono)", fontSize: 11, fontWeight: 500, color: "var(--ink-on-paper-3)", marginLeft: 8 }}>followers</span>
                </div>
                <AreaChart data={p.points.map((pt) => pt.followers)} height={90} gradId={`aud-${p.account_id}`} />
              </>
            ) : (
              <div style={{ ...muted, padding: "8px 0 4px" }}>
                {!p.supported
                  ? `${label} doesn't expose follower stats through its API yet.`
                  : "No follower data yet — the first daily snapshot builds the trend. If you connected before audience tracking, reconnect to enable it."}
              </div>
            )}
          </div>
        );
      })}
    </div>
  );
}
