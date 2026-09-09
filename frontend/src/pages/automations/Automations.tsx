// Automations index — Instagram comment / story-reply / DM auto-responders.
// Lists every automation with status, trigger summary, runs, CTR and last
// modified; "New Automation" opens the trigger picker.
import { useCallback, useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { toast } from "sonner";
import { CARD, Btn, Chip, Icon, SectionHead } from "@/components/analytics/primitives";
import { Empty } from "@/components/analytics/shared";
import { FiltersPanel, useUrlFilters, matchesText, matchesMulti, type FilterField } from "@/components/analytics/FiltersPanel";
import { PAvatar } from "@/components/post/composer";
import { viewsMaxApi, type Automation, type AutomationListResponse } from "@/lib/api-service";
import { formatCtr, timeAgo, TRIGGER_META } from "@/lib/automations";
import { TriggerPickerModal } from "@/components/automations/TriggerPickerModal";
import { ReconnectInstagramNotice } from "@/components/automations/ReconnectInstagramNotice";
import { Notice, StatusPill } from "@/components/automations/ui";

const MONO: React.CSSProperties = { fontFamily: "var(--font-mono)", fontSize: 12, color: "var(--ink-on-paper-3)" };

function Row({ a, onToggle, onDelete, busy }: { a: Automation; onToggle: () => void; onDelete: () => void; busy: boolean }) {
  const navigate = useNavigate();
  const live = a.status === "live";
  return (
    <div style={{ ...CARD, padding: "16px 18px", display: "grid", gridTemplateColumns: "minmax(0,1fr) 70px 70px 120px auto", gap: 14, alignItems: "center" }}>
      <div style={{ minWidth: 0 }}>
        <div style={{ display: "flex", alignItems: "center", gap: 10, cursor: "pointer" }} onClick={() => navigate(`/dashboard/automations/${a.id}`)}>
          <StatusPill status={a.status} />
          <span style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 16, color: "var(--ink-on-paper-1)", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>{a.name}</span>
          {a.needs_reconnect && <span style={{ fontSize: 11.5, fontWeight: 700, color: "var(--vm-red)" }}>Reconnect needed</span>}
        </div>
        <div style={{ display: "flex", alignItems: "center", gap: 8, marginTop: 8, flexWrap: "wrap" }}>
          <PAvatar id="instagram" size={18} avatarUrl={a.social_account?.avatar_url ?? null} />
          <span style={{ fontFamily: "var(--font-body)", fontSize: 12.5, color: "var(--ink-on-paper-2)" }}>{a.trigger_summary.replace(/ and (comment|message) (contains|is exactly) .*$/, "")}</span>
          {a.keyword_mode !== "any" && (a.keywords ?? []).map((k) => <Chip key={k} tone="aqua">{k}</Chip>)}
          {a.post_match === "specific" && (a.posts ?? []).slice(0, 4).map((p) => p.thumbnail_url ? <img key={p.id} src={p.thumbnail_url} alt="" style={{ width: 18, height: 18, borderRadius: 4, objectFit: "cover" }} /> : null)}
        </div>
        {a.last_error && <div style={{ ...MONO, color: "var(--vm-red)", marginTop: 6 }}>{a.last_error}</div>}
      </div>
      <div style={{ textAlign: "right" }}><div style={MONO}>Runs</div><div style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 16 }}>{a.stats.runs}</div></div>
      <div style={{ textAlign: "right" }}><div style={MONO}>CTR</div><div style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 16 }}>{formatCtr(a.stats)}</div></div>
      <div style={{ textAlign: "right" }}><div style={MONO}>Modified</div><div style={{ fontFamily: "var(--font-body)", fontSize: 13 }}>{timeAgo(a.updated_at)}</div></div>
      <div style={{ display: "flex", gap: 6 }}>
        <Btn kind="ghost" size="sm" icon="edit" onClick={() => navigate(`/dashboard/automations/${a.id}`)}>Edit</Btn>
        <Btn kind={live ? "ghost" : "aqua"} size="sm" icon={live ? "x" : "zap"} onClick={busy ? undefined : onToggle}>{live ? "Stop" : "Go live"}</Btn>
        <Btn kind="ghost" size="sm" icon="trash-2" onClick={busy ? undefined : onDelete}>Delete</Btn>
      </div>
    </div>
  );
}

export default function Automations() {
  const navigate = useNavigate();
  const [data, setData] = useState<AutomationListResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [picker, setPicker] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [needsReconnect, setNeedsReconnect] = useState(false);

  const load = useCallback(async () => {
    const [res, accounts] = await Promise.all([viewsMaxApi.getAutomations(), viewsMaxApi.getAutomationAccounts()]);
    if (res.success && res.data) setData(res.data);
    else toast.error(res.error || "Couldn't load automations.");
    if (accounts.success && accounts.data) {
      const list = accounts.data.accounts;
      setNeedsReconnect(list.length > 0 && !list.some((a) => a.can_automate));
    }
    setLoading(false);
  }, []);
  useEffect(() => { void load(); }, [load]);

  const all = useMemo(() => data?.automations ?? [], [data]);
  const filterFields = useMemo<FilterField[]>(() => [
    { kind: "text", key: "q", label: "Name", placeholder: "Search all automations…", suggestions: all.map((a) => a.name) },
    { kind: "multi", key: "trigger", label: "Trigger", options: (["comment", "story_reply", "dm"] as const).map((t) => ({ value: t, label: TRIGGER_META[t].label })) },
    { kind: "multi", key: "status", label: "Status", options: [{ value: "live", label: "Live" }, { value: "stopped", label: "Stopped" }] },
  ], [all]);
  const filters = useUrlFilters(filterFields);
  const rows = useMemo(() => {
    const v = filters.values;
    return all.filter((a) => matchesText(a.name, v.q) && matchesMulti(a.trigger_type, v.trigger) && matchesMulti(a.status, v.status));
  }, [all, filters.values]);

  const atCap = data?.limit != null && (data?.used ?? 0) >= data.limit;

  const toggle = async (a: Automation) => {
    setBusyId(a.id);
    const res = a.status === "live" ? await viewsMaxApi.stopAutomation(a.id) : await viewsMaxApi.startAutomation(a.id);
    setBusyId(null);
    if (!res.success) {
      toast.error(res.error || "Couldn't update the automation.");
      if (res.code === "reconnect_required") setNeedsReconnect(true);
      return;
    }
    toast.success(res.data?.status === "live" ? "Automation is live." : "Automation stopped.");
    void load();
  };

  const remove = async (a: Automation) => {
    if (!window.confirm(`Delete "${a.name}"? Its run history is kept.`)) return;
    setBusyId(a.id);
    const res = await viewsMaxApi.deleteAutomation(a.id);
    setBusyId(null);
    if (!res.success) { toast.error(res.error || "Couldn't delete the automation."); return; }
    toast.success("Automation deleted.");
    void load();
  };

  return (
    <div style={{ margin: "-24px", padding: 24, background: "var(--paper-1)", minHeight: "calc(100vh - 4rem)" }}>
      <div style={{ maxWidth: 1080, margin: "0 auto", display: "flex", flexDirection: "column", gap: 16 }}>
        <SectionHead eyebrow="AUTOMATIONS" title="My Automations"
          right={atCap ? (
            <span title={`You've reached your plan's limit of ${data?.limit} automation${data?.limit === 1 ? "" : "s"}.`}>
              <Btn kind="ghost" icon="plus" onClick={() => navigate("/dashboard/billing")}>Upgrade to add more</Btn>
            </span>
          ) : (
            <Btn icon="plus" onClick={() => setPicker(true)}>New Automation</Btn>
          )} />
        <p style={{ fontFamily: "var(--font-body)", fontSize: 14, color: "var(--ink-on-paper-2)", margin: 0, lineHeight: 1.5 }}>
          Reply to comments, story replies and DMs on autopilot — and send the link they asked for as a tracked DM.
          {data?.limit != null && <span style={{ ...MONO, marginLeft: 8 }}>{data.used} / {data.limit} used</span>}
        </p>

        {data && !data.enabled && <Notice tone="info">Instagram automations aren't switched on for this server yet. Set <code>INSTAGRAM_AUTOMATIONS_ENABLED=true</code> once Meta has approved the comments &amp; messages permissions.</Notice>}
        {needsReconnect && <ReconnectInstagramNotice onReconnected={load} />}

        {loading ? (
          <div style={{ ...CARD, padding: 32, textAlign: "center", color: "var(--ink-on-paper-3)", fontFamily: "var(--font-body)", fontSize: 13.5 }}>Loading…</div>
        ) : all.length === 0 ? (
          <div style={{ ...CARD, padding: 48, textAlign: "center" }}>
            <Icon name="zap" size={28} stroke="var(--vm-volt-deep)" />
            <Empty label="No automations yet. Create one to auto-reply to comments, story replies or DMs." />
            <Btn icon="plus" onClick={() => setPicker(true)}>New Automation</Btn>
          </div>
        ) : (
          <>
            <FiltersPanel fields={filterFields} filters={filters} />
            {rows.length === 0 ? (
              <div style={{ ...CARD, padding: 48 }}><Empty label="No automations match your filters." /></div>
            ) : (
              <div style={{ display: "flex", flexDirection: "column", gap: 10 }}>
                {rows.map((a) => <Row key={a.id} a={a} busy={busyId === a.id} onToggle={() => toggle(a)} onDelete={() => remove(a)} />)}
              </div>
            )}
          </>
        )}
      </div>
      {picker && <TriggerPickerModal onClose={() => setPicker(false)} />}
    </div>
  );
}
