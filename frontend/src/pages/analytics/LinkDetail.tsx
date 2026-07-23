// Analytics — Link detail: the full click → sale story for one piece of content.
import { useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { CARD, Icon, AreaChart, Funnel, Chip, Segmented, PlatformGlyph, MetricCard, type FunnelStage } from "@/components/analytics/primitives";
import { AnalyticsShell, AnalyticsLoading, useFunnlModel, RANGE_OPTS, type Range } from "@/components/analytics/useAnalytics";
import { fmtMoney, fmtFull, fmtNum, fmtPct, platformMeta, type LinkMetric, type FunnlModel } from "@/lib/analytics-model";

function CopyUrl({ url }: { url: string }) {
  const [c, setC] = useState(false);
  return (
    <button onClick={(e) => { e.stopPropagation(); navigator.clipboard?.writeText("https://" + url).catch(() => {}); setC(true); setTimeout(() => setC(false), 1400); }}
      style={{ display: "inline-flex", alignItems: "center", gap: 7, background: "var(--paper-1)", border: "1px solid var(--line-1)", borderRadius: 999, padding: "6px 12px", cursor: "pointer", fontFamily: "var(--font-mono)", fontSize: 12.5, color: "var(--ink-on-paper-1)" }}>
      {url}<Icon name={c ? "check" : "copy"} size={13} stroke={c ? "var(--vm-volt-deep)" : "var(--ink-on-paper-3)"} />
    </button>
  );
}

function Body({ link, model }: { link: LinkMetric; model: FunnlModel }) {
  const navigate = useNavigate();
  const [range, setRange] = useState<Range>("28d");
  const meta = platformMeta(link.platform);
  const pg = model.pages.find((p) => p.id === link.page);
  const stages: FunnelStage[] = [
    { label: "Clicked the link", count: link.clicks, icon: "mouse-pointer-click", color: meta.color },
    { label: "Converted", count: link.conv, icon: "badge-check", color: "var(--vm-volt-deep)" },
  ];
  // Illustrative trend shaped by the link's totals (no per-link daily series yet).
  const trend = Array.from({ length: 7 }, (_, i) => link.rev * (0.5 + (i / 6) * 0.6) / 6);

  return (
    <>
      <button onClick={() => navigate(-1)} style={{ display: "inline-flex", alignItems: "center", gap: 7, background: "none", border: "none", cursor: "pointer", padding: 0, fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 13, color: "var(--ink-on-paper-2)", alignSelf: "flex-start" }}>
        <Icon name="arrow-left" size={15} stroke="var(--ink-on-paper-2)" />Back
      </button>
      <div style={{ display: "flex", alignItems: "flex-start", justifyContent: "space-between", gap: 16, flexWrap: "wrap" }}>
        <div style={{ display: "flex", gap: 16, minWidth: 0 }}>
          <PlatformGlyph id={link.platform} size={52} />
          <div style={{ minWidth: 0 }}>
            <h1 style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 28, letterSpacing: "-.03em", margin: 0, color: "var(--ink-on-paper-1)" }}>{link.label}</h1>
            <div style={{ display: "flex", alignItems: "center", gap: 12, marginTop: 9, flexWrap: "wrap" }}>
              <span style={{ fontFamily: "var(--font-body)", fontSize: 12.5, color: "var(--ink-on-paper-2)" }}>{link.detail}</span>
              <CopyUrl url={link.short} />
            </div>
          </div>
        </div>
        <Segmented options={RANGE_OPTS} value={range} onChange={(v) => setRange(v as Range)} />
      </div>

      <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit,minmax(130px,1fr))", gap: 12 }}>
        <MetricCard dense label="Visitors" value={fmtFull(link.visitors)} />
        <MetricCard dense label="Content Views" value={link.viewsSince > 0 ? fmtNum(link.viewsSince) : "—"} />
        <MetricCard dense label="Clicks" value={fmtFull(link.clicks)} />
        <MetricCard dense label="Sales" value={String(link.conv)} accentColor="var(--vm-volt-deep)" />
        <MetricCard dense label="Revenue" value={fmtMoney(link.rev)} />
        <MetricCard dense label="$ / click" value={fmtMoney(link.epc, 2)} />
        <MetricCard dense label="Conv. rate" value={link.viewCr == null ? "—" : fmtPct(link.viewCr)} accentColor={link.viewCr == null ? undefined : "var(--vm-volt-deep)"} />
      </div>

      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 16, alignItems: "start" }}>
        <div style={{ ...CARD, padding: "22px 24px" }}>
          <div style={{ fontFamily: "var(--font-display)", fontWeight: 700, fontSize: 16, color: "var(--ink-on-paper-1)", marginBottom: 4 }}>Click → sale</div>
          <div style={{ fontFamily: "var(--font-body)", fontSize: 12.5, color: "var(--ink-on-paper-3)", marginBottom: 18 }}>How this link's traffic moved through the funnel.</div>
          <Funnel stages={stages} />
        </div>
        <div style={{ display: "flex", flexDirection: "column", gap: 16 }}>
          <div style={{ ...CARD, padding: "20px 20px 16px" }}>
            <div style={{ fontFamily: "var(--font-display)", fontWeight: 700, fontSize: 16, color: "var(--ink-on-paper-1)", marginBottom: 4 }}>Clicks by platform</div>
            <div style={{ fontFamily: "var(--font-body)", fontSize: 12.5, color: "var(--ink-on-paper-3)", marginBottom: 14 }}>Where this link's clicks actually came from (by referrer).</div>
            {Object.keys(link.platformBreakdown).length === 0 ? (
              <div style={{ fontFamily: "var(--font-body)", fontSize: 13, color: "var(--ink-on-paper-3)", paddingBottom: 6 }}>No clicks yet.</div>
            ) : (() => {
              const entries = Object.entries(link.platformBreakdown).sort((a, b) => b[1] - a[1]);
              const max = entries[0][1] || 1;
              return entries.map(([key, count]) => (
                <div key={key} style={{ display: "flex", alignItems: "center", gap: 10, marginBottom: 7 }}>
                  <span style={{ width: 96, fontFamily: "var(--font-body)", fontSize: 12.5, fontWeight: 600, color: "var(--ink-on-paper-2)", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>{platformMeta(key).name}</span>
                  <span style={{ flex: 1, height: 9, borderRadius: 999, background: "var(--paper-2)", overflow: "hidden" }}>
                    <span style={{ display: "block", width: `${Math.max(6, (count / max) * 100)}%`, height: "100%", borderRadius: 999, background: meta.color }} />
                  </span>
                  <span style={{ fontFamily: "var(--font-mono)", fontSize: 12.5, fontWeight: 700, color: "var(--ink-on-paper-1)" }}>{fmtFull(count)}</span>
                </div>
              ));
            })()}
          </div>
          <div style={{ ...CARD, padding: "20px 20px 12px" }}>
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", marginBottom: 8 }}>
              <div style={{ fontFamily: "var(--font-display)", fontWeight: 700, fontSize: 16, color: "var(--ink-on-paper-1)" }}>Revenue trend</div>
              <Chip tone={link.rev > 0 ? "up" : "ghost"}>{link.rev > 0 ? "▲ Earning" : "Low volume"}</Chip>
            </div>
            <AreaChart data={link.clicks ? trend : [0, 0, 0, 0, 0, 0, 0]} height={150} color={meta.color} gradId={"lk" + link.id} />
          </div>
          <div style={{ ...CARD, padding: 20, display: "flex", flexDirection: "column", gap: 14 }}>
            <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between" }}>
              <span style={{ fontFamily: "var(--font-mono)", fontSize: 10.5, letterSpacing: ".05em", textTransform: "uppercase", color: "var(--ink-on-paper-3)" }}>Source</span>
              <span style={{ display: "flex", alignItems: "center", gap: 8, fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 13.5, color: "var(--ink-on-paper-1)" }}><PlatformGlyph id={link.platform} size={22} radius={6} />{meta.name}</span>
            </div>
            <div style={{ borderTop: "1px solid var(--paper-2)" }} />
            <button onClick={() => pg && navigate(`/dashboard/monetization/offers/${pg.id}`)} style={{ display: "flex", alignItems: "center", justifyContent: "space-between", background: "none", border: "none", cursor: pg ? "pointer" : "default", padding: 0 }}>
              <span style={{ fontFamily: "var(--font-mono)", fontSize: 10.5, letterSpacing: ".05em", textTransform: "uppercase", color: "var(--ink-on-paper-3)" }}>Destination</span>
              <span style={{ display: "flex", alignItems: "center", gap: 6, fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 13.5, color: "var(--vm-red)" }}>{pg?.name || "—"}{pg && <Icon name="arrow-up-right" size={15} stroke="var(--vm-red)" />}</span>
            </button>
            <div style={{ borderTop: "1px solid var(--paper-2)" }} />
            <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between" }}>
              <span style={{ fontFamily: "var(--font-mono)", fontSize: 10.5, letterSpacing: ".05em", textTransform: "uppercase", color: "var(--ink-on-paper-3)" }}>Counts as</span>
              <span style={{ display: "flex", alignItems: "center", gap: 8, fontFamily: "var(--font-mono)", fontSize: 13, color: "var(--ink-on-paper-1)" }}>{pg?.conv.url}<Chip tone="aqua">{pg?.conv.value ? fmtMoney(pg.conv.value) : "lead"}</Chip></span>
            </div>
          </div>
        </div>
      </div>
    </>
  );
}

export default function LinkDetail() {
  const { id } = useParams();
  const { model, loading } = useFunnlModel();
  if (loading || !model) return <AnalyticsShell><AnalyticsLoading /></AnalyticsShell>;
  const link = model.links.find((l) => l.id === id);
  return (
    <AnalyticsShell>
      {link ? <Body link={link} model={model} /> : (
        <div style={{ ...CARD, padding: 48, textAlign: "center", color: "var(--ink-on-paper-3)" }}>Link not found.</div>
      )}
    </AnalyticsShell>
  );
}
