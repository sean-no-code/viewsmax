// Shared analytics views: platform leaderboard + top-content table.
import { Icon, PlatformGlyph, RankBar } from "@/components/analytics/primitives";
import { fmtMoney, fmtFull, fmtPct, platformMeta, type PlatformMetric, type LinkMetric } from "@/lib/analytics-model";

type LeaderMetric = "rev" | "clicks" | "conv";

export function PlatformLeaderboard({ platforms, metric = "rev", onOpen, dense, max = 7 }: {
  platforms: PlatformMetric[]; metric?: LeaderMetric; onOpen?: (id: string) => void; dense?: boolean; max?: number;
}) {
  const rows = [...platforms].sort((a, b) => b[metric] - a[metric]).slice(0, max);
  if (!rows.length) return <Empty label="No source data yet." />;
  const top = rows[0][metric] || 1;
  const valOf = (r: PlatformMetric) => (metric === "rev" ? fmtMoney(r.rev) : metric === "clicks" ? fmtFull(r.clicks) : fmtFull(r.conv));
  return (
    <div style={{ display: "flex", flexDirection: "column", gap: dense ? 12 : 16 }}>
      {rows.map((r) => (
        <div key={r.id} onClick={() => onOpen?.(r.id)} style={{ display: "grid", gridTemplateColumns: "auto 1fr auto", alignItems: "center", gap: 13, cursor: onOpen ? "pointer" : "default" }}>
          <PlatformGlyph id={r.id} size={dense ? 30 : 34} />
          <div style={{ minWidth: 0 }}>
            <div style={{ display: "flex", alignItems: "baseline", justifyContent: "space-between", gap: 10, marginBottom: 6 }}>
              <span style={{ fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 13.5, color: "var(--ink-on-paper-1)" }}>{r.name}</span>
              <span style={{ display: "flex", alignItems: "baseline", gap: 8 }}>
                <span style={{ fontFamily: "var(--font-mono)", fontWeight: 700, fontSize: 13.5, color: "var(--ink-on-paper-1)", fontVariantNumeric: "tabular-nums" }}>{valOf(r)}</span>
                <span style={{ fontFamily: "var(--font-mono)", fontSize: 11, color: "var(--ink-on-paper-3)", width: 46, textAlign: "right" }}>{r.viewCr == null ? "—" : fmtPct(r.viewCr)}</span>
              </span>
            </div>
            <RankBar pct={(r[metric] / top) * 100} color={r.color} height={dense ? 6 : 8} />
          </div>
          <Icon name="chevron-right" size={16} stroke="var(--ink-on-paper-3)" style={{ opacity: onOpen ? 1 : 0 }} />
        </div>
      ))}
    </div>
  );
}

type SortKey = "rev" | "conv" | "clicks";
export function TopContentTable({ links, limit, onOpen, dense, sort = "rev" }: {
  links: LinkMetric[]; limit?: number; onOpen?: (id: string) => void; dense?: boolean; sort?: SortKey;
}) {
  const rows = [...links].sort((a, b) => b[sort] - a[sort]).slice(0, limit || links.length);
  const pad = dense ? "9px 18px" : "13px 20px";
  const cols: [string, "left" | "right"][] = [["Content", "left"], ["Source", "left"], ["Clicks", "right"], ["Sales", "right"], ["Revenue", "right"], ["Conv %", "right"]];
  if (!rows.length) return <Empty label="No tracked links yet." />;
  return (
    <table style={{ width: "100%", borderCollapse: "collapse" }}>
      <thead><tr>{cols.map(([h, a]) => (
        <th key={h} style={{ textAlign: a, padding: dense ? "8px 18px" : "10px 20px", fontFamily: "var(--font-mono)", fontSize: 10.5, letterSpacing: ".05em", textTransform: "uppercase", color: "var(--ink-on-paper-3)", fontWeight: 600, borderBottom: "1px solid var(--line-1)" }}>{h}</th>
      ))}</tr></thead>
      <tbody>{rows.map((l, i) => {
        const meta = platformMeta(l.platform);
        return (
          <tr key={l.id} onClick={() => onOpen?.(l.id)} style={{ borderTop: i ? "1px solid var(--paper-2)" : "none", cursor: onOpen ? "pointer" : "default" }}
            onMouseEnter={(e) => { if (onOpen) e.currentTarget.style.background = "var(--paper-1)"; }}
            onMouseLeave={(e) => { e.currentTarget.style.background = "transparent"; }}>
            <td style={{ padding: pad, maxWidth: 340 }}>
              <div style={{ display: "flex", alignItems: "center", gap: 12, minWidth: 0 }}>
                <PlatformGlyph id={l.platform} size={dense ? 26 : 30} />
                <div style={{ minWidth: 0 }}>
                  <div style={{ fontSize: 13.5, fontWeight: 600, color: "var(--ink-on-paper-1)", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>{l.label}</div>
                  {!dense && <div style={{ fontFamily: "var(--font-mono)", fontSize: 11, color: "var(--ink-on-paper-3)", marginTop: 2 }}>{l.short}</div>}
                </div>
              </div>
            </td>
            <td style={{ padding: pad, fontSize: 12.5, color: "var(--ink-on-paper-2)", whiteSpace: "nowrap" }}>{meta.name}</td>
            <td style={{ textAlign: "right", padding: pad, fontFamily: "var(--font-mono)", fontSize: 13, color: "var(--ink-on-paper-2)" }}>{fmtFull(l.clicks)}</td>
            <td style={{ textAlign: "right", padding: pad, fontFamily: "var(--font-mono)", fontSize: 13, color: "var(--ink-on-paper-1)" }}>{l.conv}</td>
            <td style={{ textAlign: "right", padding: pad, fontFamily: "var(--font-mono)", fontWeight: 700, fontSize: 13, color: "var(--ink-on-paper-1)" }}>{fmtMoney(l.rev)}</td>
            <td style={{ textAlign: "right", padding: pad }}>
              <span style={{ fontFamily: "var(--font-mono)", fontWeight: 700, fontSize: 12, color: (l.viewCr ?? 0) >= 1.5 ? "var(--fg-on-volt)" : "var(--ink-on-paper-2)", background: (l.viewCr ?? 0) >= 1.5 ? "var(--vm-volt)" : "var(--paper-2)", padding: "3px 8px", borderRadius: 7 }}>{l.viewCr == null ? "—" : fmtPct(l.viewCr, 1)}</span>
            </td>
          </tr>
        );
      })}</tbody>
    </table>
  );
}

export function Empty({ label }: { label: string }) {
  return <div style={{ padding: 28, textAlign: "center", color: "var(--ink-on-paper-3)", fontSize: 13.5, fontFamily: "var(--font-body)" }}>{label}</div>;
}
