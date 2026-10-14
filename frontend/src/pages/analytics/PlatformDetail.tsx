// Analytics — Platform detail: drill into one traffic source.
import { useNavigate, useParams } from "react-router-dom";
import { useTranslation } from "react-i18next";
import { CARD, Icon, Funnel, PlatformGlyph, MetricCard, type FunnelStage } from "@/components/analytics/primitives";
import { AnalyticsShell, AnalyticsLoading, useFunnlModel } from "@/components/analytics/useAnalytics";
import { fmtMoney, fmtFull, fmtNum, fmtPct } from "@/lib/analytics-model";

export default function PlatformDetail() {
  const { id } = useParams();
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { model, loading } = useFunnlModel();

  if (loading || !model) return <AnalyticsShell><AnalyticsLoading /></AnalyticsShell>;
  const p = model.platforms.find((x) => x.id === id);
  if (!p) return <AnalyticsShell><div style={{ ...CARD, padding: 48, textAlign: "center", color: "var(--ink-on-paper-3)" }}>{t("analytics.platform.notFound", "Source not found.")}</div></AnalyticsShell>;

  const pLinks = model.links.filter((l) => l.platform === p.id).sort((a, b) => b.rev - a.rev);
  const stages: FunnelStage[] = [
    { label: t("analytics.platform.funnelClicks", "Clicks"), count: p.clicks, icon: "mouse-pointer-click", color: p.color },
    { label: t("analytics.platform.funnelConverted", "Converted"), count: p.conv, icon: "badge-check", color: "var(--vm-volt-deep)" },
  ];

  return (
    <AnalyticsShell>
      <button onClick={() => navigate("/dashboard/analytics/overview")} style={{ display: "inline-flex", alignItems: "center", gap: 7, background: "none", border: "none", cursor: "pointer", padding: 0, fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 13, color: "var(--ink-on-paper-2)", alignSelf: "flex-start" }}>
        <Icon name="arrow-left" size={15} stroke="var(--ink-on-paper-2)" />{t("analytics.platform.backToOverview", "Overview")}
      </button>
      <div style={{ display: "flex", alignItems: "center", gap: 16 }}>
        <PlatformGlyph id={p.id} size={52} />
        <div>
          <h1 style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 30, letterSpacing: "-.03em", margin: 0, color: "var(--ink-on-paper-1)" }}>{p.name}</h1>
          <div style={{ fontFamily: "var(--font-body)", fontSize: 13, color: "var(--ink-on-paper-2)", marginTop: 6 }}>{t("analytics.platform.summary", { count: pLinks.length, rate: p.viewCr == null ? "—" : fmtPct(p.viewCr), defaultValue_one: "{{count}} tracked link · {{rate}} convert", defaultValue_other: "{{count}} tracked links · {{rate}} convert" })}</div>
        </div>
      </div>
      <div style={{ display: "grid", gridTemplateColumns: "repeat(4,1fr)", gap: 12 }}>
        <MetricCard dense label={t("analytics.platform.metricClicks", "Clicks")} value={fmtNum(p.clicks)} />
        <MetricCard dense label={t("analytics.platform.metricSales", "Sales")} value={String(p.conv)} accentColor="var(--vm-volt-deep)" />
        <MetricCard dense label={t("analytics.platform.metricRevenue", "Revenue")} value={fmtMoney(p.rev)} />
        <MetricCard dense label={t("analytics.platform.metricPerClick", "$ / click")} value={fmtMoney(p.epc, 2)} />
      </div>
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1.4fr", gap: 16, alignItems: "start" }}>
        <div style={{ ...CARD, padding: "22px 24px" }}>
          <div style={{ fontFamily: "var(--font-display)", fontWeight: 700, fontSize: 16, color: "var(--ink-on-paper-1)", marginBottom: 18 }}>{t("analytics.platform.funnel", "Funnel")}</div>
          <Funnel stages={stages} />
        </div>
        <div style={{ ...CARD, overflow: "hidden" }}>
          <div style={{ padding: "15px 20px", fontFamily: "var(--font-display)", fontWeight: 700, fontSize: 16, color: "var(--ink-on-paper-1)", borderBottom: "1px solid var(--line-1)" }}>{t("analytics.platform.contentFrom", "Content from {{name}}", { name: p.name })}</div>
          {pLinks.map((l, i) => (
            <div key={l.id} onClick={() => navigate(`/dashboard/monetization/links/${l.id}`)} style={{ display: "flex", alignItems: "center", gap: 13, padding: "14px 20px", borderTop: i ? "1px solid var(--paper-2)" : "none", cursor: "pointer" }}
              onMouseEnter={(e) => (e.currentTarget.style.background = "var(--paper-1)")} onMouseLeave={(e) => (e.currentTarget.style.background = "transparent")}>
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ fontSize: 13.5, fontWeight: 600, color: "var(--ink-on-paper-1)", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>{l.label}</div>
                <div style={{ fontFamily: "var(--font-mono)", fontSize: 11, color: "var(--ink-on-paper-3)", marginTop: 2 }}>{t("analytics.platform.linkStats", "{{clicks}} clicks · {{sales}} sales · {{rate}}", { clicks: fmtFull(l.clicks), sales: l.conv, rate: l.viewCr == null ? "—" : fmtPct(l.viewCr, 1) })}</div>
              </div>
              <span style={{ fontFamily: "var(--font-mono)", fontWeight: 700, fontSize: 13.5, color: "var(--ink-on-paper-1)" }}>{fmtMoney(l.rev)}</span>
            </div>
          ))}
          {pLinks.length === 0 && <div style={{ padding: 28, textAlign: "center", color: "var(--ink-on-paper-3)", fontSize: 13.5 }}>{t("analytics.platform.empty", "No content from this source yet.")}</div>}
        </div>
      </div>
    </AnalyticsShell>
  );
}
