// Admin — tracking-links monitor across ALL clients, searchable by user/offer/link.
import { useCallback, useEffect, useState } from "react";
import { Icon, SectionHead } from "@/components/analytics/primitives";
import { AnalyticsLoading } from "@/components/analytics/useAnalytics";
import { PostShell } from "@/components/post/PostList";
import { useAuth } from "@/hooks/useAuth";
import { fmtNum, fmtMoney } from "@/lib/analytics-model";
import { viewsMaxApi, type AdminLinkRow } from "@/lib/api-service";
import { AdminMetricTable, adminSearchStyle, subText, pctCell, type AdminColumn } from "./AdminMetricTable";

const numCell = (v: number, dashZero = false) => (dashZero && v <= 0 ? <span style={{ color: "var(--ink-on-paper-3)" }}>—</span> : fmtNum(v));

const COLUMNS: AdminColumn<AdminLinkRow>[] = [
  { key: "name", head: "Link", sort: (r) => (r.name || "").toLowerCase(), render: (r) => (
    <div style={{ minWidth: 0 }}>
      <div style={{ fontWeight: 700, color: "var(--ink-on-paper-1)" }}>{r.name || "—"}</div>
      <div style={subText}>?trk={r.parameter_id}</div>
    </div>
  ) },
  { key: "placement", head: "Placement", sort: (r) => r.placement, render: (r) => <span style={{ textTransform: "capitalize" }}>{r.placement}</span> },
  { key: "offer", head: "Offer", sort: (r) => (r.offer?.name || "").toLowerCase(), render: (r) => (
    <div style={{ minWidth: 0 }}>
      <div>{r.offer?.name || "—"}</div>
      <div style={subText}>{r.offer?.url}</div>
    </div>
  ) },
  { key: "user", head: "User", sort: (r) => (r.user?.email || "").toLowerCase(), render: (r) => (
    <div style={{ minWidth: 0 }}>
      <div>{r.user?.name || "—"}</div>
      <div style={subText}>{r.user?.email}</div>
    </div>
  ) },
  { key: "views", head: "Views", align: "right", sort: (r) => r.views, render: (r) => numCell(r.views, true) },
  { key: "clicks", head: "Clicks", align: "right", sort: (r) => r.clicks, render: (r) => fmtNum(r.clicks) },
  { key: "conversions", head: "Conv.", align: "right", sort: (r) => r.conversions, render: (r) => r.conversions },
  { key: "view_conversion_rate", head: "CR (reach)", align: "right", sort: (r) => r.view_conversion_rate ?? -1, render: (r) => pctCell(r.view_conversion_rate) },
  { key: "click_conversion_rate", head: "CR (clicks)", align: "right", sort: (r) => r.click_conversion_rate ?? -1, render: (r) => (r.click_conversion_rate == null ? "—" : `${r.click_conversion_rate}%`) },
  { key: "revenue", head: "Revenue", align: "right", sort: (r) => r.revenue, render: (r) => <span style={{ fontFamily: "var(--font-mono)", fontWeight: 700 }}>{fmtMoney(r.revenue)}</span> },
];

export default function AdminLinks() {
  const { user } = useAuth();
  const [rows, setRows] = useState<AdminLinkRow[]>([]);
  const [loading, setLoading] = useState(true);
  const [q, setQ] = useState("");
  const [dq, setDq] = useState("");

  useEffect(() => { const t = setTimeout(() => setDq(q), 300); return () => clearTimeout(t); }, [q]);

  const load = useCallback(async () => {
    setLoading(true);
    const res = await viewsMaxApi.getAdminLinks(dq || undefined);
    setRows(res.success && res.data ? res.data : []);
    setLoading(false);
  }, [dq]);
  useEffect(() => { if (user?.is_admin) load(); }, [load, user?.is_admin]);

  if (!user?.is_admin) {
    return (
      <PostShell>
        <div style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 18, padding: 48, textAlign: "center", color: "var(--ink-on-paper-3)", fontFamily: "var(--font-body)", fontSize: 14 }}>
          <Icon name="alert-circle" size={22} stroke="var(--vm-red)" />
          <div style={{ marginTop: 10, fontWeight: 600 }}>Admins only.</div>
        </div>
      </PostShell>
    );
  }

  return (
    <PostShell max={1560}>
      <SectionHead eyebrow="Admin" title="Links" />
      <div style={{ display: "flex", justifyContent: "flex-end" }}>
        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search link, placement, offer, or user…" style={adminSearchStyle} />
      </div>
      {loading ? (
        <AnalyticsLoading />
      ) : rows.length === 0 ? (
        <div style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 18, padding: 48, textAlign: "center", color: "var(--ink-on-paper-3)", fontFamily: "var(--font-body)", fontSize: 14 }}>No links match.</div>
      ) : (
        <AdminMetricTable rows={rows} columns={COLUMNS} minWidth={1180} initialSort="clicks" />
      )}
    </PostShell>
  );
}
