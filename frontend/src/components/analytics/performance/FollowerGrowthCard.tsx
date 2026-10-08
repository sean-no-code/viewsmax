// Follower growth: total followers as an area line (broken where no account
// has a snapshot yet), or net growth per day as bars.
import { useMemo, useState } from "react";
import { dayLabel, signed, type ProfileData } from "@/lib/analytics-performance";
import { ChartFrame, PillToggle, SectionTitle, TableHead, TipRow } from "./chart";
import { ROW, SECTION, X0, X1, deltaColor, niceTicks, xScale, yScale } from "./geometry";
import { AccountCell } from "./NetBadge";

export type FollowerView = "total" | "net";
const VIEWS: { id: FollowerView; label: string }[] = [{ id: "total", label: "Total followers" }, { id: "net", label: "Net per day" }];

/** Runs of consecutive indexes with a value. */
function segments(vals: (number | null)[]): number[][] {
  const out: number[][] = [];
  let run: number[] = [];
  vals.forEach((v, i) => { if (v === null) { if (run.length) out.push(run); run = []; } else run.push(i); });
  if (run.length) out.push(run);
  return out;
}

export function FollowerGrowthCard({ data, view, onViewChange }: { data: ProfileData; view: FollowerView; onViewChange: (v: FollowerView) => void }) {
  const [hover, setHover] = useState<number | null>(null);
  const isTotal = view === "total";
  const totals = data.followers.total;
  const nets = data.followers.net;
  const n = data.dates.length, x = xScale(n);

  const { ticks, y } = useMemo(() => {
    const known = totals.filter((v): v is number => v !== null);
    const t = isTotal
      ? niceTicks(known.length ? Math.min(...known) : 0, known.length ? Math.max(...known) : 1, 4)
      : niceTicks(Math.min(0, ...nets), Math.max(0, ...nets), 4);
    return { ticks: t, y: yScale(t[0], t[t.length - 1]) };
  }, [totals, nets, isTotal]);

  const paths = useMemo(() => segments(totals).map((seg) => {
    const line = "M" + seg.map((i) => `${x(i).toFixed(1)},${y(totals[i] as number).toFixed(1)}`).join(" L");
    return { line, area: `${line} L${x(seg[seg.length - 1])},${y(ticks[0])} L${x(seg[0])},${y(ticks[0])} Z` };
  }), [totals, ticks, x, y]);
  const bw = Math.max(1, ((X1 - X0) / n) * 0.62);

  const h = hover !== null && hover < n ? hover : null;
  const net = h !== null ? nets[h] : 0;

  return (
    <section style={SECTION} aria-label="Follower growth">
      <SectionTitle title="Follower growth" sub="Selected sources, by day" right={<PillToggle options={VIEWS} value={view} onChange={onViewChange} label="Follower chart view" />} />
      <ChartFrame
        label="Follower growth chart"
        dates={data.dates}
        yTicks={ticks.map((v) => ({ v, y: y(v) }))}
        hover={h}
        onHover={(i) => { if (i !== hover) setHover(i); }}
        overlay={isTotal && h !== null && totals[h] !== null ? <circle cx={x(h)} cy={y(totals[h] as number)} r={5} style={{ fill: "var(--paper-0)", stroke: "var(--vm-volt-deep)", strokeWidth: 2.5 }} /> : null}
        tooltip={h !== null && (
          <>
            <div style={{ color: "var(--fg-3)", fontFamily: "var(--font-mono)" }}>{dayLabel(data.dates[h])}</div>
            <TipRow value={totals[h] === null ? "–" : (totals[h] as number).toLocaleString("en-US")} bold>Followers</TipRow>
            <TipRow value={signed(net)} bold color={net >= 0 ? "var(--vm-volt)" : "var(--down)"}>Net growth</TipRow>
          </>
        )}
      >
        {isTotal ? (
          paths.map((p, i) => (
            <g key={i}>
              <path d={p.area} style={{ fill: "var(--vm-volt-tint-l)" }} />
              <path d={p.line} style={{ fill: "none", stroke: "var(--vm-volt-deep)", strokeWidth: 2.5, strokeLinejoin: "round" }} />
            </g>
          ))
        ) : (
          nets.map((v, i) => (
            <rect key={i} x={x(i) - bw / 2} y={Math.min(y(v), y(0))} width={bw} height={Math.max(1, Math.abs(y(v) - y(0)))} rx={2}
              style={{ fill: v >= 0 ? "var(--vm-volt-deep)" : "var(--down)", opacity: h === null || h === i ? 1 : 0.45 }} />
          ))
        )}
      </ChartFrame>

      <div style={{ display: "flex", flexDirection: "column", borderTop: "1px solid var(--line-1)" }}>
        <TableHead cols={["Account", "Followers", "Net growth", "Change"]} />
        {data.followerRows.map((r) => (
          <div key={r.account.id} style={ROW}>
            <AccountCell account={r.account} />
            <span style={{ textAlign: "right" }}>{r.followers}</span>
            <span style={{ textAlign: "right" }}>{r.growth}</span>
            <span style={{ textAlign: "right", fontWeight: 600, color: deltaColor(r.delta.dir) }}>{r.delta.label}</span>
          </div>
        ))}
      </div>
    </section>
  );
}
