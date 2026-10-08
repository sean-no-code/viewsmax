// Chart + table components shared by the follower-growth and engagement cards:
// a fixed 800×250 SVG frame with y ticks, up to six x labels, hover hit-areas
// and a dark tooltip anchored to the hovered column; pill toggles; section and
// table scaffolding. Geometry and style constants live in geometry.ts.
import type { CSSProperties, ReactNode } from "react";
import { dayLabel, fmt } from "@/lib/analytics-performance-mock";
import { TABLE_GRID, X0, X1, Y0, Y1, xScale } from "./geometry";

const MONO: CSSProperties = { font: "500 11px var(--font-mono)", fill: "var(--ink-on-paper-3)" };

interface ChartFrameProps {
  dates: Date[];
  yTicks: { v: number; y: number }[];
  hover: number | null;
  onHover: (i: number | null) => void;
  /** Series layers, drawn under the hover guide. */
  children: ReactNode;
  /** Drawn over the hover guide (e.g. the hovered point). */
  overlay?: ReactNode;
  tooltip?: ReactNode;
  guideColor?: string;
  label: string;
}

export function ChartFrame({ dates, yTicks, hover, onHover, children, overlay, tooltip, guideColor = "var(--ink-on-paper-3)", label }: ChartFrameProps) {
  const n = dates.length, x = xScale(n);
  const k = Math.min(6, n);
  const xTicks = Array.from({ length: k }, (_, j) => { const i = k === 1 ? 0 : Math.round((j * (n - 1)) / (k - 1)); return { x: x(i), label: dayLabel(dates[i]) }; });
  const w = (X1 - X0) / Math.max(n - 1, 1);
  const tipLeft = hover !== null ? (x(hover) / 800) * 100 : 0;

  return (
    <div style={{ position: "relative" }} onMouseLeave={() => onHover(null)}>
      <svg viewBox="0 0 800 250" role="img" aria-label={label} style={{ width: "100%", height: "auto", display: "block", overflow: "visible" }}>
        {yTicks.map((t) => (
          <g key={t.v}>
            <line x1={X0} x2={X1} y1={t.y} y2={t.y} style={{ stroke: "var(--line-1)", strokeWidth: 1 }} />
            <text x={40} y={t.y + 4} textAnchor="end" style={MONO}>{fmt(t.v)}</text>
          </g>
        ))}
        {xTicks.map((t, i) => <text key={i} x={t.x} y={242} textAnchor="middle" style={MONO}>{t.label}</text>)}
        {children}
        {hover !== null && <line x1={x(hover)} x2={x(hover)} y1={Y0} y2={Y1} style={{ stroke: guideColor, strokeWidth: 1, strokeDasharray: "3 3" }} />}
        {overlay}
        {Array.from({ length: n }, (_, i) => (
          <rect key={i} x={x(i) - w / 2} y={0} width={w} height={Y1} onMouseEnter={() => onHover(i)} style={{ fill: "transparent" }} data-col={i} />
        ))}
      </svg>
      {hover !== null && tooltip && (
        <div style={{
          position: "absolute", top: 0, left: `${tipLeft}%`, transform: tipLeft > 70 ? "translateX(calc(-100% - 12px))" : "translateX(12px)",
          pointerEvents: "none", background: "var(--ink-900)", color: "var(--fg-1)", borderRadius: 10, padding: "10px 12px", fontSize: 12,
          display: "flex", flexDirection: "column", gap: 4, minWidth: 150, boxShadow: "var(--shadow-md)", zIndex: 2,
        }}>{tooltip}</div>
      )}
    </div>
  );
}

export function TipRow({ children, value, color, bold }: { children: ReactNode; value: string; color?: string; bold?: boolean }) {
  return (
    <div style={{ display: "flex", justifyContent: "space-between", gap: 12 }}>
      <span style={{ display: "flex", alignItems: "center", gap: 6 }}>{children}</span>
      <span style={{ fontVariantNumeric: "tabular-nums", fontWeight: bold ? 700 : 400, color }}>{value}</span>
    </div>
  );
}

/** Small pill toggle (Total followers / Net per day, Grid / List). */
export function PillToggle<T extends string>({ options, value, onChange, label }: { options: { id: T; label: string }[]; value: T; onChange: (v: T) => void; label: string }) {
  return (
    <div role="group" aria-label={label} style={{ display: "flex", gap: 2, padding: 3, background: "var(--paper-2)", border: "1px solid var(--line-1)", borderRadius: 10 }}>
      {options.map((o) => {
        const on = o.id === value;
        return (
          <button key={o.id} type="button" aria-pressed={on} onClick={() => onChange(o.id)} style={{
            border: 0, cursor: "pointer", padding: "5px 11px", borderRadius: 7, font: "600 12px var(--font-body)",
            background: on ? "var(--paper-0)" : "transparent", color: on ? "var(--ink-on-paper-1)" : "var(--ink-on-paper-2)",
            boxShadow: on ? "0 1px 2px rgba(0,0,0,.08)" : "none",
          }}>{o.label}</button>
        );
      })}
    </div>
  );
}

export function SectionTitle({ title, sub, right }: { title: string; sub: string; right?: ReactNode }) {
  return (
    <div style={{ display: "flex", flexWrap: "wrap", justifyContent: "space-between", alignItems: "flex-start", gap: 12 }}>
      <div style={{ display: "flex", flexDirection: "column", gap: 4 }}>
        <h2 style={{ margin: 0, fontFamily: "var(--font-display)", fontWeight: 700, fontSize: 20, letterSpacing: "-0.01em" }}>{title}</h2>
        <div style={{ fontSize: 13, color: "var(--ink-on-paper-3)" }}>{sub}</div>
      </div>
      {right}
    </div>
  );
}

/** Per-account table header. */
export function TableHead({ cols }: { cols: string[] }) {
  return (
    <div style={{ display: "grid", gridTemplateColumns: TABLE_GRID, gap: 12, padding: "10px 0", fontSize: 12, color: "var(--ink-on-paper-3)", borderBottom: "1px solid var(--line-1)" }}>
      {cols.map((c, i) => <span key={c} title={c} style={{ textAlign: i ? "right" : "left", minWidth: 0, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>{c}</span>)}
    </div>
  );
}
