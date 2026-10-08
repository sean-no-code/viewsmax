// Chart geometry, number formatting helpers and shared inline styles for the
// performance pages (kept out of chart.tsx so that file only exports components).
import type { CSSProperties } from "react";

export const X0 = 48, X1 = 792, Y0 = 12, Y1 = 222;

export function niceTicks(min: number, max: number, n: number): number[] {
  const span = max - min || 1;
  const raw = span / n;
  const mag = Math.pow(10, Math.floor(Math.log10(raw)));
  const step = [1, 2, 2.5, 5, 10].map((s) => s * mag).find((s) => s >= raw) ?? mag * 10;
  const lo = Math.floor(min / step) * step, hi = Math.ceil(max / step) * step;
  const out: number[] = [];
  for (let v = lo; v <= hi + step / 2; v += step) out.push(v);
  return out;
}

/** Column index → x coordinate. */
export const xScale = (n: number) => (i: number) => (n === 1 ? (X0 + X1) / 2 : X0 + ((X1 - X0) * i) / (n - 1));
/** Value → y coordinate for a [lo, hi] domain. */
export const yScale = (lo: number, hi: number) => (v: number) => Y1 - ((v - lo) / (hi - lo || 1)) * (Y1 - Y0);

export const deltaColor = (dir: "up" | "down" | "flat") => (dir === "up" ? "var(--up)" : dir === "down" ? "var(--down)" : "var(--ink-on-paper-3)");

export const SECTION: CSSProperties = {
  background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: "var(--r-md)",
  padding: "22px 24px", display: "flex", flexDirection: "column", gap: 18,
};

/** Per-account tables: 2fr/1fr/1fr/1fr grid. */
export const TABLE_GRID = "minmax(0,2fr) repeat(3,minmax(0,1fr))";
export const ROW: CSSProperties = { display: "grid", gridTemplateColumns: TABLE_GRID, gap: 12, padding: "11px 0", fontSize: 14, borderBottom: "1px solid var(--line-1)", fontVariantNumeric: "tabular-nums", alignItems: "center" };
