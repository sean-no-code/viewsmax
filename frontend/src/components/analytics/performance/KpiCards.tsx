import type { Kpi } from "@/lib/analytics-performance";
import { deltaColor } from "./geometry";

export function KpiCards({ kpis }: { kpis: Kpi[] }) {
  return (
    <section aria-label="Key metrics" style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit,minmax(200px,1fr))", gap: 16 }}>
      {kpis.map((k) => (
        <div key={k.label} style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: "var(--r-md)", padding: "18px 20px", display: "flex", flexDirection: "column", gap: 6 }}>
          <div style={{ fontSize: 13, color: "var(--ink-on-paper-2)" }}>{k.label}</div>
          <div style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 30, letterSpacing: "-0.02em", fontVariantNumeric: "tabular-nums" }}>{k.value}</div>
          <div style={{ fontSize: 13, fontWeight: 600, color: deltaColor(k.delta.dir) }}>
            {k.delta.label} <span style={{ fontWeight: 400, color: "var(--ink-on-paper-3)" }}>vs previous period</span>
          </div>
        </div>
      ))}
    </section>
  );
}
