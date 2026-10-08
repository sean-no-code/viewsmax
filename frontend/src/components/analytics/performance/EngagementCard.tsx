// Engagement: daily engagements stacked by network, plus a per-account table.
import { useMemo, useState } from "react";
import { dayLabel, type ProfileData } from "@/lib/analytics-performance";
import { ChartFrame, SectionTitle, TableHead, TipRow } from "./chart";
import { ROW, SECTION, deltaColor, niceTicks, xScale, yScale } from "./geometry";
import { AccountCell, NetBadge } from "./NetBadge";

export function EngagementCard({ data }: { data: ProfileData }) {
  const [hover, setHover] = useState<number | null>(null);
  const { totals, byNet } = data.engagement;
  const n = totals.length, x = xScale(n);

  const { ticks, y, layers } = useMemo(() => {
    const t = niceTicks(0, Math.max(...totals), 4);
    const yy = yScale(0, t[t.length - 1]);
    const cum = totals.map(() => 0);
    const ls = byNet.map((s) => {
      const lower = cum.slice();
      s.values.forEach((v, i) => { cum[i] += v; });
      const top = cum.map((v, i) => `${x(i).toFixed(1)},${yy(v).toFixed(1)}`);
      const bot = lower.map((v, i) => `${x(i).toFixed(1)},${yy(v).toFixed(1)}`).reverse();
      return { net: s.net, d: `M${top.join(" L")} L${bot.join(" L")} Z` };
    });
    return { ticks: t, y: yy, layers: ls };
  }, [totals, byNet, x]);

  const h = hover !== null && hover < n ? hover : null;

  return (
    <section style={SECTION} aria-label="Engagement">
      <SectionTitle
        title="Engagement"
        sub="Likes, comments and shares gained by day, stacked by network"
        right={
          <div style={{ display: "flex", flexWrap: "wrap", gap: 14, fontSize: 12, color: "var(--ink-on-paper-2)" }}>
            {byNet.map((s) => (
              <span key={s.net.id} style={{ display: "flex", alignItems: "center", gap: 6 }}><NetBadge net={s.net} size={16} ring={s.net.color} />{s.net.name}</span>
            ))}
          </div>
        }
      />
      <ChartFrame
        label="Engagement chart"
        dates={data.dates}
        yTicks={ticks.map((v) => ({ v, y: y(v) }))}
        hover={h}
        onHover={(i) => { if (i !== hover) setHover(i); }}
        guideColor="var(--ink-on-paper-1)"
        tooltip={h !== null && (
          <>
            <div style={{ color: "var(--fg-3)", fontFamily: "var(--font-mono)" }}>{dayLabel(data.dates[h])}</div>
            {[...byNet].reverse().map((s) => (
              <TipRow key={s.net.id} value={s.values[h].toLocaleString("en-US")}>
                <span style={{ width: 7, height: 7, borderRadius: 2, background: s.net.color }} />{s.net.name}
              </TipRow>
            ))}
            <div style={{ borderTop: "1px solid var(--ink-600)", paddingTop: 4, marginTop: 2 }}>
              <TipRow value={totals[h].toLocaleString("en-US")} bold>Total</TipRow>
            </div>
          </>
        )}
      >
        {layers.map((l) => <path key={l.net.id} d={l.d} style={{ fill: l.net.color, opacity: 0.85, stroke: "var(--paper-0)", strokeWidth: 1 }} />)}
      </ChartFrame>

      <div style={{ display: "flex", flexDirection: "column", borderTop: "1px solid var(--line-1)" }}>
        <TableHead cols={["Account", "Engagements", "Eng. rate", "Change"]} />
        {data.engagementRows.map((r) => (
          <div key={r.account.id} style={ROW}>
            <span style={{ display: "flex", alignItems: "center", gap: 10, minWidth: 0 }}>
              <span style={{ flex: "1 1 auto", maxWidth: 190, minWidth: 0, display: "flex" }}><AccountCell account={r.account} /></span>
              <span className="hidden sm:block" style={{ flex: "1 1 60px", height: 6, background: "var(--paper-2)", borderRadius: 3, overflow: "hidden", maxWidth: 140 }}>
                <span style={{ display: "block", height: "100%", width: `${(r.share * 100).toFixed(1)}%`, background: r.net.color }} />
              </span>
            </span>
            <span style={{ textAlign: "right" }}>{r.engagements}</span>
            <span style={{ textAlign: "right" }}>{r.rate}</span>
            <span style={{ textAlign: "right", fontWeight: 600, color: deltaColor(r.delta.dir) }}>{r.delta.label}</span>
          </div>
        ))}
      </div>
    </section>
  );
}
