// Conversion-events breakdown table: dark header, a totals row, one row per
// tracked link (content), sortable by revenue. Acquisition (sources/referrer
// URLs) lives in the separate GA-style TrafficSources tables — this table is
// about what each piece of CONTENT produced. Ported from the ViewsMax
// dashboard design board.
import { useMemo, useState, type CSSProperties } from "react";
import { fmtFull, fmtMoney, type LinkMetric } from "@/lib/analytics-model";

interface Row {
  id: string;
  source: string;
  views: number;
  clicks: number;
  calls: number;
  emails: number;
  sales: number;
  revenue: number;
}

const GRID = "minmax(0,2.4fr) repeat(5, minmax(76px,1fr)) minmax(88px,1fr)";
const headCell: CSSProperties = { textAlign: "right", fontFamily: "var(--font-mono)", fontSize: 10.5, letterSpacing: ".05em", textTransform: "uppercase", color: "rgba(255,255,255,.6)", fontWeight: 600 };
const numCell: CSSProperties = { textAlign: "right", fontFamily: "var(--font-mono)", fontSize: 12.5, color: "var(--ink-on-paper-2)" };
const totalNum: CSSProperties = { textAlign: "right", fontFamily: "var(--font-mono)", fontWeight: 700, fontSize: 13, color: "var(--ink-on-paper-1)" };

export function ConversionEventsTable({
  links, rangeLabel = "this period", onOpenContent,
}: {
  links: LinkMetric[];
  rangeLabel?: string;
  onOpenContent?: (id: string) => void;
}) {
  const [asc, setAsc] = useState(false);

  const rows = useMemo<Row[]>(() => {
    const base: Row[] = links.map((l) => ({
      id: l.id, source: l.label,
      views: l.viewsSince, clicks: l.clicks, calls: l.calls, emails: l.emails, sales: l.conv, revenue: l.rev,
    }));
    return base.sort((a, b) => (asc ? a.revenue - b.revenue : b.revenue - a.revenue));
  }, [asc, links]);

  const totals = useMemo(() => rows.reduce(
    (t, r) => ({ views: t.views + r.views, clicks: t.clicks + r.clicks, calls: t.calls + r.calls, emails: t.emails + r.emails, sales: t.sales + r.sales, revenue: t.revenue + r.revenue }),
    { views: 0, clicks: 0, calls: 0, emails: 0, sales: 0, revenue: 0 },
  ), [rows]);

  return (
    <div style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 18, boxShadow: "0 1px 2px rgba(10,10,12,.04)", overflow: "hidden" }}>
      {/* caption */}
      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", padding: "14px 18px 10px" }}>
        <div style={{ fontFamily: "var(--font-display)", fontWeight: 700, fontSize: 15.5, color: "var(--ink-on-paper-1)" }}>Content</div>
        <div style={{ fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 12, color: "var(--ink-on-paper-3)" }}>Conversion events · {rangeLabel}</div>
      </div>

      <div style={{ overflowX: "auto" }}>
        <div style={{ minWidth: 840 }}>
          {/* dark header row */}
          <div style={{ display: "grid", gridTemplateColumns: GRID, alignItems: "center", padding: "11px 18px", background: "var(--ink-900)" }}>
            <div style={{ ...headCell, textAlign: "left" }}>Content</div>
            <div style={headCell}>Content Views</div>
            <div style={headCell}>Clicks</div>
            <div style={headCell}>Call bookings</div>
            <div style={headCell}>Emails</div>
            <div style={headCell}>Sales</div>
            <button onClick={() => setAsc((v) => !v)} style={{ justifySelf: "end", display: "flex", alignItems: "center", gap: 4, border: "none", background: "none", cursor: "pointer", fontFamily: "var(--font-mono)", fontSize: 10.5, letterSpacing: ".05em", textTransform: "uppercase", color: "#fff", fontWeight: 700 }}>
              ($) <span style={{ fontSize: 11 }}>{asc ? "↑" : "↓"}</span>
            </button>
          </div>

          {/* totals row */}
          <div style={{ display: "grid", gridTemplateColumns: GRID, alignItems: "center", padding: "12px 18px", borderBottom: "2px solid var(--line-1)" }}>
            <div style={{ fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 13, color: "var(--ink-on-paper-1)" }}>All content</div>
            <div style={totalNum}>{fmtFull(totals.views)}</div>
            <div style={totalNum}>{fmtFull(totals.clicks)}</div>
            <div style={totalNum}>{fmtFull(totals.calls)}</div>
            <div style={totalNum}>{fmtFull(totals.emails)}</div>
            <div style={totalNum}>{fmtFull(totals.sales)}</div>
            <div style={{ ...totalNum, fontWeight: 800, color: "var(--vm-red)" }}>{fmtMoney(totals.revenue)}</div>
          </div>

          {/* data rows */}
          {rows.length === 0 ? (
            <div style={{ padding: 28, textAlign: "center", color: "var(--ink-on-paper-3)", fontSize: 13.5, fontFamily: "var(--font-body)" }}>No conversion events yet.</div>
          ) : rows.map((r, idx) => (
            <div key={r.id} onClick={() => onOpenContent?.(r.id)}
              style={{ display: "grid", gridTemplateColumns: GRID, alignItems: "center", padding: "11px 18px", background: idx % 2 === 1 ? "var(--paper-1)" : "transparent", cursor: onOpenContent ? "pointer" : "default" }}
              onMouseEnter={(e) => { if (onOpenContent) e.currentTarget.style.background = "var(--paper-2)"; }}
              onMouseLeave={(e) => { e.currentTarget.style.background = idx % 2 === 1 ? "var(--paper-1)" : "transparent"; }}>
              <div style={{ fontFamily: "var(--font-body)", fontWeight: 500, fontSize: 13, color: "var(--ink-on-paper-1)", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis", paddingRight: 14 }}>{r.source}</div>
              <div style={numCell}>{r.views > 0 ? fmtFull(r.views) : "—"}</div>
              <div style={numCell}>{fmtFull(r.clicks)}</div>
              <div style={numCell}>{fmtFull(r.calls)}</div>
              <div style={numCell}>{fmtFull(r.emails)}</div>
              <div style={numCell}>{fmtFull(r.sales)}</div>
              <div style={{ ...numCell, fontWeight: 700, color: "var(--ink-on-paper-1)" }}>{fmtMoney(r.revenue)}</div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
