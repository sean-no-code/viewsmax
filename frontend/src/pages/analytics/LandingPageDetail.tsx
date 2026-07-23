// Analytics — Landing Page detail: performance + inline install-snippet panel
// (reuses the existing ViewsMax tracker snippet) + conversion goal + links.
import { useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { useAuth } from "@/hooks/useAuth";
import { CARD, Icon, AreaChart, StatusDot, Segmented, Btn, Chip, MetricCard, CodeBlock, Funnel, type FunnelStage } from "@/components/analytics/primitives";
import { PlatformGlyph } from "@/components/analytics/primitives";
import { AnalyticsShell, AnalyticsLoading, useFunnlModel, useTimeseries, RANGE_OPTS, type Range } from "@/components/analytics/useAnalytics";
import { fmtNum, fmtMoney, fmtMoneyK, fmtPct, fmtFull, buildSnippet, type PageMetric, type LinkMetric } from "@/lib/analytics-model";
import { Modal } from "@/components/analytics/Modal";
import { viewsMaxApi } from "@/lib/api-service";
import { toast } from "sonner";

function BackBar({ to, label }: { to: string; label: string }) {
  const navigate = useNavigate();
  return (
    <button onClick={() => navigate(to)} style={{ display: "inline-flex", alignItems: "center", gap: 7, background: "none", border: "none", cursor: "pointer", padding: 0, fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 13, color: "var(--ink-on-paper-2)", alignSelf: "flex-start" }}>
      <Icon name="arrow-left" size={15} stroke="var(--ink-on-paper-2)" />{label}
    </button>
  );
}

function Body({ page, links }: { page: PageMetric; links: LinkMetric[] }) {
  const navigate = useNavigate();
  const { user } = useAuth();
  const [range, setRange] = useState<Range>("28d");
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [deleting, setDeleting] = useState(false);

  const handleDelete = async () => {
    setDeleting(true);
    const res = await viewsMaxApi.deleteTrackingEvent(Number(page.id));
    if (res.success) {
      toast.success("Landing page deleted.");
      navigate("/dashboard/analytics/landing-pages");
    } else {
      toast.error(res.error || "Couldn't delete the landing page.");
      setDeleting(false);
      setConfirmOpen(false);
    }
  };
  const { points } = useTimeseries(range, page.id);
  const series = points.map((p) => p.revenue);
  const pageLinks = links.filter((l) => l.page === page.id).sort((a, b) => b.rev - a.rev);
  const snippet = buildSnippet((user as { public_id?: string } | null)?.public_id || "");

  const stages: FunnelStage[] = [
    { label: "Link clicks", count: page.raw.total_clicks || 0, icon: "mouse-pointer-click", color: "var(--data-violet)" },
    { label: "Reached " + page.conv.url, count: page.conversions, icon: "badge-check", color: "var(--vm-volt-deep)" },
  ];

  return (
    <>
      <BackBar to="/dashboard/analytics/landing-pages" label="Landing pages" />
      <div style={{ display: "flex", alignItems: "flex-start", justifyContent: "space-between", gap: 16, flexWrap: "wrap" }}>
        <div>
          <h1 style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 30, letterSpacing: "-.03em", margin: 0, color: "var(--ink-on-paper-1)" }}>{page.name}</h1>
          <div style={{ display: "flex", alignItems: "center", gap: 14, marginTop: 8 }}>
            <span style={{ fontFamily: "var(--font-mono)", fontSize: 13, color: "var(--ink-on-paper-2)", display: "flex", alignItems: "center", gap: 6 }}><Icon name="link" size={13} stroke="var(--ink-on-paper-3)" />{page.url}</span>
            <StatusDot live={page.live} />
          </div>
        </div>
        <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
          <Segmented options={RANGE_OPTS} value={range} onChange={(v) => setRange(v as Range)} />
          <Btn kind="ghost" icon="trash-2" onClick={() => setConfirmOpen(true)}>Delete</Btn>
        </div>
      </div>

      <div style={{ display: "grid", gridTemplateColumns: "1.55fr 1fr", gap: 16, alignItems: "start" }}>
        {/* LEFT — performance */}
        <div style={{ display: "flex", flexDirection: "column", gap: 16 }}>
          <div style={{ display: "grid", gridTemplateColumns: "repeat(4,minmax(0,1fr))", gap: 12 }}>
            <MetricCard dense label="Views" value={page.viewsSince > 0 ? fmtNum(page.viewsSince) : "—"} />
            <MetricCard dense label="Sales" value={String(page.conversions)} />
            <MetricCard dense label="Revenue" value={fmtMoneyK(page.rev)} />
            <MetricCard dense label="Conv. rate" value={page.viewCr == null ? "—" : fmtPct(page.viewCr)} accentColor="var(--vm-volt-deep)" />
          </div>
          <div style={{ ...CARD, padding: "20px 20px 12px" }}>
            <div style={{ fontFamily: "var(--font-display)", fontWeight: 700, fontSize: 16, color: "var(--ink-on-paper-1)", marginBottom: 10 }}>{page.live ? "Revenue over time" : "No data yet"}</div>
            {page.live
              ? <AreaChart data={series} height={180} color="var(--vm-red)" gradId={"pg" + page.id} />
              : <div style={{ height: 180, display: "grid", placeItems: "center", color: "var(--ink-on-paper-3)", fontFamily: "var(--font-body)", fontSize: 13.5, textAlign: "center" }}><div><Icon name="radio" size={26} stroke="var(--ink-on-paper-3)" /><div style={{ marginTop: 8 }}>Waiting for the first tracked visitor.<br />Install the snippet to start.</div></div></div>}
          </div>
          <div style={{ ...CARD, overflow: "hidden" }}>
            <div style={{ padding: "15px 20px", fontFamily: "var(--font-display)", fontWeight: 700, fontSize: 16, color: "var(--ink-on-paper-1)", borderBottom: "1px solid var(--line-1)" }}>Links driving to this page</div>
            {pageLinks.length ? pageLinks.map((l, i) => (
              <div key={l.id} onClick={() => navigate(`/dashboard/analytics/links/${l.id}`)} style={{ display: "flex", alignItems: "center", gap: 13, padding: "13px 20px", borderTop: i ? "1px solid var(--paper-2)" : "none", cursor: "pointer" }}
                onMouseEnter={(e) => (e.currentTarget.style.background = "var(--paper-1)")} onMouseLeave={(e) => (e.currentTarget.style.background = "transparent")}>
                <PlatformGlyph id={l.platform} size={30} />
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ fontSize: 13.5, fontWeight: 600, color: "var(--ink-on-paper-1)", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>{l.label}</div>
                  <div style={{ fontFamily: "var(--font-mono)", fontSize: 11, color: "var(--ink-on-paper-3)", marginTop: 2 }}>{fmtFull(l.clicks)} clicks · {l.conv} sales</div>
                </div>
                <span style={{ fontFamily: "var(--font-mono)", fontWeight: 700, fontSize: 13.5, color: "var(--ink-on-paper-1)" }}>{fmtMoney(l.rev)}</span>
              </div>
            )) : <div style={{ padding: 28, textAlign: "center", color: "var(--ink-on-paper-3)", fontSize: 13.5 }}>No links point here yet — add one from the Links tab.</div>}
          </div>
        </div>

        {/* RIGHT — install + conversion */}
        <div style={{ display: "flex", flexDirection: "column", gap: 16 }}>
          <div style={{ ...CARD, padding: 20 }}>
            <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", marginBottom: 14 }}>
              <div style={{ display: "flex", alignItems: "center", gap: 9 }}><Icon name="code-xml" size={18} stroke="var(--vm-red)" /><span style={{ fontFamily: "var(--font-display)", fontWeight: 700, fontSize: 16, color: "var(--ink-on-paper-1)" }}>Install tracking</span></div>
              <StatusDot live={page.live} />
            </div>
            <div style={{ fontFamily: "var(--font-body)", fontSize: 13, color: "var(--ink-on-paper-2)", lineHeight: 1.5, marginBottom: 14 }}>Paste this snippet inside <span style={{ fontFamily: "var(--font-mono)", color: "var(--ink-on-paper-1)" }}>&lt;head&gt;</span> on <span style={{ fontFamily: "var(--font-mono)", color: "var(--ink-on-paper-1)" }}>{page.url}</span>.</div>
            <CodeBlock label="ViewsMax tracking" code={snippet} />
            {!page.live && <div style={{ display: "flex", alignItems: "center", gap: 8, marginTop: 14 }}><span style={{ fontFamily: "var(--font-body)", fontSize: 12, color: "var(--ink-on-paper-3)" }}>No events received yet.</span></div>}
          </div>
          <div style={{ ...CARD, padding: 20 }}>
            <div style={{ display: "flex", alignItems: "center", gap: 9, marginBottom: 14 }}><Icon name="target" size={18} stroke="var(--vm-volt-deep)" /><span style={{ fontFamily: "var(--font-display)", fontWeight: 700, fontSize: 16, color: "var(--ink-on-paper-1)" }}>Conversion goal</span></div>
            <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", padding: "12px 14px", background: "var(--paper-1)", borderRadius: 12, border: "1px solid var(--line-1)", marginBottom: 10 }}>
              <span style={{ fontFamily: "var(--font-mono)", fontSize: 13, color: "var(--ink-on-paper-1)" }}>{page.conv.url}</span>
              <Chip tone="aqua">{page.conv.value ? fmtMoney(page.conv.value) : "lead"}</Chip>
            </div>
            <div style={{ fontFamily: "var(--font-body)", fontSize: 12, color: "var(--ink-on-paper-2)", lineHeight: 1.5 }}>{page.conv.label} event · fires once per visitor session.</div>
          </div>
          {page.live && <div style={{ ...CARD, padding: 20 }}>
            <div style={{ fontFamily: "var(--font-display)", fontWeight: 700, fontSize: 15, color: "var(--ink-on-paper-1)", marginBottom: 14 }}>On-page funnel</div>
            <Funnel stages={stages} dense />
          </div>}
        </div>
      </div>

      {confirmOpen && (
        <Modal title="Delete this landing page?" sub={page.name} onClose={() => !deleting && setConfirmOpen(false)} width={460}>
          <p style={{ fontFamily: "var(--font-body)", fontSize: 13.5, color: "var(--ink-on-paper-2)", lineHeight: 1.6, margin: "0 0 20px" }}>
            This removes the offer and frees a slot on your plan. Any tracking links pointing here will stop working. This can't be undone.
          </p>
          <div style={{ display: "flex", justifyContent: "flex-end", gap: 10 }}>
            <Btn kind="ghost" onClick={() => !deleting && setConfirmOpen(false)}>Cancel</Btn>
            <Btn kind="primary" icon="trash-2" onClick={deleting ? undefined : handleDelete}>
              {deleting ? "Deleting…" : "Delete page"}
            </Btn>
          </div>
        </Modal>
      )}
    </>
  );
}

export default function LandingPageDetail() {
  const { id } = useParams();
  const { model, loading } = useFunnlModel();
  if (loading || !model) return <AnalyticsShell><AnalyticsLoading /></AnalyticsShell>;
  const page = model.pages.find((p) => p.id === id);
  return (
    <AnalyticsShell>
      {page ? <Body page={page} links={model.links} /> : (
        <>
          <BackBar to="/dashboard/analytics/landing-pages" label="Landing pages" />
          <div style={{ ...CARD, padding: 48, textAlign: "center", color: "var(--ink-on-paper-3)" }}>Landing page not found.</div>
        </>
      )}
    </AnalyticsShell>
  );
}
