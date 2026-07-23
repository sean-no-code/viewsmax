// Dual-axis analytics chart: visitors + clicks (left axis), content-views
// trend (own hidden scale) and a smooth green revenue line/area (right axis),
// with a hover guide + tooltip and a CLICKABLE legend — toggling a series
// hides it and rescales the axes to what's still visible. Ported from the
// ViewsMax dashboard design kit and made dynamic over the tracking series.
import { useMemo, useState } from "react";

export interface ChartPoint {
  label: string; // x-axis label, e.g. "12 Jun"
  clicks: number;
  visitors?: number;
  views: number;
  revenue: number;
}

type SeriesKey = "clicks" | "visitors" | "views" | "revenue";

// Geometry (matches the design board's proportions; scales via viewBox).
const W = 940, H = 320, X0 = 44, X1 = 892, TOP = 22, BOT = 274;

// "Nice" rounded ceiling for an axis so gridline labels read cleanly.
function niceMax(v: number): number {
  if (v <= 0) return 1;
  const pow = Math.pow(10, Math.floor(Math.log10(v)));
  const n = v / pow;
  const step = n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10;
  return step * pow;
}

const fmtAxisMoney = (n: number) =>
  n >= 1000 ? "$" + (n / 1000).toFixed(n % 1000 === 0 ? 0 : 1) + "k" : "$" + Math.round(n);

// Catmull-Rom → cubic-bezier smoothing for the revenue line.
function smooth(ps: [number, number][]): string {
  if (!ps.length) return "";
  let d = "M " + ps[0][0].toFixed(1) + " " + ps[0][1].toFixed(1);
  for (let i = 0; i < ps.length - 1; i++) {
    const p0 = ps[i - 1] || ps[i], p1 = ps[i], p2 = ps[i + 1], p3 = ps[i + 2] || p2;
    const c1x = p1[0] + (p2[0] - p0[0]) / 6, c1y = p1[1] + (p2[1] - p0[1]) / 6;
    const c2x = p2[0] - (p3[0] - p1[0]) / 6, c2y = p2[1] - (p3[1] - p1[1]) / 6;
    d += ` C ${c1x.toFixed(1)} ${c1y.toFixed(1)}, ${c2x.toFixed(1)} ${c2y.toFixed(1)}, ${p2[0].toFixed(1)} ${p2[1].toFixed(1)}`;
  }
  return d;
}

export function RevenueChart({ points, gradId = "vmRevArea" }: { points: ChartPoint[]; gradId?: string }) {
  const [hover, setHover] = useState<number | null>(null);
  // Click a legend entry to hide/show its series (e.g. leave only Revenue).
  const [hidden, setHidden] = useState<Set<SeriesKey>>(new Set());
  const toggle = (key: SeriesKey) =>
    setHidden((h) => {
      const next = new Set(h);
      if (next.has(key)) next.delete(key);
      else if (next.size < 3) next.add(key); // never hide the last series
      return next;
    });
  const on = (key: SeriesKey) => !hidden.has(key);

  // Revenue = green, clicks = red, content views = blue, visitors = purple.
  const GREEN = "var(--up)", RED = "var(--vm-red)", BLUE = "#2551F5", PURPLE = "#9B6BFF";

  const empty = { label: "", clicks: 0, visitors: 0, views: 0, revenue: 0 };
  const data = points.length ? points : [empty, empty];
  const N = data.length;
  const clicks = data.map((p) => p.clicks);
  const visitors = data.map((p) => p.visitors ?? 0);
  const views = data.map((p) => p.views);
  const revenue = data.map((p) => p.revenue);

  // Left axis is shared by the count series (clicks + visitors) — scale to
  // whatever's visible so hiding a spiky series zooms the rest.
  const cMax = useMemo(() => {
    const candidates = [1, ...(on("clicks") ? clicks : []), ...(on("visitors") ? visitors : [])];
    return niceMax(Math.max(...candidates) * 1.12);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [points, hidden]);
  const vMax = Math.max(...views, 1) * 1.12; // content-views trend, own scale
  const rMax = niceMax(Math.max(...revenue, 1) * 1.08);
  const step = (X1 - X0) / Math.max(1, N - 1);

  const xAt = (i: number) => X0 + (i / Math.max(1, N - 1)) * (X1 - X0);
  const yC = (v: number) => BOT - (v / cMax) * (BOT - TOP);
  const yV = (v: number) => BOT - (v / vMax) * (BOT - TOP);
  const yR = (v: number) => BOT - (v / rMax) * (BOT - TOP);

  const pathOf = (arr: number[], y: (v: number) => number) => smooth(arr.map((v, i) => [xAt(i), y(v)] as [number, number]));
  const clicksLine = pathOf(clicks, yC);
  const visitorsLine = pathOf(visitors, yC);
  const viewsLine = pathOf(views, yV);
  const line = pathOf(revenue, yR);
  const area = line + ` L ${X1.toFixed(1)} ${BOT} L ${X0.toFixed(1)} ${BOT} Z`;

  // Left-axis (counts) gridlines + right-axis (revenue) tick labels.
  const cTicks = [0, 0.25, 0.5, 0.75, 1].map((f) => Math.round(cMax * f));
  const rTicks = [0, 0.5, 1].map((f) => Math.round(rMax * f));

  // Evenly spaced x labels (≈8 max) so dense ranges stay readable.
  const labelEvery = Math.max(1, Math.ceil(N / 8));

  const LEGEND: Array<{ key: SeriesKey; label: string; color: string }> = [
    { key: "visitors", label: "Visitors", color: PURPLE },
    { key: "clicks", label: "Clicks", color: RED },
    { key: "views", label: "Content Views", color: BLUE },
    { key: "revenue", label: "Revenue", color: GREEN },
  ];

  return (
    <div style={{ position: "relative", width: "100%" }} onMouseLeave={() => setHover(null)}>
      {hover != null && (() => {
        const leftPct = (xAt(hover) / W) * 100;
        return (
          <div style={{
            position: "absolute", left: leftPct + "%", top: 2, transform: "translateX(-50%)",
            background: "var(--ink-900)", color: "var(--paper-1)", borderRadius: 9, padding: "8px 11px",
            pointerEvents: "none", fontFamily: "var(--font-mono)", fontSize: 11, whiteSpace: "nowrap",
            boxShadow: "0 8px 24px -6px rgba(0,0,0,.4)", zIndex: 5,
          }}>
            <div style={{ fontWeight: 700, marginBottom: 3 }}>{data[hover].label}</div>
            <div style={{ display: "flex", gap: 10 }}>
              {on("visitors") && <span style={{ color: PURPLE }}>● {visitors[hover].toLocaleString("en-US")} visitors</span>}
              {on("clicks") && <span style={{ color: "var(--vm-red-hot)" }}>● {clicks[hover].toLocaleString("en-US")} clicks</span>}
              {on("views") && <span style={{ color: BLUE }}>● {views[hover].toLocaleString("en-US")} content views</span>}
              {on("revenue") && <span style={{ color: "var(--up)" }}>${revenue[hover].toLocaleString("en-US")}</span>}
            </div>
          </div>
        );
      })()}

      <svg viewBox={`0 0 ${W} ${H}`} width="100%" height={H} preserveAspectRatio="xMidYMid meet" style={{ display: "block", overflow: "visible" }}>
        <defs>
          <linearGradient id={gradId} x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stopColor={GREEN} stopOpacity={0.30} />
            <stop offset="100%" stopColor={GREEN} stopOpacity={0.02} />
          </linearGradient>
        </defs>

        {/* gridlines + left (counts) axis labels */}
        {cTicks.map((g, k) => {
          const y = yC(g);
          return (
            <g key={"g" + k}>
              <line x1={X0} x2={X1} y1={y} y2={y} stroke="var(--line-1)" strokeWidth={1} strokeDasharray={g === 0 ? "0" : "3 4"} />
              <text x={X0 - 8} y={y + 3} textAnchor="end" fontFamily="var(--font-mono)" fontSize={10} fill="var(--ink-on-paper-3)">{g >= 1000 ? (g / 1000).toFixed(g % 1000 === 0 ? 0 : 1) + "k" : g}</text>
            </g>
          );
        })}
        {/* right (revenue) axis labels */}
        {on("revenue") && rTicks.map((p, k) => (
          <text key={"r" + k} x={X1 + 8} y={yR(p) + 3} textAnchor="start" fontFamily="var(--font-mono)" fontSize={10} fill="var(--ink-on-paper-3)">{fmtAxisMoney(p)}</text>
        ))}

        {/* content-views trend line (blue) */}
        {on("views") && <path d={viewsLine} fill="none" stroke={BLUE} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" opacity={0.85} />}

        {/* visitors line (purple) */}
        {on("visitors") && <path d={visitorsLine} fill="none" stroke={PURPLE} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" opacity={0.9} />}

        {/* clicks line (red) */}
        {on("clicks") && <path d={clicksLine} fill="none" stroke={RED} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" />}

        {/* revenue area + line + dots (green) */}
        {on("revenue") && (
          <g>
            <path d={area} fill={`url(#${gradId})`} />
            <path d={line} fill="none" stroke={GREEN} strokeWidth={2.8} strokeLinecap="round" strokeLinejoin="round" />
            {revenue.map((v, i) => <circle key={"d" + i} cx={xAt(i)} cy={yR(v)} r={2.6} fill="var(--paper-0)" stroke={GREEN} strokeWidth={1.6} />)}
          </g>
        )}

        {/* hover guide */}
        {hover != null && (
          <g>
            <line x1={xAt(hover)} x2={xAt(hover)} y1={TOP} y2={BOT} stroke="var(--line-2)" strokeWidth={1} />
            {on("revenue") && <circle cx={xAt(hover)} cy={yR(revenue[hover])} r={5} fill={GREEN} stroke="var(--paper-0)" strokeWidth={2.5} />}
          </g>
        )}

        {/* baseline + x labels */}
        <line x1={X0} x2={X1} y1={BOT} y2={BOT} stroke="var(--line-1)" strokeWidth={1} />
        {data.map((p, i) => (i % labelEvery === 0 || i === N - 1) && p.label
          ? <text key={"x" + i} x={xAt(i)} y={BOT + 18} textAnchor="middle" fontFamily="var(--font-mono)" fontSize={10} fill="var(--ink-on-paper-3)">{p.label}</text>
          : null)}

        {/* hover hit areas */}
        {data.map((_, i) => (
          <rect key={"h" + i} x={xAt(i) - step / 2} y={TOP} width={step} height={BOT - TOP} fill="transparent" onMouseEnter={() => setHover(i)} />
        ))}
      </svg>

      {/* Clickable legend — click to hide/show a series. */}
      <div style={{ display: "flex", gap: 14, justifyContent: "center", marginTop: 6, flexWrap: "wrap" }}>
        {LEGEND.map((s) => {
          const active = on(s.key);
          return (
            <button
              key={s.key}
              onClick={() => toggle(s.key)}
              title={active ? `Hide ${s.label}` : `Show ${s.label}`}
              style={{
                display: "flex", alignItems: "center", gap: 6, fontFamily: "var(--font-mono)", fontSize: 11,
                color: "var(--ink-on-paper-3)", background: "none", border: "none", cursor: "pointer", padding: "2px 4px",
                opacity: active ? 1 : 0.38, textDecoration: active ? "none" : "line-through",
              }}
            >
              <span style={{ width: 14, height: 3, borderRadius: 2, background: s.color }} /> {s.label}
            </button>
          );
        })}
      </div>
    </div>
  );
}
