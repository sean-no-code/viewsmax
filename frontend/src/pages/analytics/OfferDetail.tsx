// Analytics — Offer detail. Links-table-centric screen (implements the
// "Landing Page.dc.html" design): a toolbar with Install-tracking + Conversion-
// events buttons and a date filter, a sortable links table, and three modals
// (install snippet, conversion-events CRUD, new tracked link).
import { Fragment, useEffect, useMemo, useState } from "react";
import { useNavigate, useParams } from "react-router-dom";
import { toast } from "sonner";
import { useAuth } from "@/hooks/useAuth";
import { CARD, Icon, Segmented, CodeBlock, Btn, SearchSelect } from "@/components/analytics/primitives";
import { Modal, Field, TextInput } from "@/components/analytics/Modal";
import { AnalyticsShell, AnalyticsLoading, useFunnlModel, RANGE_OPTS, type Range } from "@/components/analytics/useAnalytics";
import { FiltersPanel, useUrlFilters, matchesText, matchesMulti, matchesRange, matchesDateRange, type FilterField } from "@/components/analytics/FiltersPanel";
import { PlacementIcon } from "@/components/analytics/PlacementIcon";
import { viewsMaxApi, type GoalType, type XPublishedPost } from "@/lib/api-service";
import {
  fmtMoney, fmtFull, fmtNum, fmtPct, buildSnippet, goalLabel, goalLabelMap, platformMeta,
  type PageMetric, type LinkMetric, type GoalItem,
} from "@/lib/analytics-model";

/* ---------- helpers ---------- */
// Backend stores/serializes timestamps in UTC as "YYYY-MM-DD HH:MM:SS" with no
// timezone marker. new Date() would parse that as *local* time, so a click that
// just happened shows up as hours "ago". Force UTC when no offset is present.
function parseUtcMs(iso: string): number {
  let s = String(iso).trim().replace(" ", "T");
  if (!/([zZ]|[+-]\d{2}:?\d{2})$/.test(s)) s += "Z";
  return new Date(s).getTime();
}

function timeAgo(iso: string | null): string {
  if (!iso) return "—";
  const t = parseUtcMs(iso);
  if (!Number.isFinite(t)) return "—";
  const s = Math.max(0, (Date.now() - t) / 1000);
  if (s < 60) return "just now";
  const m = s / 60; if (m < 60) return `${Math.floor(m)}m ago`;
  const h = m / 60; if (h < 24) return `${Math.floor(h)}h ago`;
  const d = h / 24; if (d < 7) return `${Math.floor(d)}d ago`;
  return new Date(t).toLocaleDateString();
}

// Treat a "Where is this link placed?" value as a URL only when it clearly is
// one (scheme, or a bare domain) — free-text like "Pinned comment" stays plain.
function placementUrl(desc?: string | null): string | null {
  if (!desc) return null;
  const v = desc.trim();
  if (/^https?:\/\//i.test(v)) return v;
  if (/^[a-z0-9-]+(\.[a-z0-9-]+)+(\/\S*)?$/i.test(v)) return "https://" + v;
  return null;
}

// Copyable tracking link (stops row navigation on click).
function CopyLink({ url }: { url: string }) {
  const [copied, setCopied] = useState(false);
  return (
    <button
      onClick={(e) => { e.stopPropagation(); navigator.clipboard?.writeText("https://" + url).catch(() => {}); setCopied(true); setTimeout(() => setCopied(false), 1400); }}
      title="Copy link"
      style={{ display: "inline-flex", alignItems: "center", gap: 7, maxWidth: 260, background: "none", border: "none", padding: 0, cursor: "pointer", fontFamily: "var(--font-mono)", fontSize: 12, color: "var(--ink-on-paper-3)" }}>
      <span style={{ overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>{url}</span>
      <Icon name={copied ? "check" : "copy"} size={13} stroke={copied ? "var(--vm-volt-deep)" : "var(--ink-on-paper-3)"} />
    </button>
  );
}

// The conversion-events dropdown is built from the DB-owned goal types
// (GET /api/goal-types). "custom" is a UI sentinel: when picked, the user types
// their own event name (stored verbatim as event_type).
const CUSTOM_GOAL_VALUE = "custom";

type SortKey = "label" | "views" | "clicks" | "viewCr" | "rev";

/* ---------- install-tracking modal ---------- */
function InstallModal({ page, snippet, onClose }: { page: PageMetric; snippet: string; onClose: () => void }) {
  return (
    <Modal title="Install tracking" sub={`Paste this snippet inside <head> on ${page.url}.`} onClose={onClose} width={680}>
      <div style={{ display: "flex", alignItems: "center", gap: 9, fontSize: 13.5, color: "var(--ink-on-paper-2)", marginBottom: 14 }}>
        <span style={{ width: 8, height: 8, borderRadius: "50%", background: page.live ? "var(--up)" : "var(--warn)", flexShrink: 0 }} />
        {page.live ? "Receiving events." : "Awaiting first event — paste the snippet, then publish to start tracking."}
      </div>
      <CodeBlock label="ViewsMax tracking" code={snippet} />
      {!page.live && <div style={{ marginTop: 14, fontSize: 12.5, color: "var(--ink-on-paper-3)" }}>No events received yet. They'll appear here within seconds of your first tracked visit.</div>}
    </Modal>
  );
}

/* ---------- conversion-events CRUD modal ---------- */
export function ConversionEventsModal({ page, goalTypes, onClose, onSaved }: { page: PageMetric; goalTypes: GoalType[]; onClose: () => void; onSaved: () => void }) {
  const [goals, setGoals] = useState<GoalItem[]>(page.goals);
  const [editingId, setEditingId] = useState<number | "new" | null>(null);
  const [form, setForm] = useState<{ path: string; type: string; value: string }>({ path: "", type: "conversion", value: "" });
  const [saving, setSaving] = useState(false);

  // The selectable types + their labels come from the DB, not the frontend.
  const builtinValues = useMemo(() => new Set(goalTypes.map((t) => t.value)), [goalTypes]);
  const labels = useMemo(() => goalLabelMap(goalTypes), [goalTypes]);

  const persist = async (next: GoalItem[]) => {
    setSaving(true);
    const payload = {
      offer_url: page.raw.offer_url,
      goals: next.map((g) => ({
        ...(g.id != null ? { id: g.id } : {}),
        event_type: g.type,
        conversion_url: g.path,
        conversion_value: g.value || 0,
      })),
    };
    const res = await viewsMaxApi.updateTrackingEvent(page.id, payload as never);
    setSaving(false);
    if (res.success && res.data) {
      const fresh = (res.data.goals || []).map((g) => ({ id: g.id ?? null, path: g.conversion_url || "", type: g.event_type || "conversion", value: Number(g.conversion_value) || 0 }));
      setGoals(fresh);
      onSaved();
      return true;
    }
    toast.error(res.error || "Couldn't save the conversion event.");
    return false;
  };

  const startAdd = () => { setEditingId("new"); setForm({ path: "", type: "conversion", value: "" }); };
  const startEdit = (g: GoalItem) => { setEditingId(g.id); setForm({ path: g.path, type: g.type, value: g.value ? String(g.value) : "" }); };
  const cancelEdit = () => { setEditingId(null); setForm({ path: "", type: "conversion", value: "" }); };

  const save = async () => {
    const path = form.path.trim();
    if (!path) { toast.error("Add the page path or URL."); return; }
    const type = form.type.trim();
    if (!type) { toast.error("Pick or name an event type."); return; }
    const item: GoalItem = { id: editingId === "new" ? null : editingId, path, type, value: Number(form.value) || 0 };
    const next = editingId === "new" ? [...goals, item] : goals.map((g) => (g.id === editingId ? item : g));
    if (await persist(next)) cancelEdit();
  };

  const remove = async (g: GoalItem) => { await persist(goals.filter((x) => x !== g)); };

  return (
    <Modal title="Conversion events" sub={`${goals.length} event(s) tracked on this offer`} onClose={onClose} width={600}>
      {goals.map((g, i) => (
        <div key={g.id ?? `new-${i}`} style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 14, padding: "14px 16px", border: "1px solid var(--line-1)", borderRadius: 12, marginBottom: 10 }}>
          <div style={{ minWidth: 0 }}>
            <div style={{ fontFamily: "var(--font-mono)", fontWeight: 600, fontSize: 14, color: "var(--ink-on-paper-1)", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>{g.path}</div>
            <div style={{ fontSize: 12.5, color: "var(--ink-on-paper-3)", marginTop: 3 }}>{goalLabel(g.type, labels)} · fires once per visitor session</div>
          </div>
          <div style={{ display: "flex", alignItems: "center", gap: 8, flexShrink: 0 }}>
            {g.value > 0 && <span style={{ fontFamily: "var(--font-mono)", fontWeight: 600, fontSize: 13, color: "var(--vm-volt-deep)", background: "var(--vm-volt-tint-l)", borderRadius: 999, padding: "4px 11px" }}>{fmtMoney(g.value)}</span>}
            <button onClick={() => startEdit(g)} style={{ height: 32, padding: "0 12px", borderRadius: 8, border: "1px solid var(--line-1)", background: "var(--paper-0)", cursor: "pointer", fontWeight: 600, fontSize: 12.5, color: "var(--ink-on-paper-2)" }}>Edit</button>
            <button onClick={() => remove(g)} style={{ height: 32, padding: "0 12px", borderRadius: 8, border: "1px solid var(--line-1)", background: "var(--paper-0)", cursor: "pointer", fontWeight: 600, fontSize: 12.5, color: "var(--ink-on-paper-2)" }}>Delete</button>
          </div>
        </div>
      ))}

      {goals.length === 0 && editingId === null && (
        <div style={{ padding: 22, textAlign: "center", border: "1px dashed var(--line-2)", borderRadius: 12, color: "var(--ink-on-paper-3)", fontSize: 13.5, marginBottom: 10 }}>No conversion events yet. Add one to start measuring what converts.</div>
      )}

      {editingId !== null ? (
        <div style={{ border: "1px solid var(--line-1)", borderRadius: 12, padding: 18, background: "var(--paper-1)" }}>
          <div style={{ fontFamily: "var(--font-mono)", fontSize: 10.5, letterSpacing: ".08em", textTransform: "uppercase", color: "var(--ink-on-paper-3)", fontWeight: 600, marginBottom: 14 }}>{editingId === "new" ? "New conversion event" : "Edit conversion event"}</div>
          <Field label="Page path"><TextInput placeholder="/thank-you" value={form.path} onChange={(e) => setForm((f) => ({ ...f, path: e.target.value }))} style={{ fontFamily: "var(--font-mono)" }} /></Field>
          <div style={{ display: "flex", gap: 12 }}>
            <div style={{ flex: 1 }}>
              <Field label="Event type">
                <select value={builtinValues.has(form.type) ? form.type : CUSTOM_GOAL_VALUE} onChange={(e) => setForm((f) => ({ ...f, type: e.target.value === CUSTOM_GOAL_VALUE ? "" : e.target.value }))} style={{ width: "100%", boxSizing: "border-box", background: "var(--paper-1)", border: "1px solid var(--line-1)", borderRadius: 12, padding: "11px 14px", fontFamily: "var(--font-body)", fontSize: 14, color: "var(--ink-on-paper-1)", outline: "none", cursor: "pointer" }}>
                  {goalTypes.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
                  <option value={CUSTOM_GOAL_VALUE}>Custom…</option>
                </select>
              </Field>
            </div>
            <div style={{ width: 140 }}>
              <Field label="Value" hint="USD"><TextInput inputMode="numeric" placeholder="49" value={form.value} onChange={(e) => setForm((f) => ({ ...f, value: e.target.value.replace(/[^0-9.]/g, "") }))} style={{ fontFamily: "var(--font-mono)" }} /></Field>
            </div>
          </div>
          {!builtinValues.has(form.type) && (
            <Field label="Custom event name" hint="your own label">
              <TextInput placeholder="e.g. demo-requested" value={form.type} onChange={(e) => setForm((f) => ({ ...f, type: e.target.value }))} />
            </Field>
          )}
          <div style={{ fontFamily: "var(--font-body)", fontSize: 12, color: "var(--ink-on-paper-2)", lineHeight: 1.5, marginBottom: 16 }}>
            When a tracked visitor lands and later hits <span style={{ fontFamily: "var(--font-mono)", color: "var(--ink-on-paper-1)" }}>{form.path.trim() || "/thank-you"}</span>, we record a <b>{fmtMoney(Number(form.value) || 49)}</b> conversion against the link that sent them.
          </div>
          <div style={{ display: "flex", gap: 10, marginTop: 4 }}>
            <Btn onClick={saving ? undefined : save}>{saving ? "Saving…" : editingId === "new" ? "Add event" : "Save changes"}</Btn>
            <Btn kind="ghost" onClick={cancelEdit}>Cancel</Btn>
          </div>
        </div>
      ) : (
        <button onClick={startAdd} style={{ width: "100%", display: "flex", alignItems: "center", justifyContent: "center", gap: 8, background: "var(--paper-0)", border: "1px dashed var(--line-2)", borderRadius: 12, padding: 14, cursor: "pointer", fontWeight: 600, fontSize: 13.5, color: "var(--ink-on-paper-1)" }}>
          <Icon name="plus" size={16} stroke="var(--ink-on-paper-1)" />Add conversion event
        </button>
      )}
    </Modal>
  );
}

/* ---------- new-link modal (source picker removed per design) ---------- */
// Platforms a tracked link can be placed on (maps to the link's placement).
// YouTube (video) + Beehiiv expose a content source we can pull reach from.
const LINK_PLATFORMS: { value: string; label: string }[] = [
  { value: "video", label: "YouTube" },
  { value: "beehiiv", label: "Beehiiv" },
  { value: "instagram", label: "Instagram" },
  { value: "tiktok", label: "TikTok" },
  { value: "x", label: "X / Twitter" },
  { value: "linkedin", label: "LinkedIn" },
  { value: "website", label: "Website" },
  { value: "blog", label: "Blog" },
  { value: "podcast", label: "Podcast" },
  { value: "email", label: "Email" },
  { value: "ad", label: "Ad" },
  { value: "other", label: "Other" },
];
const linkSelectStyle = { width: "100%", padding: "11px 13px", borderRadius: 12, border: "1px solid var(--line-1)", background: "var(--paper-0)", color: "var(--ink-on-paper-1)", fontFamily: "var(--font-body)", fontSize: 14, outline: "none" } as const;

// The content source for a link: platform + optional YouTube video / Beehiiv post,
// plus a free-text placement note for platforms without a pullable content source.
// Shared by the new-link and edit-link modals so both offer the same picker.
// The platforms a link is placed on (multi-select) + optional content sources.
// Every selected platform with a pullable content source (YouTube video,
// Beehiiv post, X post) shows its own picker. Exported for tests.
export type LinkSource = { platforms: string[]; ytVideoId: string; beehiivPostId: string; xPostId: string; location: string };

export function PlatformSourceFields({ value, set }: { value: LinkSource; set: (patch: Partial<LinkSource>) => void }) {
  const { session } = useAuth();
  const [beehiivPosts, setBeehiivPosts] = useState<{ id: string; title: string | null }[] | null>(null);
  const [ytVideos, setYtVideos] = useState<{ id: string; title: string; views?: number }[] | null>(null);
  const [xPosts, setXPosts] = useState<XPublishedPost[] | null>(null);
  const [loadingContent, setLoadingContent] = useState(false);

  // Load a content picker whenever its platform joins the selection. Loads
  // never reset the selected id — resets happen on chip toggle — so
  // edit-modal pre-population survives the initial load.
  const hasVideo = value.platforms.includes("video");
  const hasBeehiiv = value.platforms.includes("beehiiv");
  const hasX = value.platforms.includes("x");

  useEffect(() => {
    let cancelled = false;
    if (hasBeehiiv && beehiivPosts === null) {
      setLoadingContent(true);
      viewsMaxApi.getBeehiivConnection().then(async (res) => {
        if (cancelled) return;
        if (!res.success || !res.data?.connected) { setBeehiivPosts([]); setLoadingContent(false); return; }
        const p = await viewsMaxApi.getBeehiivPosts();
        if (!cancelled) { setBeehiivPosts(p.success && p.data ? p.data : []); setLoadingContent(false); }
      });
    }
    if (hasVideo && ytVideos === null && session?.token) {
      const token = session.token;
      setLoadingContent(true);
      viewsMaxApi.getChannels(token).then(async (ch) => {
        if (cancelled) return;
        if (!ch.success || !ch.data?.length) { setYtVideos([]); setLoadingContent(false); return; }
        const lists = await Promise.all(ch.data.map((c) => viewsMaxApi.getChannelVideos(token, c.id, 100)));
        if (cancelled) return;
        setYtVideos(lists.flatMap((l) => (l.success && l.data ? l.data.videos : [])).map((v) => ({ id: v.youtube_video_id, title: v.title, views: v.view_count })));
        setLoadingContent(false);
      });
    }
    if (hasX && xPosts === null) {
      setLoadingContent(true);
      viewsMaxApi.getXPublishedPosts().then((res) => {
        if (cancelled) return;
        setXPosts(res.success && res.data ? res.data : []);
        setLoadingContent(false);
      });
    }
    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [hasVideo, hasBeehiiv, hasX, session?.token]);

  const xPostLabel = (p: XPublishedPost) => {
    const when = p.posted_at ? new Date(p.posted_at).toLocaleDateString() : "";
    return when ? `${p.text} · ${when}` : p.text;
  };

  const toggle = (key: string) => {
    const on = value.platforms.includes(key);
    const next = on ? value.platforms.filter((p) => p !== key) : [...value.platforms, key];
    // Dropping a platform clears its content selection.
    set({
      platforms: next,
      ...(on && key === "video" ? { ytVideoId: "" } : null),
      ...(on && key === "beehiiv" ? { beehiivPostId: "" } : null),
      ...(on && key === "x" ? { xPostId: "" } : null),
    });
  };

  const fmtViews = (n?: number) => (n == null ? null : n >= 1000 ? `${(n / 1000).toFixed(n % 1000 === 0 ? 0 : 1)}K views` : `${n} views`);

  return (
    <>
      {/* NOT wrapped in <Field> — its <label> element would hijack every
          chip button's accessible name. */}
      <div style={{ display: "block", marginBottom: 16 }}>
        <div style={{ display: "flex", alignItems: "baseline", justifyContent: "space-between", gap: 12, marginBottom: 7 }}>
          <span style={{ fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 12.5, color: "var(--ink-on-paper-1)", whiteSpace: "nowrap" }}>Platforms</span>
          <span style={{ fontFamily: "var(--font-mono)", fontSize: 11, color: "var(--ink-on-paper-3)", whiteSpace: "nowrap" }}>everywhere this link is placed — one link, tracked across all of them</span>
        </div>
        <div style={{ display: "flex", flexWrap: "wrap", gap: 7 }} role="group" aria-label="Platforms">
          {LINK_PLATFORMS.map((p) => {
            const on = value.platforms.includes(p.value);
            return (
              <button
                key={p.value}
                type="button"
                aria-pressed={on}
                onClick={() => toggle(p.value)}
                style={{
                  padding: "7px 13px", borderRadius: 999, fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 12.5, cursor: "pointer",
                  border: on ? "1.5px solid var(--vm-red)" : "1px solid var(--line-1)",
                  background: on ? "var(--vm-red-tint-l)" : "var(--paper-0)",
                  color: on ? "var(--vm-red-deep)" : "var(--ink-on-paper-2)",
                }}
              >
                {p.label}
              </button>
            );
          })}
        </div>
      </div>
      {hasVideo && (
        <Field label="YouTube video" hint="type to search your videos — pulls in views as reach">
          <SearchSelect
            options={(ytVideos || []).map((v) => ({ id: v.id, label: fmtViews(v.views) ? `${v.title} · ${fmtViews(v.views)}` : v.title }))}
            value={value.ytVideoId}
            onChange={(id) => set({ ytVideoId: id })}
            loading={loadingContent}
            placeholder={ytVideos && ytVideos.length ? "Search your videos…" : "No videos found"}
            emptyLabel="No videos found"
            clearLabel="— No video —"
          />
        </Field>
      )}
      {hasBeehiiv && (
        <Field label="Beehiiv post" hint="type to search your posts — pulls in views as reach">
          <SearchSelect
            options={(beehiivPosts || []).map((p) => ({ id: p.id, label: p.title || p.id }))}
            value={value.beehiivPostId}
            onChange={(id) => set({ beehiivPostId: id })}
            loading={loadingContent}
            placeholder={beehiivPosts && beehiivPosts.length ? "Search your posts…" : "No posts found (connect Beehiiv)"}
            emptyLabel="No posts found (connect Beehiiv)"
            clearLabel="— No post —"
          />
        </Field>
      )}
      {hasX && (
        <>
          <Field label="X post" hint="type to search posts you published via ViewsMax">
            <SearchSelect
              options={(xPosts || []).map((p) => ({ id: p.id, label: xPostLabel(p) }))}
              value={value.xPostId}
              onChange={(id) => set({ xPostId: id })}
              loading={loadingContent}
              placeholder={xPosts && xPosts.length ? "Search your posts…" : "No posts published via ViewsMax yet"}
              emptyLabel="No posts published via ViewsMax yet"
              clearLabel="— No post —"
            />
          </Field>
          {xPosts !== null && xPosts.length === 0 && !loadingContent && (
            <div style={{ fontFamily: "var(--font-body)", fontSize: 12, color: "var(--ink-on-paper-3)", lineHeight: 1.5, margin: "-6px 0 14px" }}>
              Only posts published through ViewsMax can be picked (the X plan doesn't allow reading your timeline). You can still describe the placement below.
            </div>
          )}
        </>
      )}
      {value.platforms.length > 0 && (
        <>
          <Field label="Where is this link placed?" hint="optional"><TextInput placeholder="e.g. bio link, pinned comment, or a URL" value={value.location} onChange={(e) => set({ location: e.target.value })} /></Field>
          <div style={{ fontFamily: "var(--font-body)", fontSize: 12, color: "var(--ink-on-paper-3)", lineHeight: 1.5, margin: "-6px 0 14px" }}>The page or post this link lives on — so you can find where each link is published later.</div>
        </>
      )}
    </>
  );
}

function NewLinkModal({ page, onClose, onCreated }: { page: PageMetric; onClose: () => void; onCreated: () => void }) {
  const [label, setLabel] = useState("");
  const [src, setSrc] = useState<LinkSource>({ platforms: [], ytVideoId: "", beehiivPostId: "", xPostId: "", location: "" });
  const set = (patch: Partial<LinkSource>) => setSrc((s) => ({ ...s, ...patch }));
  const [saving, setSaving] = useState(false);

  const create = async () => {
    const name = label.trim();
    if (!name) { toast.error("Give the link a name."); return; }
    setSaving(true);
    const res = await viewsMaxApi.generateTrackingLink({
      tracking_event_id: Number(page.id),
      placement: src.platforms[0] || "other",
      placements: src.platforms.length ? src.platforms : undefined,
      youtube_video_id: src.platforms.includes("video") && src.ytVideoId ? src.ytVideoId : undefined,
      beehiiv_post_id: src.platforms.includes("beehiiv") && src.beehiivPostId ? src.beehiivPostId : undefined,
      x_post_id: src.platforms.includes("x") && src.xPostId ? src.xPostId : undefined,
      name,
      description: src.location.trim() || undefined,
    });
    setSaving(false);
    if (res.success) { toast.success("Tracking link created."); onCreated(); }
    else toast.error(res.error || "Couldn't create the link.");
  };

  return (
    <Modal title="Add a tracked link" sub="Each link maps a piece of content to this offer so we can attribute the sale." onClose={onClose}>
      <Field label="What is this link?" hint="content name"><TextInput placeholder="e.g. YouTube — my launch video" value={label} onChange={(e) => setLabel(e.target.value)} /></Field>
      <PlatformSourceFields value={src} set={set} />
      <div style={{ fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 15, color: "var(--ink-on-paper-1)", margin: "8px 0 10px" }}>Destination offer</div>
      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 12, padding: "16px 18px", border: "1.5px solid var(--vm-red)", background: "var(--vm-red-tint-l)", borderRadius: 12 }}>
        <div style={{ display: "flex", alignItems: "baseline", gap: 10, minWidth: 0 }}>
          <span style={{ fontWeight: 700, fontSize: 15, color: "var(--ink-on-paper-1)", whiteSpace: "nowrap" }}>{page.name}</span>
          <span style={{ fontFamily: "var(--font-mono)", fontSize: 13, color: "var(--ink-on-paper-3)", overflow: "hidden", textOverflow: "ellipsis" }}>{page.url}</span>
        </div>
        <Icon name="check" size={18} stroke="var(--vm-red)" />
      </div>
      <div style={{ display: "flex", justifyContent: "flex-end", gap: 12, marginTop: 24 }}>
        <Btn kind="ghost" onClick={onClose}>Cancel</Btn>
        <Btn kind="aqua" icon="check" onClick={saving ? undefined : create}>{saving ? "Creating…" : "Create link"}</Btn>
      </div>
    </Modal>
  );
}

/* ---------- edit-link modal ---------- */
function EditLinkModal({ link, onClose, onSaved }: { link: LinkMetric; onClose: () => void; onSaved: () => void }) {
  const [label, setLabel] = useState(link.raw.name || link.label || "");
  const [src, setSrc] = useState<LinkSource>({
    platforms: link.placements.length ? link.placements : (link.raw.placement ? [link.raw.placement] : []),
    ytVideoId: link.raw.youtube_video_id || "",
    beehiivPostId: link.raw.beehiiv_post_id || "",
    xPostId: link.raw.x_post_id || "",
    location: link.raw.description || "",
  });
  const set = (patch: Partial<LinkSource>) => setSrc((s) => ({ ...s, ...patch }));
  const [saving, setSaving] = useState(false);

  const save = async () => {
    const name = label.trim();
    if (!name) { toast.error("Give the link a name."); return; }
    setSaving(true);
    // Always send all source ids so the backend can re-baseline when the source
    // changes and clear it when switching to a platform without a content source.
    const res = await viewsMaxApi.updateTrackingLink(Number(link.id), {
      name,
      placement: src.platforms[0] || "other",
      placements: src.platforms.length ? src.platforms : [],
      youtube_video_id: src.platforms.includes("video") ? (src.ytVideoId || null) : null,
      beehiiv_post_id: src.platforms.includes("beehiiv") ? (src.beehiivPostId || null) : null,
      x_post_id: src.platforms.includes("x") ? (src.xPostId || null) : null,
      description: src.location.trim(),
    });
    setSaving(false);
    if (res.success) { toast.success("Link updated."); onSaved(); }
    else toast.error(res.error || "Couldn't update the link.");
  };

  return (
    <Modal title="Edit link" sub="Update this link's name, platform, or where it's placed." onClose={onClose}>
      <Field label="What is this link?" hint="content name"><TextInput placeholder="e.g. YouTube — my launch video" value={label} onChange={(e) => setLabel(e.target.value)} /></Field>
      <PlatformSourceFields value={src} set={set} />
      <div style={{ display: "flex", alignItems: "center", gap: 9, padding: "12px 14px", border: "1px solid var(--line-1)", borderRadius: 12, background: "var(--paper-1)" }}>
        <Icon name="link" size={15} stroke="var(--ink-on-paper-3)" />
        <span style={{ fontFamily: "var(--font-mono)", fontSize: 12.5, color: "var(--ink-on-paper-3)", overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>{link.short}</span>
      </div>
      <div style={{ display: "flex", justifyContent: "flex-end", gap: 12, marginTop: 24 }}>
        <Btn kind="ghost" onClick={onClose}>Cancel</Btn>
        <Btn kind="aqua" icon="check" onClick={saving ? undefined : save}>{saving ? "Saving…" : "Save changes"}</Btn>
      </div>
    </Modal>
  );
}

/* ---------- body ---------- */
function Body({ page, links, goalTypes, reload }: { page: PageMetric; links: LinkMetric[]; goalTypes: GoalType[]; reload: () => void }) {
  const navigate = useNavigate();
  const { user } = useAuth();
  const [range, setRange] = useState<Range>("28d");
  const [modal, setModal] = useState<null | "install" | "conversions" | "newlink">(null);
  const [editLink, setEditLink] = useState<LinkMetric | null>(null);
  // Rows expanded (via the eye icon) to show the per-platform click breakdown.
  const [expanded, setExpanded] = useState<Set<string>>(new Set());
  const [sortKey, setSortKey] = useState<SortKey>("clicks");
  const [sortDir, setSortDir] = useState<"asc" | "desc">("desc");

  const snippet = buildSnippet((user as { public_id?: string } | null)?.public_id || "");

  const toggleSort = (key: SortKey) => {
    if (key === sortKey) { setSortDir((d) => (d === "asc" ? "desc" : "asc")); return; }
    setSortKey(key);
    setSortDir(key === "label" ? "asc" : "desc");
  };

  // Filters (URL-persisted, AND-combined) applied client-side over the offer's
  // already-loaded links.
  const pageLinks = useMemo(() => links.filter((l) => l.page === page.id), [links, page.id]);
  const filterFields = useMemo<FilterField[]>(() => {
    const present = [...new Set(pageLinks.map((l) => l.platform))];
    return [
      { kind: "text", key: "q", label: "Search", placeholder: "Name, content, URL…", suggestions: pageLinks.flatMap((l) => [l.label, l.contentTitle ?? ""]).filter(Boolean) },
      { kind: "multi", key: "platforms", label: "Platform", options: present.map((p) => ({ value: p, label: platformMeta(p).name, icon: <PlacementIcon placement={p} size={18} /> })) },
      { kind: "daterange", key: "created", label: "Created" },
      { kind: "minmax", key: "revenue", label: "Revenue ($)" },
    ];
  }, [pageLinks]);
  const filters = useUrlFilters(filterFields);

  const rows = useMemo(() => {
    const v = filters.values;
    const filtered = pageLinks.filter((l) =>
      (matchesText(l.label, v.q) || matchesText(l.contentTitle ?? "", v.q) || matchesText(l.short, v.q))
      && matchesMulti(l.platform, v.platforms)
      && matchesDateRange(l.createdAt, v.created_from, v.created_to)
      && matchesRange(l.rev, v.revenue_min, v.revenue_max));
    const get = (l: LinkMetric): number | string =>
      sortKey === "label" ? (l.contentTitle || l.label).toLowerCase() : sortKey === "views" ? l.viewsSince : sortKey === "clicks" ? l.clicks : sortKey === "viewCr" ? (l.viewCr ?? -1) : l.rev;
    return [...filtered].sort((a, b) => {
      const av = get(a), bv = get(b);
      if (typeof av === "string" && typeof bv === "string") return sortDir === "asc" ? av.localeCompare(bv) : bv.localeCompare(av);
      return sortDir === "asc" ? (av as number) - (bv as number) : (bv as number) - (av as number);
    });
  }, [pageLinks, filters.values, sortKey, sortDir]);

  const columns: { key: SortKey | null; head: string; align: "left" | "right" }[] = [
    { key: "label", head: "Name", align: "left" },
    { key: null, head: "Link", align: "left" },
    { key: "views", head: "Content Views", align: "right" },
    { key: "clicks", head: "Clicks", align: "right" },
    { key: "viewCr", head: "Conv. rate", align: "right" },
    { key: "rev", head: "Revenue", align: "right" },
    { key: null, head: "Last click", align: "right" },
    { key: null, head: "", align: "right" },
  ];

  return (
    <>
      {/* toolbar */}
      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 16, flexWrap: "wrap" }}>
        <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
          <button onClick={() => setModal("install")} style={{ display: "inline-flex", alignItems: "center", gap: 9, background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 999, padding: "9px 16px", cursor: "pointer", fontWeight: 600, fontSize: 13.5, color: "var(--ink-on-paper-1)" }}>Install tracking</button>
          {page.goals.length === 0 ? (
            <button onClick={() => setModal("conversions")} style={{ display: "inline-flex", alignItems: "center", gap: 8, background: "var(--vm-red-tint-l)", border: "1px solid var(--vm-red)", borderRadius: 999, padding: "9px 16px", cursor: "pointer", fontWeight: 700, fontSize: 13.5, color: "var(--vm-red)" }}>
              <Icon name="alert-circle" size={16} stroke="var(--vm-red)" />No Conversion Events added
            </button>
          ) : (
            <button onClick={() => setModal("conversions")} style={{ display: "inline-flex", alignItems: "center", gap: 9, background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 999, padding: "9px 16px", cursor: "pointer", fontWeight: 600, fontSize: 13.5, color: "var(--ink-on-paper-1)" }}>
              Conversion events
              <span style={{ fontFamily: "var(--font-mono)", fontWeight: 700, fontSize: 12, background: "var(--vm-red)", color: "#fff", borderRadius: 999, minWidth: 20, height: 20, display: "inline-flex", alignItems: "center", justifyContent: "center", padding: "0 6px" }}>{page.goals.length}</span>
            </button>
          )}
        </div>
        <Segmented options={RANGE_OPTS} value={range} onChange={(v) => setRange(v as Range)} />
      </div>

      {/* links card */}
      <div style={{ ...CARD, overflow: "hidden" }}>
        <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 16, padding: "18px 22px", borderBottom: "1px solid var(--line-1)", flexWrap: "wrap" }}>
          <div style={{ fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 16, color: "var(--ink-on-paper-1)" }}>{page.url}</div>
          <Btn icon="plus" onClick={() => setModal("newlink")}>New link</Btn>
        </div>
        <div style={{ padding: "14px 22px", borderBottom: "1px solid var(--line-1)" }}>
          <FiltersPanel fields={filterFields} filters={filters} />
        </div>

        <div style={{ overflowX: "auto" }}>
          <table style={{ width: "100%", borderCollapse: "collapse", minWidth: 1040 }}>
            <thead>
              <tr>
                {columns.map((c, i) => {
                  const active = c.key && sortKey === c.key;
                  const arrow = active ? (sortDir === "asc" ? " ↑" : " ↓") : "";
                  return (
                    <th key={i} onClick={c.key ? () => toggleSort(c.key as SortKey) : undefined}
                      style={{ textAlign: c.align, padding: c.align === "left" ? "11px 22px" : "11px 16px", fontFamily: "var(--font-mono)", fontSize: 10.5, letterSpacing: ".06em", textTransform: "uppercase", fontWeight: 600, whiteSpace: "nowrap", color: active ? "var(--ink-on-paper-1)" : "var(--ink-on-paper-3)", cursor: c.key ? "pointer" : "default", userSelect: "none" }}>
                      {c.head}{arrow}
                    </th>
                  );
                })}
              </tr>
            </thead>
            <tbody>
              {rows.map((l, i) => (
                <Fragment key={l.id}>
                <tr onClick={() => navigate(`/dashboard/monetization/links/${l.id}`)}
                  style={{ borderTop: i ? "1px solid var(--paper-2)" : "1px solid var(--line-1)", cursor: "pointer" }}
                  onMouseEnter={(e) => (e.currentTarget.style.background = "var(--paper-1)")} onMouseLeave={(e) => (e.currentTarget.style.background = "transparent")}>
                  <td style={{ padding: "14px 22px" }}>
                    {(() => {
                      const href = placementUrl(l.raw.description);
                      // The linked content's actual title leads; the user's own
                      // link label drops to a second line when both exist.
                      const primary = l.contentTitle || l.label;
                      const base = { fontWeight: 600, fontSize: 14, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis", maxWidth: 320 } as const;
                      return (
                        <div style={{ display: "flex", alignItems: "center", gap: 11, minWidth: 0 }}>
                          <span title={platformMeta(l.platform).name} style={{ flexShrink: 0 }}><PlacementIcon placement={l.platform} size={30} /></span>
                          <div style={{ minWidth: 0 }}>
                            {href ? (
                              <a href={href} target="_blank" rel="noopener noreferrer" onClick={(e) => e.stopPropagation()}
                                title={`Open where this link is placed — ${l.raw.description}`}
                                style={{ ...base, display: "block", color: "var(--vm-volt-deep)", textDecoration: "none" }}>{primary}</a>
                            ) : (
                              <div style={{ ...base, color: "var(--ink-on-paper-1)" }}>{primary}</div>
                            )}
                            {l.contentTitle && l.label && l.contentTitle !== l.label && (
                              <div style={{ fontFamily: "var(--font-mono)", fontSize: 11, color: "var(--ink-on-paper-3)", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis", maxWidth: 320, marginTop: 2 }}>{l.label}</div>
                            )}
                          </div>
                        </div>
                      );
                    })()}
                  </td>
                  <td style={{ padding: "14px 16px" }}><CopyLink url={l.short} /></td>
                  <td title={l.viewsSince > 0 ? `${fmtFull(l.viewsSince)} views since this link was created (${fmtFull(l.totalViews)} total on the linked content)` : l.platform === "x" ? "View tracking isn't available for X posts (the X API plan doesn't allow reads)." : "No content-view data for this link (views show for YouTube and Beehiiv-backed links)."} style={{ padding: "14px 16px", textAlign: "right", fontFamily: "var(--font-mono)", fontWeight: 600, fontSize: 15, color: l.viewsSince > 0 ? "var(--ink-on-paper-1)" : "var(--ink-on-paper-3)" }}>{l.viewsSince > 0 ? fmtNum(l.viewsSince) : "—"}</td>
                  <td style={{ padding: "14px 16px", textAlign: "right", fontFamily: "var(--font-mono)", fontWeight: 600, fontSize: 15, color: "var(--ink-on-paper-1)" }}>{fmtFull(l.clicks)}</td>
                  <td title={l.viewCr == null ? "No view data for this link's content — reach-based rate needs views since the link was created." : undefined} style={{ padding: "14px 16px", textAlign: "right", fontFamily: "var(--font-mono)", fontSize: 13, color: l.viewCr == null ? "var(--ink-on-paper-3)" : "var(--vm-volt-deep)" }}>{l.viewCr == null ? "—" : fmtPct(l.viewCr)}</td>
                  <td style={{ padding: "14px 16px", textAlign: "right", fontFamily: "var(--font-mono)", fontWeight: 600, fontSize: 14, color: "var(--ink-on-paper-3)" }}>{fmtMoney(l.rev)}</td>
                  <td style={{ padding: "14px 16px", textAlign: "right", fontFamily: "var(--font-mono)", fontSize: 12.5, color: "var(--ink-on-paper-3)", whiteSpace: "nowrap" }}>{timeAgo(l.lastClickAt)}</td>
                  <td style={{ padding: "12px 22px", textAlign: "right" }}>
                    <div style={{ display: "inline-flex", alignItems: "center", gap: 8 }}>
                      <button onClick={(e) => { e.stopPropagation(); setEditLink(l); }} title="Edit link"
                        style={{ width: 32, height: 32, borderRadius: 8, border: "1px solid var(--line-1)", background: "var(--paper-0)", cursor: "pointer", display: "grid", placeItems: "center" }}>
                        <Icon name="edit" size={15} stroke="var(--ink-on-paper-2)" />
                      </button>
                      <button onClick={(e) => { e.stopPropagation(); setExpanded((s) => { const n = new Set(s); if (n.has(l.id)) n.delete(l.id); else n.add(l.id); return n; }); }}
                        title={expanded.has(l.id) ? "Hide platform breakdown" : "Show platform breakdown"}
                        style={{ width: 32, height: 32, borderRadius: 8, border: expanded.has(l.id) ? "1.5px solid var(--vm-red)" : "1px solid var(--line-1)", background: expanded.has(l.id) ? "var(--vm-red-tint-l)" : "var(--paper-0)", cursor: "pointer", display: "grid", placeItems: "center" }}>
                        <Icon name="eye" size={15} stroke={expanded.has(l.id) ? "var(--vm-red)" : "var(--ink-on-paper-2)"} />
                      </button>
                      <button onClick={(e) => { e.stopPropagation(); navigate(`/dashboard/monetization/links/${l.id}`); }} title="Open link analytics"
                        style={{ width: 32, height: 32, borderRadius: 8, border: "1px solid var(--line-1)", background: "var(--paper-0)", cursor: "pointer", display: "grid", placeItems: "center" }}>
                        <Icon name="bar-chart" size={15} stroke="var(--ink-on-paper-2)" />
                      </button>
                    </div>
                  </td>
                </tr>
                {expanded.has(l.id) && (
                  <tr>
                    <td colSpan={8} style={{ padding: "0 22px 16px", background: "var(--paper-1)", borderTop: "1px dashed var(--line-1)" }}>
                      <div style={{ display: "flex", gap: 32, flexWrap: "wrap", padding: "14px 4px 2px" }}>
                        {/* Clicks by platform (from each click's referrer classification) */}
                        <div style={{ minWidth: 240 }}>
                          <div style={{ fontFamily: "var(--font-mono)", fontSize: 10.5, fontWeight: 700, letterSpacing: ".06em", color: "var(--ink-on-paper-3)", marginBottom: 8 }}>CLICKS BY PLATFORM</div>
                          {Object.keys(l.platformBreakdown).length === 0 ? (
                            <div style={{ fontFamily: "var(--font-body)", fontSize: 12.5, color: "var(--ink-on-paper-3)" }}>No clicks yet.</div>
                          ) : (() => {
                            const entries = Object.entries(l.platformBreakdown).sort((a, b) => b[1] - a[1]);
                            const max = entries[0][1] || 1;
                            return entries.map(([key, count]) => (
                              <div key={key} style={{ display: "flex", alignItems: "center", gap: 9, marginBottom: 5 }}>
                                <span style={{ width: 86, fontFamily: "var(--font-body)", fontSize: 12, fontWeight: 600, color: "var(--ink-on-paper-2)", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>{platformMeta(key).name}</span>
                                <span style={{ flex: 1, maxWidth: 180, height: 8, borderRadius: 999, background: "var(--paper-2)", overflow: "hidden" }}>
                                  <span style={{ display: "block", width: `${Math.max(6, (count / max) * 100)}%`, height: "100%", borderRadius: 999, background: "var(--vm-red)" }} />
                                </span>
                                <span style={{ fontFamily: "var(--font-mono)", fontSize: 12, fontWeight: 700, color: "var(--ink-on-paper-1)" }}>{fmtFull(count)}</span>
                              </div>
                            ));
                          })()}
                        </div>
                        {/* Declared placements */}
                        <div>
                          <div style={{ fontFamily: "var(--font-mono)", fontSize: 10.5, fontWeight: 700, letterSpacing: ".06em", color: "var(--ink-on-paper-3)", marginBottom: 8 }}>PLACED ON</div>
                          <div style={{ display: "flex", flexWrap: "wrap", gap: 6, maxWidth: 260 }}>
                            {l.placements.map((p) => (
                              <span key={p} style={{ display: "inline-flex", alignItems: "center", gap: 6, padding: "4px 10px", borderRadius: 999, border: "1px solid var(--line-1)", background: "var(--paper-0)", fontFamily: "var(--font-body)", fontSize: 12, fontWeight: 600, color: "var(--ink-on-paper-2)" }}>
                                <PlacementIcon placement={p} size={16} /> {platformMeta(p).name}
                              </span>
                            ))}
                          </div>
                        </div>
                        {/* Top referrers */}
                        <div style={{ minWidth: 200 }}>
                          <div style={{ fontFamily: "var(--font-mono)", fontSize: 10.5, fontWeight: 700, letterSpacing: ".06em", color: "var(--ink-on-paper-3)", marginBottom: 8 }}>TOP REFERRERS</div>
                          {l.topReferrers.length === 0 ? (
                            <div style={{ fontFamily: "var(--font-body)", fontSize: 12.5, color: "var(--ink-on-paper-3)" }}>No referrer data yet.</div>
                          ) : l.topReferrers.map((r) => (
                            <div key={r.host} title={r.url} style={{ display: "flex", alignItems: "center", gap: 8, marginBottom: 4, fontFamily: "var(--font-mono)", fontSize: 12 }}>
                              <span style={{ color: "var(--ink-on-paper-2)" }}>{r.host}</span>
                              <span style={{ color: "var(--ink-on-paper-3)" }}>×{r.count}</span>
                            </div>
                          ))}
                        </div>
                      </div>
                    </td>
                  </tr>
                )}
                </Fragment>
              ))}
            </tbody>
          </table>
          {rows.length === 0 && (
            <div style={{ padding: 36, textAlign: "center", color: "var(--ink-on-paper-3)", fontSize: 13.5 }}>
              {filters.activeCount > 0 ? "No links match your filters." : "No links yet — add your first to start attributing traffic."}
            </div>
          )}
        </div>
      </div>

      {modal === "install" && <InstallModal page={page} snippet={snippet} onClose={() => setModal(null)} />}
      {modal === "conversions" && <ConversionEventsModal page={page} goalTypes={goalTypes} onClose={() => setModal(null)} onSaved={reload} />}
      {modal === "newlink" && <NewLinkModal page={page} onClose={() => setModal(null)} onCreated={() => { setModal(null); reload(); }} />}
      {editLink && <EditLinkModal link={editLink} onClose={() => setEditLink(null)} onSaved={() => { setEditLink(null); reload(); }} />}
    </>
  );
}

export default function OfferDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { model, loading, reload } = useFunnlModel();
  if (loading || !model) return <AnalyticsShell><AnalyticsLoading /></AnalyticsShell>;
  const page = model.pages.find((p) => p.id === id);
  return (
    <AnalyticsShell>
      {page ? <Body page={page} links={model.links} goalTypes={model.goalTypes} reload={reload} /> : (
        <>
          <button onClick={() => navigate("/dashboard/monetization/offers")} style={{ display: "inline-flex", alignItems: "center", gap: 7, background: "none", border: "none", cursor: "pointer", padding: 0, fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 13, color: "var(--ink-on-paper-2)", alignSelf: "flex-start" }}>
            <Icon name="arrow-left" size={15} stroke="var(--ink-on-paper-2)" />Offers
          </button>
          <div style={{ ...CARD, padding: 48, textAlign: "center", color: "var(--ink-on-paper-3)" }}>Offer not found.</div>
        </>
      )}
    </AnalyticsShell>
  );
}
