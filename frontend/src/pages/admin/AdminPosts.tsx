// Admin — publishing monitor across ALL clients. Server-gated by role:admin;
// this page also hides itself from non-admins for good measure.
import { useCallback, useEffect, useRef, useState, type CSSProperties } from "react";
import { toast } from "sonner";
import { Icon, SectionHead } from "@/components/analytics/primitives";
import { AnalyticsLoading } from "@/components/analytics/useAnalytics";
import { PostShell, TargetStatusBadge, effectiveCaption, fmtDateTime, isInboxDelivery, publishedUrl, INBOX_NOTE } from "@/components/post/PostList";
import { useAuth } from "@/hooks/useAuth";
import { viewsMaxApi, type AdminPost, type PostTarget, type TikTokLogEntry } from "@/lib/api-service";
import type { AdminPostStats, AdminReconcileReport } from "@/lib/api-service";

const fmtWhen = (p: AdminPost) => fmtDateTime(p.scheduled_at || p.created_at);

// Platforms available for the platform filter dropdown.
const PLATFORM_OPTIONS = ["youtube", "tiktok", "instagram", "x", "linkedin", "threads", "facebook"];
// Target states that mean a posted post still has work outstanding.
const UNFINISHED = ["pending", "publishing", "failed"];

// A scheduled post whose time has passed (the scheduler should have published it).
const isOverdue = (p: AdminPost) =>
  p.status === "scheduled" && !!p.scheduled_at && new Date(p.scheduled_at.replace(" ", "T")).getTime() < Date.now();

// Whether the per-row requeue/publish action should be offered.
const needsRequeue = (p: AdminPost) =>
  isOverdue(p) || (p.status === "posted" && (p.targets || []).some((t) => UNFINISHED.includes(t.status)));

const STAT_CARDS: { key: keyof AdminPostStats; label: string; color: string }[] = [
  { key: "published", label: "Published", color: "var(--up)" },
  { key: "failed", label: "Failed", color: "var(--vm-red)" },
  { key: "publishing", label: "Publishing", color: "var(--warn)" },
  { key: "pending", label: "Pending", color: "var(--ink-on-paper-3)" },
  { key: "posts", label: "Posts", color: "var(--ink-on-paper-1)" },
];

// "scheduled" filters by POST status; the rest filter by per-platform TARGET status.
const FILTERS = ["all", "scheduled", "published", "failed", "publishing", "pending"] as const;

const mono = { fontFamily: "var(--font-mono)", fontSize: 11.5 } as const;

const chipStyle = (active: boolean): CSSProperties => ({
  textTransform: "capitalize", padding: "6px 12px", borderRadius: 999,
  border: "1px solid " + (active ? "var(--ink-on-paper-1)" : "var(--line-1)"),
  background: active ? "var(--ink-on-paper-1)" : "var(--paper-0)",
  color: active ? "#fff" : "var(--ink-on-paper-2)", cursor: "pointer",
  fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 12.5,
});
const selectStyle: CSSProperties = {
  padding: "7px 10px", borderRadius: 999, border: "1px solid var(--line-1)",
  background: "var(--paper-0)", color: "var(--ink-on-paper-2)", cursor: "pointer",
  fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 12.5,
};

// Table layout: chevron | client | caption | when | media | platform badges | action.
const GRID = "18px 180px minmax(0,2.1fr) 128px 46px minmax(0,1.5fr) 104px";
const headCell = { fontFamily: "var(--font-mono)", fontSize: 10.5, letterSpacing: ".05em", textTransform: "uppercase", color: "rgba(255,255,255,.6)", fontWeight: 600 } as const;

// Pretty-printed JSON / value block used throughout the diagnostics drawer.
function JsonBlock({ value }: { value: unknown }) {
  if (value == null) return null;
  const text = typeof value === "string" ? value : JSON.stringify(value, null, 2);
  return (
    <pre style={{ ...mono, margin: "6px 0 0", padding: "10px 12px", background: "var(--paper-2)", border: "1px solid var(--line-1)", borderRadius: 10, color: "var(--ink-on-paper-2)", whiteSpace: "pre-wrap", wordBreak: "break-word", maxHeight: 280, overflow: "auto" }}>
      {text}
    </pre>
  );
}

function LogRow({ e }: { e: TikTokLogEntry }) {
  const bad = e.status >= 400 || e.status === 0;
  return (
    <details style={{ borderTop: "1px solid var(--paper-2)", padding: "8px 0" }}>
      <summary style={{ ...mono, cursor: "pointer", display: "flex", gap: 8, alignItems: "center", color: "var(--ink-on-paper-2)" }}>
        <span style={{ fontWeight: 700, color: "var(--ink-on-paper-1)" }}>{e.step}</span>
        <span style={{ color: bad ? "var(--vm-red)" : "var(--up)" }}>HTTP {e.status}</span>
        {e.range && <span style={{ color: "var(--ink-on-paper-3)" }}>· {e.range}</span>}
        {e.at && <span style={{ color: "var(--ink-on-paper-3)", marginLeft: "auto" }}>{e.at.replace("T", " ").slice(0, 19)}</span>}
      </summary>
      <JsonBlock value={e.response} />
    </details>
  );
}

function Field({ label, value }: { label: string; value?: string | null }) {
  if (!value) return null;
  return (
    <div style={{ minWidth: 0 }}>
      <div style={{ ...mono, fontSize: 10.5, textTransform: "uppercase", letterSpacing: ".04em", color: "var(--ink-on-paper-3)" }}>{label}</div>
      <div style={{ ...mono, color: "var(--ink-on-paper-1)", wordBreak: "break-all" }}>{value}</div>
    </div>
  );
}

function TargetDetail({ t }: { t: PostTarget }) {
  const meta = t.meta || {};
  const log = (meta.log || []) as TikTokLogEntry[];
  return (
    <div style={{ border: "1px solid var(--line-1)", borderRadius: 12, padding: "14px 16px", background: "var(--paper-0)" }}>
      <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
        <span style={{ fontFamily: "var(--font-body)", fontWeight: 800, fontSize: 13, textTransform: "capitalize", color: "var(--ink-on-paper-1)" }}>{t.platform}</span>
        <TargetStatusBadge target={t} />
      </div>

      {t.error && (
        <div style={{ marginTop: 10, padding: "10px 12px", background: "color-mix(in srgb, var(--vm-red) 8%, transparent)", border: "1px solid color-mix(in srgb, var(--vm-red) 35%, transparent)", borderRadius: 10, color: "var(--vm-red)", fontFamily: "var(--font-body)", fontSize: 12.5, whiteSpace: "pre-wrap", wordBreak: "break-word" }}>
          {t.error}
        </div>
      )}

      {t.status === "published" && isInboxDelivery(t) && (
        <div style={{ marginTop: 10, padding: "10px 12px", background: "rgba(255,176,32,.12)", border: "1px solid rgba(255,176,32,.4)", borderRadius: 10, color: "var(--ink-on-paper-1)", fontFamily: "var(--font-body)", fontSize: 12.5, lineHeight: 1.5 }}>
          {INBOX_NOTE}
        </div>
      )}

      <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(160px, 1fr))", gap: 12, marginTop: 12 }}>
        <Field label="publish_id" value={meta.publish_id as string | undefined} />
        <Field label="mode" value={meta.mode} />
        <Field label="platform_post_id" value={t.platform_post_id} />
        <Field label="published_at" value={t.published_at ? fmtDateTime(t.published_at) : undefined} />
      </div>

      {publishedUrl(t) && (
        <a href={publishedUrl(t) as string} target="_blank" rel="noopener noreferrer"
          style={{ display: "inline-flex", alignItems: "center", gap: 6, marginTop: 12, fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 12.5, color: "var(--vm-volt-deep)", textDecoration: "none" }}>
          View published post <Icon name="arrow-up-right" size={14} stroke="var(--vm-volt-deep)" />
        </a>
      )}

      {log.length > 0 && (
        <div style={{ marginTop: 14 }}>
          <div style={{ ...mono, fontSize: 10.5, textTransform: "uppercase", letterSpacing: ".04em", color: "var(--ink-on-paper-3)", marginBottom: 2 }}>TikTok exchange ({log.length})</div>
          {log.map((e, i) => <LogRow key={i} e={e} />)}
        </div>
      )}

      {meta.last_status != null && (
        <details style={{ marginTop: 12 }}>
          <summary style={{ ...mono, cursor: "pointer", color: "var(--ink-on-paper-2)", fontWeight: 700 }}>Last status</summary>
          <JsonBlock value={meta.last_status} />
        </details>
      )}

      {log.length === 0 && !t.error && (
        <div style={{ ...mono, color: "var(--ink-on-paper-3)", marginTop: 12 }}>No platform exchange recorded yet.</div>
      )}
    </div>
  );
}

export default function AdminPosts() {
  const { user } = useAuth();
  const [posts, setPosts] = useState<AdminPost[]>([]);
  const [stats, setStats] = useState<AdminPostStats | null>(null);
  const [loading, setLoading] = useState(true);
  const [filter, setFilter] = useState<(typeof FILTERS)[number]>("all");
  const [platform, setPlatform] = useState("");
  const [overdue, setOverdue] = useState(false);
  const [q, setQ] = useState("");
  const [debouncedQ, setDebouncedQ] = useState("");
  const [expanded, setExpanded] = useState<number | null>(null);
  const [detail, setDetail] = useState<Record<number, AdminPost>>({});
  const [detailLoading, setDetailLoading] = useState<number | null>(null);
  const [report, setReport] = useState<AdminReconcileReport | null>(null);
  const [reconciling, setReconciling] = useState(false);
  const [requeuingId, setRequeuingId] = useState<number | null>(null);

  // Debounce the search box (300ms) so we don't refetch on every keystroke.
  useEffect(() => { const t = setTimeout(() => setDebouncedQ(q), 300); return () => clearTimeout(t); }, [q]);

  const toggle = useCallback(async (id: number) => {
    setExpanded((cur) => (cur === id ? null : id));
    if (detail[id]) return;
    setDetailLoading(id);
    const res = await viewsMaxApi.getAdminPost(id);
    if (res.success && res.data) setDetail((d) => ({ ...d, [id]: res.data as AdminPost }));
    setDetailLoading(null);
  }, [detail]);

  const loadSeq = useRef(0);
  const load = useCallback(async () => {
    const seq = ++loadSeq.current;
    setLoading(true);
    const [p, s] = await Promise.all([
      viewsMaxApi.getAdminPosts({
        // "scheduled" is a post-status view; the others are per-target statuses.
        post_status: filter === "scheduled" ? "scheduled" : undefined,
        status: filter === "all" || filter === "scheduled" ? undefined : filter,
        platform: platform || undefined,
        overdue: overdue || undefined,
        q: debouncedQ || undefined,
      }),
      viewsMaxApi.getAdminPostStats(),
    ]);
    // Ignore a response that a newer filter/search change has superseded.
    if (seq !== loadSeq.current) return;
    setPosts(p.success && p.data ? p.data : []);
    if (s.success && s.data) setStats(s.data);
    setLoading(false);
  }, [filter, platform, overdue, debouncedQ]);

  useEffect(() => { if (user?.is_admin) load(); }, [load, user?.is_admin]);

  const runReconcile = async () => {
    setReconciling(true);
    const res = await viewsMaxApi.reconcileAdminPosts();
    setReconciling(false);
    if (res.success && res.data) setReport(res.data);
    else toast.error(res.error || "Reconcile scan failed.");
  };

  const handleRequeue = async (p: AdminPost) => {
    const overdueSched = isOverdue(p);
    const ok = window.confirm(
      overdueSched
        ? "Publish this overdue scheduled post now? It will be marked posted and dispatched to all its platforms."
        : "Re-send this post's unfinished platform jobs? Platforms that already posted are NOT re-sent (no double-posting).",
    );
    if (!ok) return;
    setRequeuingId(p.id);
    const res = await viewsMaxApi.requeueAdminPost(p.id);
    setRequeuingId(null);
    if (res.success && res.data) {
      toast.success(`Requeued ${res.data.requeued} · healed ${res.data.healed} · skipped ${res.data.skipped}.`);
      setReport(null);
      setDetail((d) => { const n = { ...d }; delete n[p.id]; return n; }); // force fresh diagnostics
      load();
    } else {
      toast.error(res.error || "Requeue failed.");
    }
  };

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
      <SectionHead eyebrow="Admin" title="Publishing monitor" />

      {/* Stat cards */}
      <div style={{ display: "grid", gridTemplateColumns: "repeat(5, 1fr)", gap: 12 }}>
        {STAT_CARDS.map((c) => (
          <div key={c.key} style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 14, padding: "14px 16px", boxShadow: "0 1px 2px rgba(10,10,12,.04)" }}>
            <div style={{ fontFamily: "var(--font-mono)", fontSize: 11, color: "var(--ink-on-paper-3)", textTransform: "uppercase", letterSpacing: ".04em" }}>{c.label}</div>
            <div style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 26, color: c.color, marginTop: 4 }}>{stats ? stats[c.key] : "—"}</div>
          </div>
        ))}
      </div>

      {/* Filters + search + actions */}
      <div style={{ display: "flex", alignItems: "center", gap: 10, flexWrap: "wrap" }}>
        <div style={{ display: "flex", gap: 6, flexWrap: "wrap" }}>
          {FILTERS.map((f) => (
            <button key={f} onClick={() => setFilter(f)} style={chipStyle(filter === f)}>{f}</button>
          ))}
        </div>

        <select value={platform} onChange={(e) => setPlatform(e.target.value)} style={selectStyle} title="Filter to posts targeting one platform">
          <option value="">All platforms</option>
          {PLATFORM_OPTIONS.map((pl) => <option key={pl} value={pl} style={{ textTransform: "capitalize" }}>{pl}</option>)}
        </select>

        <button
          onClick={() => setOverdue((o) => !o)}
          title="Show only scheduled posts whose time has already passed — the scheduler should have published these but hasn't."
          style={chipStyle(overdue)}
        >
          Overdue
        </button>

        <button
          onClick={runReconcile}
          disabled={reconciling}
          title="Scan every post for platform jobs left stuck by a worker or scheduler outage. Read-only — it reports what's stuck and what can be safely re-queued, and changes nothing."
          style={{ display: "inline-flex", alignItems: "center", gap: 6, padding: "7px 13px", borderRadius: 999, border: "1px solid var(--vm-volt-deep)", background: "var(--vm-volt-tint-l)", color: "var(--vm-volt-deep)", cursor: reconciling ? "default" : "pointer", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 12.5, opacity: reconciling ? 0.6 : 1 }}
        >
          <Icon name="search" size={14} stroke="var(--vm-volt-deep)" /> {reconciling ? "Scanning…" : "Reconcile"}
        </button>

        <input
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder="Search caption, client, or platform id…"
          style={{ marginLeft: "auto", minWidth: 240, fontFamily: "var(--font-body)", fontSize: 13, color: "var(--ink-on-paper-1)", background: "var(--paper-0)", border: "1px solid var(--line-2)", borderRadius: 10, padding: "9px 12px", outline: "none" }}
        />
      </div>

      {/* Reconcile report banner */}
      {report && (
        <div style={{ display: "flex", alignItems: "center", gap: 12, flexWrap: "wrap", background: report.stuck_targets || report.overdue_scheduled ? "rgba(255,176,32,.12)" : "var(--vm-volt-tint-l)", border: "1px solid " + (report.stuck_targets || report.overdue_scheduled ? "rgba(255,176,32,.45)" : "var(--vm-volt-deep)"), borderRadius: 12, padding: "11px 14px", fontFamily: "var(--font-body)", fontSize: 13, color: "var(--ink-on-paper-1)" }}>
          <Icon name={report.stuck_targets || report.overdue_scheduled ? "alert-triangle" : "check-circle-2"} size={16} stroke={report.stuck_targets || report.overdue_scheduled ? "var(--warn)" : "var(--vm-volt-deep)"} />
          <span style={{ fontWeight: 600 }}>
            {report.stuck_targets || report.overdue_scheduled
              ? `${report.stuck_targets} stuck target${report.stuck_targets === 1 ? "" : "s"} across ${report.affected_post_ids.length} post${report.affected_post_ids.length === 1 ? "" : "s"} — ${report.requeueable} never posted (safe to requeue), ${report.already_on_platform} already on-platform (will heal). ${report.overdue_scheduled} overdue scheduled.`
              : "Nothing stuck — all publishing is up to date."}
          </span>
          {report.overdue_scheduled > 0 && (
            <button onClick={() => { setFilter("scheduled"); setOverdue(true); setReport(null); }} style={{ background: "none", border: "none", color: "var(--vm-volt-deep)", fontWeight: 700, cursor: "pointer", fontFamily: "var(--font-body)", fontSize: 12.5 }}>View overdue →</button>
          )}
          <button onClick={() => setReport(null)} style={{ marginLeft: "auto", background: "none", border: "none", color: "var(--ink-on-paper-3)", fontWeight: 700, cursor: "pointer", fontFamily: "var(--font-body)", fontSize: 12.5 }}>Dismiss</button>
        </div>
      )}

      {loading ? (
        <AnalyticsLoading />
      ) : posts.length === 0 ? (
        <div style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 18, padding: 48, textAlign: "center", color: "var(--ink-on-paper-3)", fontFamily: "var(--font-body)", fontSize: 14 }}>No posts match.</div>
      ) : (
        <div style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 18, overflow: "hidden", boxShadow: "0 1px 2px rgba(10,10,12,.04)" }}>
          {/* header row */}
          <div style={{ display: "grid", gridTemplateColumns: GRID, gap: 14, alignItems: "center", padding: "11px 20px", background: "var(--ink-900)" }}>
            <span />
            <div style={headCell}>Client</div>
            <div style={headCell}>Caption</div>
            <div style={headCell}>When</div>
            <div style={{ ...headCell, textAlign: "right" }}>Media</div>
            <div style={headCell}>Platforms</div>
            <div style={{ ...headCell, textAlign: "right" }}>Action</div>
          </div>

          {posts.map((p, i) => {
            const open = expanded === p.id;
            const d = detail[p.id];
            const caption = effectiveCaption(p);
            return (
              <div key={p.id} style={{ borderTop: i ? "1px solid var(--paper-2)" : "none" }}>
                <div
                  role="button"
                  tabIndex={0}
                  onClick={() => toggle(p.id)}
                  onKeyDown={(e) => { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); toggle(p.id); } }}
                  style={{ width: "100%", textAlign: "left", display: "grid", gridTemplateColumns: GRID, gap: 14, alignItems: "center", padding: "13px 20px", background: open ? "var(--paper-1)" : i % 2 === 1 ? "var(--paper-1)" : "transparent", cursor: "pointer" }}
                >
                  <Icon name={open ? "chevron-down" : "chevron-right"} size={14} stroke="var(--ink-on-paper-3)" />
                  <div style={{ minWidth: 0 }}>
                    <div style={{ fontFamily: "var(--font-body)", fontSize: 13, fontWeight: 700, color: "var(--ink-on-paper-1)", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>{p.user?.name || "Unknown"}</div>
                    <div style={{ fontFamily: "var(--font-mono)", fontSize: 11, color: "var(--ink-on-paper-3)", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>{p.user?.email || `user #${p.user_id}`}</div>
                  </div>
                  <div style={{ minWidth: 0, fontFamily: "var(--font-body)", fontSize: 13, color: caption ? "var(--ink-on-paper-1)" : "var(--ink-on-paper-3)", fontStyle: caption ? "normal" : "italic", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }} title={caption || undefined}>
                    {caption || "No caption"}
                  </div>
                  <div style={{ fontFamily: "var(--font-mono)", fontSize: 11.5, color: "var(--ink-on-paper-2)", whiteSpace: "nowrap", display: "flex", flexDirection: "column", gap: 3 }}>
                    <span>{fmtWhen(p)}</span>
                    {isOverdue(p) && <span style={{ alignSelf: "flex-start", fontFamily: "var(--font-body)", fontSize: 9.5, fontWeight: 800, textTransform: "uppercase", letterSpacing: ".04em", color: "var(--vm-red-deep)", background: "var(--vm-red-tint-l)", border: "1px solid var(--vm-red)", borderRadius: 999, padding: "1px 6px" }}>Overdue</span>}
                  </div>
                  <div style={{ fontFamily: "var(--font-mono)", fontSize: 12, color: "var(--ink-on-paper-2)", textAlign: "right" }}>{(p.media || []).length}</div>
                  <div style={{ display: "flex", gap: 6, flexWrap: "wrap" }}>
                    {(p.targets || []).length === 0
                      ? <span style={{ ...mono, color: "var(--ink-on-paper-3)" }}>none</span>
                      : (p.targets || []).map((t) => <TargetStatusBadge key={t.id} target={t} />)}
                  </div>
                  <div style={{ display: "flex", justifyContent: "flex-end" }}>
                    {needsRequeue(p) && (
                      <button
                        onClick={(e) => { e.stopPropagation(); handleRequeue(p); }}
                        onKeyDown={(e) => e.stopPropagation()}
                        disabled={requeuingId === p.id}
                        title={isOverdue(p)
                          ? "This scheduled post is overdue — publish it now (marks it posted and dispatches all its platforms)."
                          : "Re-send this post's unfinished platform jobs. Platforms that already posted are never re-sent (no double-posting) — they're just marked published."}
                        style={{ display: "inline-flex", alignItems: "center", gap: 5, padding: "5px 10px", borderRadius: 999, border: "1px solid var(--vm-red)", background: "var(--vm-red-tint-l)", color: "var(--vm-red-deep)", cursor: requeuingId === p.id ? "default" : "pointer", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 11.5, whiteSpace: "nowrap", opacity: requeuingId === p.id ? 0.6 : 1 }}
                      >
                        <Icon name="rotate-cw" size={12} stroke="var(--vm-red-deep)" />
                        {requeuingId === p.id ? "…" : isOverdue(p) ? "Publish" : "Requeue"}
                      </button>
                    )}
                  </div>
                </div>

                {open && (
                  <div style={{ padding: "4px 20px 18px 48px", display: "grid", gap: 12, background: "var(--paper-1)" }}>
                    {detailLoading === p.id && !d ? (
                      <div style={{ ...mono, color: "var(--ink-on-paper-3)" }}>Loading diagnostics…</div>
                    ) : (d?.targets || p.targets || []).length === 0 ? (
                      <div style={{ ...mono, color: "var(--ink-on-paper-3)" }}>No platform targets.</div>
                    ) : (
                      (d?.targets || p.targets || []).map((t) => <TargetDetail key={t.id} t={t} />)
                    )}
                  </div>
                )}
              </div>
            );
          })}
        </div>
      )}
    </PostShell>
  );
}
