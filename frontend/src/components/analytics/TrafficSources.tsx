// GA-style acquisition tables: WHERE visitors came from. Two cards side by
// side — Sources (traffic grouped by classified platform: google, x,
// direct, …) with grouped visitor counts, and Referrers (the full referring
// URLs). Pageview-based; until a site has pageview beacons the backend falls
// back to tracking-link click data and says so.
import { type CSSProperties } from "react";
import { fmtFull, platformMeta } from "@/lib/analytics-model";
import type { TrafficSourcesData } from "@/lib/api-service";
import { PlacementIcon } from "@/components/analytics/PlacementIcon";

const CARD_STYLE: CSSProperties = { background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 18, boxShadow: "0 1px 2px rgba(10,10,12,.04)", overflow: "hidden" };
const headCell: CSSProperties = { textAlign: "right", fontFamily: "var(--font-mono)", fontSize: 10.5, letterSpacing: ".05em", textTransform: "uppercase", color: "rgba(255,255,255,.6)", fontWeight: 600 };
const numCell: CSSProperties = { textAlign: "right", fontFamily: "var(--font-mono)", fontSize: 12.5, color: "var(--ink-on-paper-2)" };

// Sources the classifier emits that aren't post placements (no brand glyph).
const GENERIC_SOURCE_LABELS: Record<string, string> = {
  direct: "Direct",
  google: "Google",
  bing: "Bing",
  duckduckgo: "DuckDuckGo",
  pinterest: "Pinterest",
  reddit: "Reddit",
  other: "Other",
};

function sourceLabel(key: string): string {
  return GENERIC_SOURCE_LABELS[key] ?? platformMeta(key).name;
}

function TableShell({ title, caption, columns, children, empty }: {
  title: string; caption: string; columns: string[]; children: React.ReactNode; empty: boolean;
}) {
  const grid = `minmax(0,2.4fr) repeat(${columns.length - 1}, minmax(76px,1fr))`;
  return (
    <div style={CARD_STYLE}>
      <div style={{ padding: "14px 18px 10px" }}>
        <div style={{ fontFamily: "var(--font-display)", fontWeight: 700, fontSize: 15.5, color: "var(--ink-on-paper-1)" }}>{title}</div>
        <div style={{ fontFamily: "var(--font-body)", fontSize: 12, color: "var(--ink-on-paper-3)", marginTop: 2 }}>{caption}</div>
      </div>
      <div style={{ display: "grid", gridTemplateColumns: grid, alignItems: "center", padding: "9px 18px", background: "var(--ink-900)" }}>
        {columns.map((c, i) => <div key={c} style={{ ...headCell, textAlign: i === 0 ? "left" : "right" }}>{c}</div>)}
      </div>
      {empty ? (
        <div style={{ padding: 24, textAlign: "center", color: "var(--ink-on-paper-3)", fontSize: 13, fontFamily: "var(--font-body)" }}>
          No traffic in this period yet.
        </div>
      ) : children}
    </div>
  );
}

export function TrafficSources({ data, loading }: { data: TrafficSourcesData | null; loading: boolean }) {
  const grid3 = "minmax(0,2.4fr) repeat(2, minmax(76px,1fr))";

  if (loading) {
    return (
      <div style={{ ...CARD_STYLE, padding: 28, textAlign: "center", color: "var(--ink-on-paper-3)", fontFamily: "var(--font-body)", fontSize: 13 }}>
        Loading traffic sources…
      </div>
    );
  }

  const sources = data?.sources ?? [];
  const referrers = data?.referrers ?? [];

  return (
    <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 16, alignItems: "start" }}>
      <TableShell
        title="Sources"
        caption={data?.basis === "clicks" ? "Where link clicks came from (site-wide visitor data appears once your pages report views)." : "Where your visitors came from."}
        columns={["Source", "Visitors", data?.basis === "clicks" ? "Clicks" : "Views"]}
        empty={sources.length === 0}
      >
        {sources.map((s, idx) => (
          <div key={s.source} style={{ display: "grid", gridTemplateColumns: grid3, alignItems: "center", padding: "10px 18px", background: idx % 2 === 1 ? "var(--paper-1)" : "transparent" }}>
            <div style={{ display: "flex", alignItems: "center", gap: 9, minWidth: 0 }}>
              <PlacementIcon placement={s.source} size={20} />
              <span style={{ fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 13, color: "var(--ink-on-paper-1)", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>{sourceLabel(s.source)}</span>
            </div>
            <div style={{ ...numCell, fontWeight: 700, color: "var(--ink-on-paper-1)" }}>{fmtFull(s.visitors)}</div>
            <div style={numCell}>{fmtFull(s.views)}</div>
          </div>
        ))}
      </TableShell>

      <TableShell
        title="Referrers"
        caption="The exact URLs that sent the traffic."
        columns={["Referrer URL", "Visitors", data?.basis === "clicks" ? "Clicks" : "Views"]}
        empty={referrers.length === 0}
      >
        {referrers.map((r, idx) => (
          <div key={r.referrer} style={{ display: "grid", gridTemplateColumns: grid3, alignItems: "center", padding: "10px 18px", background: idx % 2 === 1 ? "var(--paper-1)" : "transparent" }}>
            <a
              href={r.referrer}
              target="_blank"
              rel="noopener noreferrer"
              title={r.referrer}
              style={{ fontFamily: "var(--font-mono)", fontSize: 12, color: "var(--vm-volt-deep)", textDecoration: "none", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis", paddingRight: 12 }}
            >
              {r.referrer}
            </a>
            <div style={{ ...numCell, fontWeight: 700, color: "var(--ink-on-paper-1)" }}>{fmtFull(r.visitors)}</div>
            <div style={numCell}>{fmtFull(r.views)}</div>
          </div>
        ))}
      </TableShell>
    </div>
  );
}
