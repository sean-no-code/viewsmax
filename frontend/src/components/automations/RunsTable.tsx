// Activity log for one automation: who triggered it and what we sent back.
import { useEffect, useState } from "react";
import { Btn, Chip } from "@/components/analytics/primitives";
import { viewsMaxApi, type AutomationRun } from "@/lib/api-service";
import { timeAgo } from "@/lib/automations";
import { HINT } from "./ui";

const TH: React.CSSProperties = { textAlign: "left", fontFamily: "var(--font-mono)", fontSize: 10.5, letterSpacing: ".05em", textTransform: "uppercase", color: "var(--ink-on-paper-3)", padding: "8px 10px", borderBottom: "1px solid var(--line-1)", whiteSpace: "nowrap" };
const TD: React.CSSProperties = { fontFamily: "var(--font-body)", fontSize: 12.5, color: "var(--ink-on-paper-1)", padding: "9px 10px", borderBottom: "1px solid var(--paper-2)", verticalAlign: "top" };

function Dot({ state }: { state: string | null | undefined }) {
  const color = state === "sent" || state === "completed" ? "var(--up)" : state === "failed" ? "var(--vm-red)" : state === "partial" ? "var(--warn)" : "var(--ink-on-paper-3)";
  return <span style={{ display: "inline-flex", alignItems: "center", gap: 5, fontFamily: "var(--font-mono)", fontSize: 11, color }}><span style={{ width: 7, height: 7, borderRadius: "50%", background: color }} />{state ?? "—"}</span>;
}

export function RunsTable({ automationId, refreshKey = 0 }: { automationId: number; refreshKey?: number }) {
  const [runs, setRuns] = useState<AutomationRun[]>([]);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    viewsMaxApi.getAutomationRuns(automationId, page).then((res) => {
      if (cancelled) return;
      if (res.success && res.data) { setRuns(res.data.data); setLastPage(res.data.last_page); }
      setLoading(false);
    });
    return () => { cancelled = true; };
  }, [automationId, page, refreshKey]);

  if (loading && runs.length === 0) return <div style={{ ...HINT, padding: 16 }}>Loading runs…</div>;
  if (runs.length === 0) return <div style={{ ...HINT, padding: 16, textAlign: "center" }}>No runs yet. Once the automation is live, every trigger shows up here.</div>;

  return (
    <div style={{ overflowX: "auto" }}>
      <table style={{ width: "100%", borderCollapse: "collapse" }}>
        <thead><tr>{["When", "From", "They wrote", "Matched", "Reply", "DM", "Clicked", "Status"].map((h) => <th key={h} style={TH}>{h}</th>)}</tr></thead>
        <tbody>
          {runs.map((r) => (
            <tr key={r.id}>
              <td style={{ ...TD, whiteSpace: "nowrap" }}>{timeAgo(r.created_at)}</td>
              <td style={TD}>{r.sender_username ? `@${r.sender_username}` : r.sender_id}</td>
              <td style={{ ...TD, maxWidth: 260, overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }} title={r.inbound_text ?? ""}>{r.inbound_text ?? "—"}</td>
              <td style={TD}>{r.matched_keyword ? <Chip tone="aqua">{r.matched_keyword}</Chip> : <span style={HINT}>any</span>}</td>
              <td style={TD}>{r.trigger_type === "comment" ? <Dot state={r.reply_status} /> : <span style={HINT}>—</span>}</td>
              <td style={TD}><Dot state={r.dm_status} /></td>
              <td style={TD}>{r.clicked_at ? <span style={{ color: "var(--up)", fontWeight: 700 }}>✓</span> : <span style={HINT}>—</span>}</td>
              <td style={TD}><Dot state={r.status} />{r.error && <div style={{ ...HINT, color: "var(--vm-red)", marginTop: 2 }}>{r.error}</div>}</td>
            </tr>
          ))}
        </tbody>
      </table>
      {lastPage > 1 && (
        <div style={{ display: "flex", justifyContent: "flex-end", gap: 8, marginTop: 10, alignItems: "center" }}>
          <Btn kind="ghost" size="sm" onClick={page > 1 ? () => setPage(page - 1) : undefined}>Prev</Btn>
          <span style={HINT}>{page} / {lastPage}</span>
          <Btn kind="ghost" size="sm" onClick={page < lastPage ? () => setPage(page + 1) : undefined}>Next</Btn>
        </div>
      )}
    </div>
  );
}
