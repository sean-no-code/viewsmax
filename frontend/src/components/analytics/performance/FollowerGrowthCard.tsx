// Follower growth: total followers as an area line, or net growth per day as bars.
import { useMemo, useState } from "react";
import { dayLabel, signed, type ProfileData } from "@/lib/analytics-performance-mock";
import { ChartFrame, PillToggle, SectionTitle, TableHead, TipRow } from "./chart";
import { ROW, SECTION, X0, X1, deltaColor, niceTicks, xScale, yScale } from "./geometry";
import { AccountCell } from "./NetBadge";

export type FollowerView = "total" | "net";
const VIEWS: { id: FollowerView; label: string }[] = [{ id: "total", label: "Total followers" }, { id: "net", label: "Net per day" }];

export function FollowerGrowthCard({ data, view, onViewChange }: { data: ProfileData; view: FollowerView; onViewChange: (v: FollowerView) => void }) {
  const [hover, setHover] = useState<number | null>(null);
  const isTotal = view === "total";
  const vals = isTotal ? data.followers.total : data.followers.net;
  const n = vals.length, x = xScale(n);

  const { ticks, y } = useMemo(() => {
    const t = niceTicks(isTotal ? Math.min(...vals) : Math.min(0, ...vals), Math.max(...vals), 4);
    return { ticks: t, y: yScale(t[0], t[t.length - 1]) };
  }, [vals, isTotal]);

  const line = "M" + vals.map((v, i) => `${x(i).toFixed(1)},${y(v).toFixed(1)}`).join(" L");
  const area = `${line} L${x(n - 1)},${y(ticks[0])} L${x(0)},${y(ticks[0])} Z`;
  const bw = Math.max(1, ((X1 - X0) / n) * 0.62);

  const h = hover !== null && hover < n ? hover : null;
  const net = h !== null ? data.followers.net[h] : 0;

  return (
    <section style={SECTION} aria-label="Follower growth">
      <SectionTitle title="Follower growth" sub="Selected sources, by day" right={<PillToggle options={VIEWS} value={view} onChange={onViewChange} label="Follower chart view" />} />
      <ChartFrame
        label="Follower growth chart"
        dates={data.dates}
        yTicks={ticks.map((v) => ({ v, y: y(v) }))}
        hover={h}
        onHover={(i) => { if (i !== hover) setHover(i); }}
        overlay={isTotal && h !== null ? <circle cx={x(h)} cy={y(vals[h])} r={5} style={{ fill: "var(--paper-0)", stroke: "var(--vm-volt-deep)", strokeWidth: 2.5 }} /> : null}
        tooltip={h !== null && (
          <>
            <div style={{ color: "var(--fg-3)", fontFamily: "var(--font-mono)" }}>{dayLabel(data.dates[h])}</div>
            <TipRow value={data.followers.total[h].toLocaleString("en-US")} bold>Followers</TipRow>
            <TipRow value={signed(net)} bold color={net >= 0 ? "var(--vm-volt)" : "var(--down)"}>Net growth</TipRow>
          </>
        )}
      >
        {isTotal ? (
          <>
            <path d={area} style={{ fill: "var(--vm-volt-tint-l)" }} />
            <path d={line} style={{ fill: "none", stroke: "var(--vm-volt-deep)", strokeWidth: 2.5, strokeLinejoin: "round" }} />
          </>
        ) : (
          vals.map((v, i) => (
            <rect key={i} x={x(i) - bw / 2} y={Math.min(y(v), y(0))} width={bw} height={Math.max(1, Math.abs(y(v) - y(0)))} rx={2}
              style={{ fill: v >= 0 ? "var(--vm-volt-deep)" : "var(--down)", opacity: h === null || h === i ? 1 : 0.45 }} />
          ))
        )}
      </ChartFrame>

      <div style={{ display: "flex", flexDirection: "column", borderTop: "1px solid var(--line-1)" }}>
        <TableHead cols={["Account", "Followers", "Net growth", "Change"]} />
        {data.followerRows.map((r) => (
          <div key={r.account.id} style={ROW}>
            <AccountCell account={r.account} net={r.net} />
            <span style={{ textAlign: "right" }}>{r.followers}</span>
            <span style={{ textAlign: "right" }}>{r.growth}</span>
            <span style={{ textAlign: "right", fontWeight: 600, color: deltaColor(r.delta.dir) }}>{r.delta.label}</span>
          </div>
        ))}
      </div>
    </section>
  );
}
