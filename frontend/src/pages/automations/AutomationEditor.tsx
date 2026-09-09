// Automation editor (new + edit). Left: the config — trigger conditions,
// keywords, public reply, DM. Right: a phone preview. Header: name, Save,
// Go live / Stop. Below (edit mode): the runs log.
import { useCallback, useEffect, useMemo, useState } from "react";
import { useNavigate, useParams, useSearchParams } from "react-router-dom";
import { toast } from "sonner";
import { CARD, Btn, Icon, Segmented } from "@/components/analytics/primitives";
import { Field, TextInput } from "@/components/analytics/Modal";
import { viewsMaxApi, type Automation, type AutomationAccount, type AutomationTrigger } from "@/lib/api-service";
import { draftFromAutomation, draftToPayload, emptyDraft, hasButton, MAX_REPLY_TEXTS, TRIGGER_META, triggerSummary, validateDraft, type AutomationDraft } from "@/lib/automations";
import { DmComposer } from "@/components/automations/DmComposer";
import { KeywordsInput } from "@/components/automations/KeywordsInput";
import { PhonePreview } from "@/components/automations/PhonePreview";
import { PostPickerModal, PostThumb } from "@/components/automations/PostPickerModal";
import { ReconnectInstagramNotice } from "@/components/automations/ReconnectInstagramNotice";
import { RunsTable } from "@/components/automations/RunsTable";
import { H2, HINT, OptionRow, PANEL, StatusPill, TEXTAREA, Toggle, UpgradeBadge } from "@/components/automations/ui";

export default function AutomationEditor() {
  const { id } = useParams();
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const editing = id ? Number(id) : null;

  const [automation, setAutomation] = useState<Automation | null>(null);
  const [draft, setDraft] = useState<AutomationDraft>(() => emptyDraft(
    ((["comment", "story_reply", "dm"] as AutomationTrigger[]).includes(params.get("trigger") as AutomationTrigger) ? params.get("trigger") : "comment") as AutomationTrigger,
    params.get("account") ? Number(params.get("account")) : null,
  ));
  const [accounts, setAccounts] = useState<AutomationAccount[]>([]);
  const [loading, setLoading] = useState(!!editing);
  const [saving, setSaving] = useState(false);
  const [picker, setPicker] = useState(false);
  const [showAll, setShowAll] = useState(false);
  const [runsKey, setRunsKey] = useState(0);
  const [dirty, setDirty] = useState(false);

  const patch = (p: Partial<AutomationDraft>) => { setDraft((d) => ({ ...d, ...p })); setDirty(true); };

  const loadAccounts = useCallback(async () => {
    const res = await viewsMaxApi.getAutomationAccounts();
    if (res.success && res.data) setAccounts(res.data.accounts);
  }, []);

  useEffect(() => {
    void loadAccounts();
    if (!editing) return;
    let cancelled = false;
    viewsMaxApi.getAutomation(editing).then((res) => {
      if (cancelled) return;
      if (res.success && res.data) { setAutomation(res.data); setDraft(draftFromAutomation(res.data)); setDirty(false); }
      else { toast.error(res.error || "Automation not found."); navigate("/dashboard/automations"); }
      setLoading(false);
    });
    return () => { cancelled = true; };
  }, [editing, loadAccounts, navigate]);

  const account = useMemo(() => accounts.find((a) => a.id === draft.social_account_id) ?? automation?.social_account ?? null, [accounts, draft.social_account_id, automation]);
  const meta = TRIGGER_META[draft.trigger_type];
  const isComment = draft.trigger_type === "comment";
  const errors = validateDraft(draft);

  const save = async (): Promise<Automation | null> => {
    if (errors.length) { toast.error(errors[0]); return null; }
    setSaving(true);
    const payload = draftToPayload(draft);
    const res = editing ? await viewsMaxApi.updateAutomation(editing, payload) : await viewsMaxApi.createAutomation(payload);
    setSaving(false);
    if (!res.success || !res.data) { toast.error(res.error || "Couldn't save the automation."); return null; }
    setAutomation(res.data);
    setDraft(draftFromAutomation(res.data));
    setDirty(false);
    if (!editing) navigate(`/dashboard/automations/${res.data.id}`, { replace: true });
    return res.data;
  };

  const goLive = async () => {
    const saved = dirty || !editing ? await save() : automation;
    if (!saved) return;
    setSaving(true);
    const res = await viewsMaxApi.startAutomation(saved.id);
    setSaving(false);
    if (!res.success || !res.data) { toast.error(res.error || "Couldn't start the automation."); if (res.code === "reconnect_required") void loadAccounts(); return; }
    setAutomation(res.data);
    toast.success("Automation is live.");
  };

  const stop = async () => {
    if (!automation) return;
    const res = await viewsMaxApi.stopAutomation(automation.id);
    if (!res.success || !res.data) { toast.error(res.error || "Couldn't stop the automation."); return; }
    setAutomation(res.data);
    toast.success("Automation stopped.");
  };

  if (loading) return <div style={{ padding: 32, color: "var(--ink-on-paper-3)", fontFamily: "var(--font-body)" }}>Loading…</div>;

  const live = automation?.status === "live";
  const visiblePosts = showAll ? draft.posts : draft.posts.slice(0, 4);

  return (
    <div style={{ margin: "-24px", padding: 24, background: "var(--paper-1)", minHeight: "calc(100vh - 4rem)" }}>
      <div style={{ maxWidth: 1180, margin: "0 auto", display: "flex", flexDirection: "column", gap: 16 }}>
        {/* Header */}
        <div style={{ display: "flex", alignItems: "center", gap: 12, flexWrap: "wrap" }}>
          <button onClick={() => navigate("/dashboard/automations")} style={{ border: "none", background: "transparent", cursor: "pointer", display: "grid", placeItems: "center" }}><Icon name="arrow-left" size={18} stroke="var(--ink-on-paper-2)" /></button>
          <input value={draft.name} onChange={(e) => patch({ name: e.target.value })} placeholder="Untitled"
            style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 22, letterSpacing: "-.02em", color: "var(--ink-on-paper-1)", border: "none", background: "transparent", outline: "none", minWidth: 200, flex: 1 }} />
          {automation && <StatusPill status={automation.status} />}
          <span style={HINT}>{meta.label}{account ? ` · @${account.username ?? account.name ?? account.id}` : ""}</span>
          <div style={{ display: "flex", gap: 8 }}>
            <Btn kind="ghost" onClick={saving ? undefined : () => void save()}>{saving ? "Saving…" : dirty || !editing ? "Save" : "Saved"}</Btn>
            {live ? <Btn kind="dark" icon="x" onClick={stop}>Stop</Btn> : <Btn icon="zap" onClick={saving ? undefined : goLive}>Go Live</Btn>}
          </div>
        </div>

        {account && !account.can_automate && <ReconnectInstagramNotice onReconnected={loadAccounts} />}
        {automation?.last_error && <div style={{ ...HINT, color: "var(--vm-red)" }}>{automation.last_error}</div>}

        <div style={{ display: "grid", gridTemplateColumns: "minmax(0,1fr) 340px", gap: 20, alignItems: "start" }}>
          {/* Config */}
          <div style={{ display: "flex", flexDirection: "column", gap: 18 }}>
            {isComment && (
              <section style={{ ...CARD, padding: 20 }}>
                <h2 style={H2}>When someone comments on</h2>
                <OptionRow selected={draft.post_match === "specific"} onSelect={() => patch({ post_match: "specific" })} label="a specific post or reel">
                  <div style={{ display: "flex", flexWrap: "wrap", gap: 8, alignItems: "center" }}>
                    {visiblePosts.map((p) => <PostThumb key={p.id} post={p} size={68} selected onClick={() => patch({ posts: draft.posts.filter((x) => x.id !== p.id) })} />)}
                    <div onClick={() => draft.social_account_id ? setPicker(true) : toast.error("Choose the Instagram account first.")} style={{ width: 68, height: 68, borderRadius: 8, border: "1px dashed var(--line-2)", display: "grid", placeItems: "center", cursor: "pointer" }}><Icon name="plus" size={18} stroke="var(--ink-on-paper-3)" /></div>
                  </div>
                  <div style={{ display: "flex", gap: 14, marginTop: 10 }}>
                    <button type="button" onClick={() => setPicker(true)} style={{ border: "none", background: "transparent", color: "var(--vm-volt-deep)", fontWeight: 700, fontSize: 13, cursor: "pointer", padding: 0, fontFamily: "var(--font-body)" }}>Choose posts</button>
                    {draft.posts.length > 4 && <button type="button" onClick={() => setShowAll((v) => !v)} style={{ border: "none", background: "transparent", color: "var(--vm-volt-deep)", fontWeight: 700, fontSize: 13, cursor: "pointer", padding: 0, fontFamily: "var(--font-body)" }}>{showAll ? "Show fewer" : `Show all (${draft.posts.length})`}</button>}
                  </div>
                </OptionRow>
                <OptionRow selected={draft.post_match === "any"} onSelect={() => patch({ post_match: "any" })} label="any post or reel" />
                <OptionRow selected={false} onSelect={() => toast.info("Coming soon: automatically attach to your next post.")} label="next post or reel" badge={<UpgradeBadge />} />
                <label style={{ display: "flex", alignItems: "center", gap: 10, marginTop: 6, ...HINT }}>
                  <Toggle checked={draft.include_replies} onChange={(v) => patch({ include_replies: v })} /> Also fire on replies inside comment threads
                </label>
              </section>
            )}

            <section style={{ ...CARD, padding: 20 }}>
              <h2 style={H2}>{isComment ? "And this comment has" : draft.trigger_type === "story_reply" ? "And the story reply has" : "And the message has"}</h2>
              <OptionRow selected={draft.keyword_mode !== "any"} onSelect={() => patch({ keyword_mode: "contains" })} label="a specific word or words">
                <KeywordsInput value={draft.keywords} onChange={(keywords) => patch({ keywords })} />
                <div style={{ marginTop: 12, display: "flex", alignItems: "center", gap: 10 }}>
                  <span style={HINT}>Match</span>
                  <Segmented options={[{ id: "contains", label: "contains" }, { id: "exact", label: "exact" }]} value={draft.keyword_mode === "exact" ? "exact" : "contains"} onChange={(v) => patch({ keyword_mode: v as "contains" | "exact" })} />
                </div>
              </OptionRow>
              <OptionRow selected={draft.keyword_mode === "any"} onSelect={() => patch({ keyword_mode: "any" })} label="any word" />

              {isComment && (
                <div style={{ ...PANEL, display: "flex", flexDirection: "column", gap: 10 }}>
                  <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 10 }}>
                    <span style={{ fontFamily: "var(--font-body)", fontSize: 14, color: "var(--ink-on-paper-1)" }}>reply to their comments under the post</span>
                    <Toggle checked={draft.reply_enabled} onChange={(v) => patch({ reply_enabled: v, reply_texts: v && draft.reply_texts.length === 0 ? ["Sent you a DM! 📩"] : draft.reply_texts })} />
                  </div>
                  {draft.reply_enabled && (
                    <div>
                      {draft.reply_texts.map((t, i) => (
                        <div key={i} style={{ display: "flex", gap: 8, marginBottom: 8 }}>
                          <textarea style={{ ...TEXTAREA, minHeight: 44 }} value={t} maxLength={2200} onChange={(e) => patch({ reply_texts: draft.reply_texts.map((x, j) => (j === i ? e.target.value : x)) })} />
                          {draft.reply_texts.length > 1 && <button type="button" onClick={() => patch({ reply_texts: draft.reply_texts.filter((_, j) => j !== i) })} style={{ border: "none", background: "transparent", cursor: "pointer" }}><Icon name="x" size={14} stroke="var(--ink-on-paper-3)" /></button>}
                        </div>
                      ))}
                      {draft.reply_texts.length < MAX_REPLY_TEXTS && <button type="button" onClick={() => patch({ reply_texts: [...draft.reply_texts, ""] })} style={{ border: "none", background: "transparent", color: "var(--vm-volt-deep)", fontWeight: 700, fontSize: 12.5, cursor: "pointer", padding: 0, fontFamily: "var(--font-body)" }}>+ Add a variation</button>}
                      <div style={{ ...HINT, marginTop: 6 }}>One variation is picked at random per comment — identical replies get flagged as spam.</div>
                    </div>
                  )}
                </div>
              )}
            </section>

            <section style={{ ...CARD, padding: 20 }}>
              <h2 style={H2}>They will get</h2>
              <DmComposer draft={draft} onChange={patch} />
            </section>

            <section style={{ ...CARD, padding: 20 }}>
              <h2 style={H2}>Advanced</h2>
              <Field label="Cooldown per person" hint="hours · 0 = off">
                <TextInput type="number" min={0} max={720} value={draft.cooldown_hours} onChange={(e) => patch({ cooldown_hours: Math.max(0, Math.min(720, Number(e.target.value) || 0)) })} />
              </Field>
              <div style={{ ...HINT, marginTop: -8 }}>Stops the same person triggering this automation again inside the window — so a "DM contains any word" automation doesn't answer every follow-up.</div>
              <div style={{ ...HINT, marginTop: 12 }}>Summary: <b>{triggerSummary(draft)}</b>{hasButton(draft) ? " → DM card with a tracked button." : " → DM."}</div>
            </section>

            {automation && (
              <section style={{ ...CARD, padding: 20 }}>
                <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between" }}>
                  <h2 style={{ ...H2, margin: 0 }}>Runs</h2>
                  <div style={{ display: "flex", alignItems: "center", gap: 14 }}>
                    <span style={HINT}>{automation.stats.runs} runs · {automation.stats.dms_sent} DMs · {automation.stats.clicked} clicks</span>
                    <Btn kind="ghost" size="sm" icon="rotate-cw" onClick={() => setRunsKey((k) => k + 1)}>Refresh</Btn>
                  </div>
                </div>
                <div style={{ marginTop: 12 }}><RunsTable automationId={automation.id} refreshKey={runsKey} /></div>
              </section>
            )}
          </div>

          {/* Preview */}
          <div style={{ position: "sticky", top: 24 }}>
            <div style={{ ...HINT, marginBottom: 10 }}>Preview</div>
            <PhonePreview draft={draft} account={account} />
            {errors.length > 0 && (
              <div style={{ ...PANEL, marginTop: 14 }}>
                <div style={{ fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 12.5, color: "var(--ink-on-paper-1)", marginBottom: 6 }}>Before going live</div>
                {errors.map((e) => <div key={e} style={{ ...HINT, display: "flex", gap: 6 }}><span>•</span>{e}</div>)}
              </div>
            )}
          </div>
        </div>
      </div>

      {picker && draft.social_account_id && (
        <PostPickerModal accountId={draft.social_account_id} selected={draft.posts} onClose={() => setPicker(false)} onSave={(posts) => patch({ posts })} onReconnect={loadAccounts} />
      )}
    </div>
  );
}
