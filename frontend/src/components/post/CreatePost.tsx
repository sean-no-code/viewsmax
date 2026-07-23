// Create Post — Direction A "Split Studio": editor on the left, live phone
// preview on the right. Ported from the ViewsMax design kit; wired to the
// posts backend (createPost).
import { useEffect, useMemo, useRef, useState, type CSSProperties } from "react";
import { useNavigate } from "react-router-dom";
import { toast } from "sonner";
import { Icon, Btn } from "@/components/analytics/primitives";
import { PLATFORMS, PMAP, PAvatar, MediaTile, PostPreview, CountChip, usePostComposer, platformAllowedForType, type Composer } from "@/components/post/composer";
import { viewsMaxApi, resolveMediaUrl, type Brand } from "@/lib/api-service";
import { useAccountDirectory } from "@/components/post/useAccountDirectory";
import { useBrands } from "@/components/post/useBrands";
import MentionTextarea from "@/components/post/MentionTextarea";
import { autoSplitX, xWeightedLength, X_LIMIT, X_MAX_IMAGES, type XThreadStatus } from "@/lib/text-metrics";

const fieldBase: CSSProperties = {
  fontFamily: "var(--font-body)", fontSize: 14, color: "var(--ink-on-paper-1)",
  background: "var(--paper-0)", border: "1px solid var(--line-2)", borderRadius: 12,
  padding: "11px 13px", outline: "none", width: "100%",
};

function SectionLabel({ children, hint }: { children: React.ReactNode; hint?: string }) {
  return (
    <div style={{ display: "flex", alignItems: "baseline", justifyContent: "space-between", marginBottom: 10 }}>
      <span className="vm-eyebrow" style={{ color: "var(--ink-on-paper-3)" }}>{children}</span>
      {hint && <span style={{ fontFamily: "var(--font-mono)", fontSize: 11, color: "var(--ink-on-paper-3)" }}>{hint}</span>}
    </div>
  );
}

function Card({ children, style }: { children: React.ReactNode; style?: CSSProperties }) {
  return <div style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 18, padding: 20, boxShadow: "0 1px 2px rgba(10,10,12,.04)", ...style }}>{children}</div>;
}

function tabStyle(active: boolean): CSSProperties {
  return {
    display: "inline-flex", alignItems: "center", gap: 6, padding: "6px 11px", borderRadius: 999,
    border: "1px solid " + (active ? "var(--ink-on-paper-1)" : "var(--line-1)"),
    background: active ? "var(--ink-on-paper-1)" : "var(--paper-0)",
    color: active ? "#fff" : "var(--ink-on-paper-2)", cursor: "pointer",
    fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 12.5,
  };
}

const PRIVACY_LABELS: Record<string, string> = {
  PUBLIC_TO_EVERYONE: "Everyone",
  MUTUAL_FOLLOW_FRIENDS: "Friends",
  FOLLOWER_OF_CREATOR: "Followers",
  SELF_ONLY: "Only me (private)",
};

// Platforms surfaced in the "Publishing to" picker — the ones the connections
// screen can actually connect today. Order follows the PLATFORMS catalog.
const SUPPORTED_PLATFORM_IDS = ["youtube", "tiktok", "instagram", "x", "linkedin", "facebook", "threads", "bluesky"];

function Toggle({ label, checked, disabled, onChange }: { label: string; checked: boolean; disabled?: boolean; onChange: (v: boolean) => void }) {
  return (
    <label style={{ display: "inline-flex", alignItems: "center", gap: 8, cursor: disabled ? "not-allowed" : "pointer", opacity: disabled ? 0.45 : 1 }}>
      <span
        onClick={() => !disabled && onChange(!checked)}
        style={{ width: 36, height: 20, borderRadius: 999, background: checked ? "var(--ink-on-paper-1)" : "var(--line-2)", position: "relative", transition: "background var(--dur)", flexShrink: 0 }}
      >
        <span style={{ position: "absolute", top: 2, left: checked ? 18 : 2, width: 16, height: 16, borderRadius: "50%", background: "#fff", transition: "left var(--dur)", boxShadow: "0 1px 2px rgba(0,0,0,.3)" }} />
      </span>
      <span style={{ fontSize: 12.5, color: "var(--ink-on-paper-2)", fontWeight: 600 }}>{label}</span>
    </label>
  );
}

const todayStr = () => {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
};

/* ---------------- Follow-up comments ---------------- */
// Extra messages posted after the post goes live: X reply threads, LinkedIn /
// Instagram comments, Threads replies. Each comment can wait a delay after the
// previous message in the chain — either a fixed preset or custom minutes.
const COMMENT_PLATFORMS = ["x", "linkedin", "threads", "instagram"];
const DELAY_PRESETS: Array<{ label: string; seconds: number }> = [
  { label: "Immediately", seconds: 0 },
  { label: "1m", seconds: 60 },
  { label: "2m", seconds: 120 },
  { label: "5m", seconds: 300 },
  { label: "10m", seconds: 600 },
  { label: "15m", seconds: 900 },
  { label: "30m", seconds: 1800 },
  { label: "1h", seconds: 3600 },
  { label: "2h", seconds: 7200 },
];

type ComposerComment = { body: string; delaySeconds: number };

function CommentsCard({ comments, setComments, selected, xAccountId }: {
  comments: ComposerComment[];
  setComments: React.Dispatch<React.SetStateAction<ComposerComment[]>>;
  selected: string[];
  /** Connected X account backing the @mention typeahead (comments post as X replies). */
  xAccountId?: number | null;
}) {
  const supported = selected.filter((id) => COMMENT_PLATFORMS.includes(id));
  const unsupported = selected.filter((id) => !COMMENT_PLATFORMS.includes(id));
  const patch = (i: number, p: Partial<ComposerComment>) =>
    setComments((list) => list.map((cm, idx) => (idx === i ? { ...cm, ...p } : cm)));
  const remove = (i: number) => setComments((list) => list.filter((_, idx) => idx !== i));

  return (
    <div style={{ borderTop: "1px solid var(--line-1)", marginTop: 18, paddingTop: 16 }}>
      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 10, marginBottom: 10 }}>
        <span className="vm-eyebrow" style={{ color: "var(--ink-on-paper-3)" }}>
          Comments{comments.length ? ` · ${comments.length}` : ""}
        </span>
        <button
          onClick={() => setComments((list) => [...list, { body: "", delaySeconds: 0 }])}
          style={{ background: "var(--vm-red)", color: "#fff", border: "none", borderRadius: 999, padding: "7px 13px", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 12.5, cursor: "pointer", flexShrink: 0 }}
        >
          + Add comment
        </button>
      </div>
      {comments.map((cm, i) => {
        const xOver = selected.includes("x") && xWeightedOver(cm.body);
        return (
          <div key={i} style={{ border: "1px solid var(--line-1)", borderRadius: 12, padding: 12, marginBottom: 10, background: "var(--paper-1)" }}>
            <div style={{ display: "flex", alignItems: "flex-start", gap: 8 }}>
              <div style={{ flex: 1 }}>
                <MentionTextarea
                  value={cm.body}
                  onChangeText={(v) => patch(i, { body: v })}
                  mentions={selected.includes("x")}
                  accountId={xAccountId}
                  rows={2}
                  placeholder={i === 0 ? "e.g. Register to my newsletter → link" : "Another follow-up…"}
                  style={{ width: "100%", border: "1px solid var(--line-1)", borderRadius: 10, padding: "9px 11px", fontFamily: "var(--font-body)", fontSize: 13.5, lineHeight: 1.55, resize: "vertical", background: "var(--paper-0)", color: "var(--ink-on-paper-1)" }}
                />
              </div>
              <button onClick={() => remove(i)} title="Remove comment" style={{ width: 30, height: 30, borderRadius: 999, border: "1px solid var(--line-1)", background: "var(--paper-0)", cursor: "pointer", display: "grid", placeItems: "center", flexShrink: 0 }}>
                <Icon name="trash-2" size={13} stroke="var(--ink-on-paper-3)" />
              </button>
            </div>
            <div style={{ display: "flex", alignItems: "center", gap: 8, marginTop: 8, flexWrap: "wrap" }}>
              <span style={{ fontSize: 11, fontWeight: 700, color: "var(--ink-on-paper-3)" }}>Delay</span>
              <select
                value={DELAY_PRESETS.some((p) => p.seconds === cm.delaySeconds) ? String(cm.delaySeconds) : "custom"}
                onChange={(e) => {
                  // "Custom…" flips to a non-preset value so the minutes input appears.
                  patch(i, { delaySeconds: e.target.value === "custom" ? 180 : Number(e.target.value) });
                }}
                style={{ border: "1px solid var(--line-1)", borderRadius: 8, padding: "5px 8px", fontFamily: "var(--font-body)", fontSize: 12, background: "var(--paper-0)", color: "var(--ink-on-paper-1)" }}
              >
                {DELAY_PRESETS.map((p) => <option key={p.seconds} value={p.seconds}>{p.label}</option>)}
                <option value="custom">Custom…</option>
              </select>
              {!DELAY_PRESETS.some((p) => p.seconds === cm.delaySeconds) ? (
                <label style={{ display: "inline-flex", alignItems: "center", gap: 5, fontSize: 12, color: "var(--ink-on-paper-2)" }}>
                  <input
                    type="number"
                    min={0}
                    max={120}
                    value={Math.round(cm.delaySeconds / 60)}
                    onChange={(e) => patch(i, { delaySeconds: Math.max(0, Math.min(120, Number(e.target.value) || 0)) * 60 })}
                    style={{ width: 60, border: "1px solid var(--line-1)", borderRadius: 8, padding: "5px 8px", fontFamily: "var(--font-mono)", fontSize: 12, background: "var(--paper-0)", color: "var(--ink-on-paper-1)" }}
                  /> min
                </label>
              ) : null}
              {i > 0 && <span style={{ fontSize: 11, color: "var(--ink-on-paper-3)" }}>after the previous comment</span>}
              {selected.includes("x") && (
                <span style={{ marginLeft: "auto", fontFamily: "var(--font-mono)", fontSize: 11, color: xOver ? "var(--vm-red)" : "var(--ink-on-paper-3)" }}>
                  {xRemaining(cm.body)} left on X
                </span>
              )}
            </div>
          </div>
        );
      })}
      {comments.length > 0 && (
        <div style={{ marginTop: 10, fontSize: 11.5, color: "var(--ink-on-paper-3)", lineHeight: 1.5 }}>
          {supported.length > 0 && <>Will post to: {supported.map((id) => PMAP[id]?.name ?? id).join(", ")}.</>}
          {unsupported.length > 0 && <> {unsupported.map((id) => PMAP[id]?.name ?? id).join(", ")} {unsupported.length === 1 ? "doesn't" : "don't"} support comments and will skip them.</>}
        </div>
      )}
    </div>
  );
}

const xRemaining = (text: string) => X_LIMIT - xWeightedLength(text);
const xWeightedOver = (text: string) => xWeightedLength(text) > X_LIMIT;

// X thread breakdown under the caption: one clickable chip-tab per tweet (its
// remaining count as the label), a "+" chip to append a tweet, and an
// auto-split action when something is over 280.
function XThreadPanel({ thread, activeIdx, onSelect, onAdd, onAutoSplit }: {
  thread: XThreadStatus;
  activeIdx: number | null; // highlighted chip when the X editor is open
  onSelect: (i: number) => void;
  onAdd: () => void;
  onAutoSplit: () => void;
}) {
  const anyOver = thread.segments.some((s) => s.over) || thread.tooMany;
  const count = Math.max(1, thread.segments.length);
  const segments = thread.segments.length ? thread.segments : [{ text: "", len: 0, over: false, empty: true }];
  return (
    <div style={{ marginTop: 12, display: "flex", flexDirection: "column", gap: 8 }}>
      <div style={{ display: "flex", alignItems: "center", gap: 7, flexWrap: "wrap" }}>
        <span style={{ display: "inline-flex", alignItems: "center", gap: 6, fontFamily: "var(--font-mono)", fontSize: 11, fontWeight: 700, color: "var(--ink-on-paper-3)", textTransform: "uppercase", letterSpacing: ".04em" }}>
          <PAvatar id="x" size={16} /> {count <= 1 ? "X post" : `X thread · ${count} posts`}
        </span>
        {segments.map((s, i) => {
          const active = i === activeIdx;
          const bad = s.over || (s.empty && count > 1);
          return (
            <button key={i} onClick={() => onSelect(i)} title={`Edit tweet ${i + 1}`}
              style={{
                display: "inline-flex", alignItems: "center", gap: 5, cursor: "pointer",
                background: bad ? "var(--vm-red-tint-l)" : active ? "var(--ink-on-paper-1)" : "var(--paper-2)",
                border: bad ? "1px solid rgba(255,31,61,.4)" : "1px solid transparent",
                borderRadius: 999, padding: "3px 9px", fontFamily: "var(--font-mono)", fontSize: 11, fontWeight: 700,
                color: bad ? "var(--vm-red)" : active ? "#fff" : "var(--ink-on-paper-3)",
              }}>
              {i + 1} · {s.empty ? "empty" : s.over ? `−${s.len - X_LIMIT}` : X_LIMIT - s.len}
            </button>
          );
        })}
        <button onClick={onAdd} title="Add a tweet to the thread"
          style={{ display: "inline-flex", alignItems: "center", cursor: "pointer", background: "var(--paper-0)", border: "1px dashed var(--line-2)", borderRadius: 999, padding: "3px 10px", fontFamily: "var(--font-mono)", fontSize: 11, fontWeight: 700, color: "var(--ink-on-paper-2)" }}>
          +
        </button>
        {anyOver && (
          <button onClick={onAutoSplit} style={{ display: "inline-flex", alignItems: "center", gap: 5, background: "var(--ink-on-paper-1)", color: "#fff", border: "none", borderRadius: 999, padding: "5px 12px", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 12, cursor: "pointer" }}>
            <Icon name="zap" size={12} stroke="#fff" /> Auto-split into a thread
          </button>
        )}
      </div>
      {thread.tooMany && (
        <span style={{ fontSize: 12, fontWeight: 600, color: "var(--vm-red)" }}>Threads are limited to 25 tweets.</span>
      )}
    </div>
  );
}

// Per-tweet editor shown on the X caption tab: a chain of clickable line
// segments (one per tweet), then ONE textarea editing the selected tweet.
// Users never see or type the raw `---` separator here — the serialization
// into the X override happens in the composer hook.
function XTweetEditor({ c, active, onSelect }: { c: Composer; active: number; onSelect: (i: number) => void }) {
  const thread = c.status.x.thread;
  const segments = thread && thread.segments.length ? thread.segments : [{ text: c.textFor("x"), len: 0, over: false, empty: true }];
  const idx = Math.min(active, segments.length - 1);
  const current = segments[idx];

  // Local mirror of the active tweet so typing isn't disturbed by the
  // trim-on-serialize round-trip; re-synced when switching tweets or when the
  // stored text changes externally (auto-split, chip actions).
  const [local, setLocal] = useState(current.text);
  const lastIdx = useRef(idx);
  useEffect(() => {
    if (lastIdx.current !== idx) {
      lastIdx.current = idx;
      setLocal(current.text);
      return;
    }
    setLocal((prev) => (current.text === prev.trim() ? prev : current.text));
  }, [idx, current.text]);

  const remaining = X_LIMIT - current.len;
  return (
    <div>
      {/* chain: one line per tweet */}
      <div style={{ display: "flex", gap: 4, marginBottom: 10 }}>
        {segments.map((s, i) => (
          <button key={i} onClick={() => onSelect(i)} title={`Tweet ${i + 1}${s.over ? " — over limit" : ""}`}
            style={{
              flex: 1, height: 5, borderRadius: 999, border: "none", cursor: "pointer", padding: 0,
              background: s.over || (s.empty && segments.length > 1) ? "var(--vm-red)" : i === idx ? "var(--ink-on-paper-1)" : "var(--line-2)",
              transition: "background var(--dur)",
            }} />
        ))}
      </div>
      <MentionTextarea
        value={local}
        onChangeText={(v) => { setLocal(v); c.setXSegment(idx, v); }}
        mentions
        accountId={c.accountSel["x"]?.[0]}
        rows={5}
        style={{ ...fieldBase, resize: "vertical", lineHeight: 1.55, minHeight: 110 }}
        placeholder={idx === 0 ? "Write the first tweet…" : "Write this tweet…"}
      />
      <div style={{ display: "flex", alignItems: "center", gap: 10, marginTop: 10, flexWrap: "wrap" }}>
        <span style={{ fontFamily: "var(--font-mono)", fontSize: 11.5, fontWeight: 700, color: "var(--ink-on-paper-2)" }}>
          Tweet {idx + 1} of {segments.length}
        </span>
        <span style={{ fontFamily: "var(--font-mono)", fontSize: 11.5, fontWeight: 700, color: current.over ? "var(--vm-red)" : remaining < X_LIMIT * 0.15 ? "var(--warn)" : "var(--ink-on-paper-3)" }}>
          {current.over ? `−${current.len - X_LIMIT}` : remaining}
        </span>
        <button onClick={() => { c.addXSegment(idx); onSelect(idx + 1); }}
          style={{ display: "inline-flex", alignItems: "center", gap: 5, background: "var(--paper-2)", border: "1px solid var(--line-1)", borderRadius: 999, padding: "5px 12px", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 12, color: "var(--ink-on-paper-1)", cursor: "pointer" }}>
          <Icon name="plus" size={12} stroke="var(--ink-on-paper-1)" /> Add tweet
        </button>
        {segments.length > 1 && (
          <button onClick={() => { c.removeXSegment(idx); onSelect(Math.min(idx, segments.length - 2)); }}
            style={{ display: "inline-flex", alignItems: "center", gap: 5, background: "none", border: "none", color: "var(--vm-red)", fontWeight: 700, fontSize: 12, cursor: "pointer", fontFamily: "var(--font-body)" }}>
            <Icon name="trash-2" size={12} stroke="var(--vm-red)" /> Delete tweet
          </button>
        )}
      </div>
    </div>
  );
}

export default function CreatePost({ onSaved, editId }: { onSaved?: () => void; editId?: number }) {
  const navigate = useNavigate();
  const c = usePostComposer();
  const [tab, setTab] = useState("all"); // 'all' or platform id
  // The picker always shows these; connected ones are selectable, the rest get
  // a red "connect" badge linking to the connections screen.
  const supportedPlatforms = PLATFORMS.filter((p) => SUPPORTED_PLATFORM_IDS.includes(p.id));
  // Narrow the picker to platforms that can carry the chosen post type. A "text"
  // post hides media-only platforms (YouTube/TikTok/Instagram); a "media" post
  // hides text-only ones (X).
  const visiblePlatforms = supportedPlatforms.filter((p) => platformAllowedForType(p.id, c.postType));
  // Connected accounts, merged from both backends: the legacy Connection store
  // (YouTube/TikTok/Instagram — drives posting) and the newer SocialAccount
  // store (X, LinkedIn, Threads, …). The directory also carries each account's
  // avatar + display name so the picker can show who you're posting as.
  // reconnectIds: platforms whose account exists but can't publish until the
  // user re-runs OAuth (expired/revoked token) — shown as "Reconnect".
  const { directory: accountDirectory, accountsByPlatform, connectedIds, reconnectIds, loading: connLoading } = useAccountDirectory();
  const connectedCount = visiblePlatforms.filter((p) => connectedIds.includes(p.id) && !reconnectIds.includes(p.id)).length;
  const { brands } = useBrands();

  // Expand a brand into the composer's selection shape, keeping only accounts
  // that can actually publish right now (same rules as the "All" button).
  const expandBrand = (b: Brand): { sel: string[]; acctSel: Record<string, number[]> } => {
    const acctSel: Record<string, number[]> = {};
    const sel = new Set<string>();
    for (const a of b.social_accounts) {
      if (!visiblePlatforms.some((p) => p.id === a.platform)) continue;
      const entry = (accountsByPlatform[a.platform] ?? []).find((e) => e.id === a.id);
      if (!entry || entry.needsReconnect) continue;
      (acctSel[a.platform] ??= []).push(a.id);
      sel.add(a.platform);
    }
    for (const conn of b.connections) {
      const pid = conn.provider;
      if (!visiblePlatforms.some((p) => p.id === pid)) continue;
      if (accountsByPlatform[pid]?.length) continue; // platform moved to the social store
      if (connectedIds.includes(pid) && !reconnectIds.includes(pid)) sel.add(pid);
    }
    return { sel: [...sel], acctSel };
  };

  const applyBrand = (b: Brand) => {
    const { sel, acctSel } = expandBrand(b);
    if (sel.length === 0) {
      toast.error(`None of ${b.name}'s accounts can post right now — reconnect them on the Connections page.`);
      return;
    }
    c.setSelected(sel);
    c.setAccountSel(acctSel);
  };

  // A brand chip stays lit only while the current selection matches its
  // expansion exactly — manual toggles naturally detach it, and the saved
  // brand_id reflects what will actually publish.
  const selKey = (sel: string[], acctSel: Record<string, number[]>) =>
    [...sel].sort().join(",") + "|" + Object.entries(acctSel)
      .filter(([, ids]) => ids.length)
      .sort(([a], [z]) => a.localeCompare(z))
      .map(([pid, ids]) => `${pid}:${[...ids].sort((a, z) => a - z).join("+")}`)
      .join(",");
  const activeBrandId = useMemo(() => {
    if (c.selected.length === 0) return null;
    const current = selKey(c.selected, c.accountSel);
    const match = brands.find((b) => {
      const { sel, acctSel } = expandBrand(b);
      return sel.length > 0 && selKey(sel, acctSel) === current;
    });
    return match?.id ?? null;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [brands, c.selected, c.accountSel, accountsByPlatform, connectedIds, reconnectIds, c.postType]);
  const [previewId, setPreviewId] = useState("instagram");
  const [optTab, setOptTab] = useState(""); // active platform tab in the options card
  const [saving, setSaving] = useState(false);
  const [done, setDone] = useState<null | "now" | "schedule">(null);
  const mediaInput = useRef<HTMLInputElement>(null);
  const coverInput = useRef<HTMLInputElement>(null);
  const isEditing = editId != null;
  // Loading/missing state while fetching an existing post to edit.
  const [loadingPost, setLoadingPost] = useState(isEditing);
  const [loadError, setLoadError] = useState<string | null>(null);

  // Editing an existing post: fetch it once and seed the composer.
  useEffect(() => {
    if (editId == null) return;
    let cancelled = false;
    setLoadingPost(true);
    setLoadError(null);
    viewsMaxApi.getPost(editId).then((res) => {
      if (cancelled) return;
      if (res.success && res.data) c.hydrate(res.data);
      else setLoadError(res.error || "Couldn't load this post.");
      setLoadingPost(false);
    });
    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [editId]);

  const onPickFile = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    e.target.value = ""; // allow re-selecting the same file
    if (!file) return;
    const type: "image" | "video" = file.type.startsWith("video") ? "video" : "image";
    const res = await c.uploadMedia(file, type);
    if (!res.success) toast.error(res.error || "Upload failed.");
  };

  const onPickCover = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    e.target.value = "";
    if (!file) return;
    const res = await c.uploadCover(file);
    if (!res.success) toast.error(res.error || "Cover upload failed.");
  };

  // Default the date for a fresh composer; when editing, hydrate sets it instead.
  useEffect(() => { if (!isEditing && !c.date) c.setDate(todayStr()); /* eslint-disable-next-line */ }, []);
  useEffect(() => { if (!c.selected.includes(previewId) && c.selected[0]) setPreviewId(c.selected[0]); }, [c.selected, previewId]);
  // Switching post type deselects platforms that can't carry it.
  useEffect(() => {
    c.setSelected((sel) => sel.filter((id) => platformAllowedForType(id, c.postType)));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [c.postType]);
  useEffect(() => { if (tab !== "all" && !c.selected.includes(tab)) setTab("all"); }, [c.selected, tab]);
  // Load TikTok posting options the first time TikTok is selected.
  useEffect(() => {
    if (c.selected.includes("tiktok") && !c.creatorInfo && !c.creatorInfoLoading && !c.creatorInfoError) c.loadCreatorInfo();
    /* eslint-disable-next-line */
  }, [c.selected]);

  const editingOverride = tab !== "all";
  const overrideVal = editingOverride ? (c.overrides[tab] ?? "") : "";
  const uploadingMedia = c.media.find((m) => m.uploading);
  const failedMedia = c.media.filter((m) => m.error);
  // Drag-to-reorder: index of the tile currently being dragged. Reorder happens
  // live as it's dragged over another tile (dependency-free HTML5 DnD).
  const [dragIdx, setDragIdx] = useState<number | null>(null);
  // Download a media item to disk. Fetches the bytes into a blob so the browser
  // saves it (a cross-origin `download` attr is ignored and would just navigate).
  const downloadMedia = async (item: typeof c.media[number]) => {
    const url = item.preview || resolveMediaUrl(item.url);
    if (!url) return;
    try {
      const res = await fetch(url);
      const blob = await res.blob();
      const obj = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = obj;
      const ext = item.type === "video" ? "mp4" : (blob.type.split("/")[1] || "jpg");
      a.download = (item.path?.split("/").pop() || `media-${item.id}`).replace(/\.[^.]+$/, "") + `.${ext}`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(obj);
    } catch {
      // Fall back to opening the asset in a new tab if the fetch is blocked.
      window.open(url, "_blank", "noopener");
    }
  };
  const hasVideo = c.media.some((m) => m.type === "video");
  // LinkedIn can't publish video — warn if the user pairs a video with LinkedIn.
  const linkedInVideoConflict = c.postType === "media" && hasVideo && c.selected.includes("linkedin");
  // X can't publish video either, and takes at most 4 images per post.
  const xVideoConflict = c.postType === "media" && hasVideo && c.selected.includes("x");
  const imageCount = c.media.filter((m) => m.type === "image").length;
  const xImageOverflow = c.postType === "media" && imageCount > X_MAX_IMAGES && c.selected.includes("x");
  // X thread state for the caption panel (shared caption or the X override).
  const [xActive, setXActive] = useState(0); // tweet selected in the X editor
  const xThread = c.status.x.thread;
  const xSegCount = Math.max(1, xThread?.segments.length ?? 1);
  // On the X tab the panel is the tweet tab bar, so it always shows; on the
  // shared tab it only appears once there's a thread (or an over-limit issue).
  const showXThread = c.selected.includes("x")
    && (tab === "x" || (tab === "all" && !!xThread && (xThread.segments.length > 1 || c.status.x.over)));
  const openXTweet = (i: number) => { setTab("x"); setXActive(i); };
  const addXTweet = () => { c.addXSegment(xSegCount - 1); openXTweet(xSegCount); };
  const captionHasDelimiter = /^[ \t]*---[ \t]*$/m.test(c.caption);

  // Per-platform publish options live in one tabbed card. A platform earns a
  // tab only when it actually has options to show: TikTok / YouTube always do;
  // Instagram's only option is the Reel cover, so it appears solely when the
  // post carries a video. X / LinkedIn / Threads have no publish options
  // (LinkedIn's old first-comment moved to the generic Comments card).
  const mediaVideoPost = c.postType === "media" && hasVideo;
  const optionTabs = c.selected.filter((id) =>
    id === "tiktok" || id === "youtube" || (id === "instagram" && mediaVideoPost)
  );
  const activeOptTab = optionTabs.includes(optTab) ? optTab : (optionTabs[0] ?? "");

  // TikTok audit compliance — gating shared by the options tab, the pre-publish
  // declaration, and persist(). Publishing is blocked until the user has made
  // the mandatory choices (privacy chosen; commercial disclosure resolved;
  // branded content not private; video within the account's max duration).
  const tiktokSelected = c.selected.includes("tiktok");
  const tiktokMaxDur = c.creatorInfo?.max_video_post_duration_sec ?? 0;
  const tiktokVideoDuration = c.media.find((m) => m.type === "video")?.duration ?? 0;
  const tiktokDurationOver = tiktokSelected && mediaVideoPost && tiktokMaxDur > 0 && tiktokVideoDuration > tiktokMaxDur;
  const tiktokNeedsPrivacy = tiktokSelected && !c.tiktok.privacy_level;
  const tiktokNeedsBrand = tiktokSelected && !!c.tiktok.disclose_commercial && !c.tiktok.your_brand && !c.tiktok.branded_content;
  const tiktokBrandedPrivate = tiktokSelected && !!c.tiktok.branded_content && c.tiktok.privacy_level === "SELF_ONLY";
  const tiktokBlockReason: string | null = !tiktokSelected ? null
    : tiktokNeedsPrivacy ? "Choose who can view your TikTok post before publishing."
    : tiktokNeedsBrand ? "You need to indicate if your content promotes yourself, a third party, or both."
    : tiktokBrandedPrivate ? "Branded content can't be set to private on TikTok."
    : tiktokDurationOver ? `Your TikTok video is ${Math.round(tiktokVideoDuration)}s — this account allows up to ${tiktokMaxDur}s.`
    : null;

  // Shared cover uploader — one uploaded image drives both the YouTube thumbnail
  // and the Instagram Reel cover, so the same control renders in either tab.
  const coverUploaderBlock = (label: string) => (
    <div style={{ display: "flex", flexDirection: "column", gap: 10 }}>
      <span style={{ fontSize: 12, color: "var(--ink-on-paper-3)", lineHeight: 1.5 }}>Custom cover image for {label}.</span>
      <div style={{ display: "flex", gap: 12, alignItems: "center", flexWrap: "wrap" }}>
        <input ref={coverInput} type="file" accept="image/*" hidden onChange={onPickCover} />
        {c.cover?.url ? (
          <div style={{ position: "relative", width: 96, height: 96, borderRadius: 12, overflow: "hidden", background: `center/cover no-repeat url(${resolveMediaUrl(c.cover.url)})`, boxShadow: "inset 0 0 0 1px rgba(0,0,0,.06)" }}>
            <button onClick={c.removeCover} title="Remove cover" style={{ position: "absolute", top: 4, right: 4, width: 20, height: 20, borderRadius: "50%", border: "none", background: "rgba(10,10,12,.62)", color: "#fff", cursor: "pointer", fontSize: 12, lineHeight: 1, display: "grid", placeItems: "center" }}>✕</button>
          </div>
        ) : (
          <button onClick={() => coverInput.current?.click()} disabled={c.coverUploading} style={{ width: 96, height: 96, borderRadius: 12, border: "1.5px dashed var(--line-2)", background: "var(--paper-1)", cursor: c.coverUploading ? "default" : "pointer", display: "flex", flexDirection: "column", gap: 4, alignItems: "center", justifyContent: "center", color: "var(--ink-on-paper-3)" }}>
            <Icon name="image-plus" size={20} stroke="var(--ink-on-paper-3)" />
            <span style={{ fontSize: 10, fontWeight: 700, fontFamily: "var(--font-body)" }}>{c.coverUploading ? "Uploading…" : "Add cover"}</span>
          </button>
        )}
        {c.cover?.url && (
          <button onClick={() => coverInput.current?.click()} style={{ background: "none", border: "none", color: "var(--vm-volt-deep)", fontWeight: 700, fontSize: 12.5, cursor: "pointer", fontFamily: "var(--font-body)" }}>Replace</button>
        )}
      </div>
      {c.coverError && <span style={{ fontSize: 12, color: "var(--vm-red)" }}>{c.coverError}</span>}
    </div>
  );

  const persist = async (status: "draft" | "scheduled" | "posted") => {
    if (c.selected.length === 0) { toast.error("Pick at least one platform."); return; }
    if (status === "scheduled" && !c.date) { toast.error("Choose a date to schedule."); return; }
    // Over-limit captions are a hard stop for publishing/scheduling — the
    // backend enforces the same rule with a 422, so don't send a doomed post.
    if (status !== "draft" && c.issues.length > 0) {
      toast.error("Fix the over-limit captions before posting — drafts can still be saved.");
      return;
    }
    if (c.mediaUploading) { toast.error("Wait for media to finish uploading."); return; }
    if (c.mediaFailed) { toast.error("Remove or re-upload the failed media."); return; }
    // TikTok accepts a video or a photo slideshow (one or more images).
    if (status !== "draft" && c.selected.includes("tiktok") && !c.media.some((m) => (m.type === "video" || m.type === "image") && m.url)) {
      toast.error("TikTok needs an uploaded video or image."); return;
    }
    // TikTok audit compliance — mirror the composer gating (also enforced with a
    // 422 by the backend) so a non-compliant TikTok post can never be sent.
    if (status !== "draft" && tiktokBlockReason) { toast.error(tiktokBlockReason); return; }
    // YouTube uploads the stored video file directly.
    if (status !== "draft" && c.selected.includes("youtube") && !c.media.some((m) => m.type === "video" && m.url)) {
      toast.error("YouTube needs an uploaded video."); return;
    }
    setSaving(true);
    // The date + time inputs are the user's LOCAL wall-clock. Convert to a UTC
    // ISO string (…Z) so the backend stores UTC and every view renders it back
    // in the viewer's own timezone — otherwise 18:30 local was stored as 18:30
    // UTC and showed as a shifted time (e.g. 1:30am) on the calendar.
    let scheduled_at: string | null = null;
    if (status === "scheduled") {
      const local = new Date(`${c.date}T${c.time || "09:00"}`);
      scheduled_at = Number.isNaN(local.getTime()) ? `${c.date} ${c.time || "09:00"}` : local.toISOString();
    }
    // Per-platform publish options, only for the platforms actually selected.
    const buildOptions = () => {
      const o: Record<string, unknown> = {};
      const mediaPost = c.postType === "media";
      if (c.selected.includes("tiktok")) {
        const tt: Record<string, unknown> = { ...c.tiktok };
        // TikTok takes a cover-frame time (ms into the video), not a custom image.
        if (mediaPost && c.tiktokCoverSec > 0) tt.video_cover_timestamp_ms = Math.round(c.tiktokCoverSec * 1000);
        o.tiktok = tt;
      }
      if (c.selected.includes("youtube")) {
        const yt: Record<string, unknown> = { ...c.youtube };
        if (mediaPost && c.cover?.url) yt.thumbnail = { url: c.cover.url, path: c.cover.path, disk: c.cover.disk, mime: c.cover.mime };
        o.youtube = yt;
      }
      // Instagram Reels take a public cover image URL.
      if (mediaPost && c.selected.includes("instagram") && c.cover?.url) {
        o.instagram = { cover_url: c.cover.url };
      }
      return Object.keys(o).length ? o : undefined;
    };
    const payload = {
      caption: c.caption,
      // A text post never carries media, even if some was uploaded then hidden.
      media: c.postType === "text" ? [] : c.media.map((m) => ({ type: m.type, g: m.g, url: m.url, path: m.path })),
      status,
      scheduled_at,
      // Only while the selection still matches a brand exactly — a tweaked
      // selection is no longer "the brand", so it saves unbranded.
      brand_id: activeBrandId,
      // One target per (platform, account). Platforms without account picks
      // (legacy stores, old drafts) publish via the newest connected account.
      targets: c.selected.flatMap((pid) => {
        const ids = c.accountSel[pid] ?? [];
        return ids.length
          ? ids.map((id) => ({ platform: pid, social_account_id: id }))
          : [{ platform: pid }];
      }),
      comments: c.comments
        .filter((cm) => cm.body.trim() !== "")
        .map((cm) => ({ body: cm.body.trim(), delay_seconds: cm.delaySeconds })),
      shorten_links: c.shortenLinks,
      overrides: c.overrides,
      options: buildOptions(),
    };
    const res = isEditing
      ? await viewsMaxApi.updatePost(editId, payload)
      : await viewsMaxApi.createPost(payload);
    setSaving(false);
    if (res.success) {
      onSaved?.();
      if (status === "draft") {
        toast.success(isEditing ? "Changes saved." : "Draft saved.");
      } else if (status === "posted") {
        // Publishing runs asynchronously on the queue — the post is NOT live
        // yet, so don't claim "Posted". Send the user to History to watch it.
        toast("Processing — we're publishing your post. Track it in History.");
        navigate("/dashboard/post/history");
      } else {
        setDone("schedule");
        toast.success(isEditing ? "Changes saved." : "Scheduled!");
      }
    } else {
      toast.error(res.error || "Couldn't save the post.");
    }
  };

  if (loadingPost) {
    return <div style={{ padding: 48, textAlign: "center", color: "var(--ink-on-paper-3)", fontFamily: "var(--font-body)", fontSize: 14 }}>Loading post…</div>;
  }
  if (loadError) {
    return (
      <div style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 18, padding: 48, textAlign: "center", color: "var(--ink-on-paper-3)", fontFamily: "var(--font-body)", fontSize: 14 }}>
        {loadError}
      </div>
    );
  }

  return (
      <div style={{ width: "100%", maxWidth: 1560, margin: "0 auto" }}>
        <div style={{ display: "grid", gridTemplateColumns: "minmax(0, 1fr) 440px", gap: 24, alignItems: "start" }}>
          {/* ============ EDITOR ============ */}
          <div style={{ minWidth: 0, display: "flex", flexDirection: "column", gap: 16 }}>
            <Card>
              <SectionLabel>Post type</SectionLabel>
              <div style={{ display: "inline-flex", background: "var(--paper-2)", borderRadius: 999, padding: 4, gap: 4 }}>
                {([["media", "Media post", "image"], ["text", "Text post", "type"]] as const).map(([t, l, ic]) => (
                  <button key={t} onClick={() => c.setPostType(t)} style={{ display: "inline-flex", alignItems: "center", gap: 6, border: "none", cursor: "pointer", padding: "8px 16px", borderRadius: 999, fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 13, background: c.postType === t ? "var(--paper-0)" : "transparent", color: c.postType === t ? "var(--ink-on-paper-1)" : "var(--ink-on-paper-3)", boxShadow: c.postType === t ? "0 1px 3px rgba(10,10,12,.12)" : "none" }}>
                    <Icon name={ic} size={14} stroke={c.postType === t ? "var(--ink-on-paper-1)" : "var(--ink-on-paper-3)"} /> {l}
                  </button>
                ))}
              </div>
              <div style={{ marginTop: 10, fontSize: 12, color: "var(--ink-on-paper-3)", lineHeight: 1.5 }}>
                {c.postType === "media"
                  ? "Photo or video. Platforms that only take media are shown."
                  : "Text-only. Only platforms that can post without media are shown."}
              </div>
            </Card>

            <Card>
              <SectionLabel hint={connectedCount ? `${c.selected.length} of ${connectedCount} selected` : undefined}>Publishing to</SectionLabel>
              {connLoading ? (
                <div style={{ fontSize: 12.5, color: "var(--ink-on-paper-3)" }}>Loading your connected accounts…</div>
              ) : (
                <>
                  {brands.length > 0 && (
                    <div style={{ display: "flex", alignItems: "center", gap: 8, flexWrap: "wrap", marginBottom: 14 }}>
                      <span className="vm-eyebrow" style={{ color: "var(--ink-on-paper-3)" }}>Brands</span>
                      {brands.map((b) => (
                        <button key={b.id} onClick={() => applyBrand(b)} title={`Select all of ${b.name}'s accounts`} style={tabStyle(activeBrandId === b.id)}>
                          {b.name}
                        </button>
                      ))}
                    </div>
                  )}
                  <div style={{ display: "flex", alignItems: "center", gap: 10, flexWrap: "wrap" }}>
                    {visiblePlatforms.flatMap((p) => {
                      const accounts = accountsByPlatform[p.id] ?? [];

                      // Multi-account platforms render one chip per connected
                      // account — each pins the exact account it publishes as.
                      if (accounts.length > 0) {
                        return accounts.map((a) => {
                          const broken = a.needsReconnect || a.id == null;
                          const on = !broken && (c.accountSel[p.id] ?? []).includes(a.id!);
                          const label = broken ? "Reconnect" : (a.name || p.name);
                          return (
                            <button
                              key={`${p.id}-${a.id}`}
                              onClick={() => (broken ? navigate("/dashboard/connections") : c.toggleAccount(p.id, a.id!))}
                              title={broken ? `${p.name} authorization expired — reconnect the account` : `${p.name} — ${a.name ?? "connected account"}`}
                              style={{ display: "flex", flexDirection: "column", alignItems: "center", gap: 5, background: "none", border: "none", cursor: "pointer", padding: 2 }}
                            >
                              <PAvatar id={p.id} size={44} ring={on} dim={!broken && !on} badge={on && c.status[p.id].over ? "!" : null} avatarUrl={a.avatarUrl} />
                              <span style={{ fontSize: 10.5, fontWeight: 600, maxWidth: 72, overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap", color: broken ? "var(--vm-red)" : on ? "var(--ink-on-paper-1)" : "var(--ink-on-paper-3)", fontFamily: "var(--font-body)" }}>{label}</span>
                            </button>
                          );
                        });
                      }

                      const needsReconnect = reconnectIds.includes(p.id);
                      const connected = connectedIds.includes(p.id) && !needsReconnect;
                      const on = connected && c.selected.includes(p.id);
                      return [(
                        <button
                          key={p.id}
                          onClick={() => (connected ? c.toggle(p.id) : navigate("/dashboard/connections"))}
                          title={connected ? p.name : needsReconnect ? `${p.name} authorization expired — reconnect the account` : `Connect ${p.name}`}
                          style={{ display: "flex", flexDirection: "column", alignItems: "center", gap: 5, background: "none", border: "none", cursor: "pointer", padding: 2 }}
                        >
                          <PAvatar id={p.id} size={44} ring={on} dim={connected && !on} connect={!connected} badge={on && c.status[p.id].over ? "!" : null} avatarUrl={accountDirectory[p.id]?.avatarUrl} />
                          <span style={{ fontSize: 10.5, fontWeight: 600, maxWidth: 72, overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap", color: !connected ? "var(--vm-red)" : on ? "var(--ink-on-paper-1)" : "var(--ink-on-paper-3)", fontFamily: "var(--font-body)" }}>{connected ? (accountDirectory[p.id]?.name ?? p.name) : needsReconnect ? "Reconnect" : "Connect"}</span>
                        </button>
                      )];
                    })}
                    {connectedCount > 1 && (
                      <button onClick={() => {
                        const sel: string[] = [];
                        const acctSel: Record<string, number[]> = {};
                        for (const p of visiblePlatforms) {
                          const healthy = (accountsByPlatform[p.id] ?? []).filter((a) => !a.needsReconnect && a.id != null);
                          if (healthy.length) {
                            sel.push(p.id);
                            acctSel[p.id] = healthy.map((a) => a.id!);
                          } else if (!(accountsByPlatform[p.id]?.length) && connectedIds.includes(p.id) && !reconnectIds.includes(p.id)) {
                            sel.push(p.id);
                          }
                        }
                        c.setSelected(sel);
                        c.setAccountSel(acctSel);
                      }} style={{ marginLeft: 4, alignSelf: "flex-start", marginTop: 2, background: "var(--paper-2)", border: "1px dashed var(--line-2)", borderRadius: 999, padding: "7px 13px", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 12, color: "var(--ink-on-paper-2)", cursor: "pointer" }}>All</button>
                    )}
                  </div>
                  {connectedCount === 0 && (
                    <div style={{ marginTop: 12, fontSize: 12.5, color: "var(--ink-on-paper-3)", lineHeight: 1.5 }}>
                      Tap a platform to connect it, or{" "}
                      <button onClick={() => navigate("/dashboard/connections")} style={{ background: "none", border: "none", padding: 0, color: "var(--vm-red)", fontWeight: 700, cursor: "pointer", font: "inherit" }}>open the connections screen</button>.
                    </div>
                  )}
                  {linkedInVideoConflict && (
                    <div style={{ marginTop: 12, display: "flex", alignItems: "flex-start", gap: 8, background: "var(--vm-red-tint-l)", border: "1px solid rgba(255,31,61,.35)", borderRadius: 10, padding: "9px 11px", fontSize: 12.5, fontWeight: 600, color: "var(--vm-red-deep)", lineHeight: 1.45 }}>
                      <Icon name="alert-triangle" size={15} stroke="var(--vm-red)" />
                      <span>LinkedIn can't post video — its target will be marked failed. Use an image for LinkedIn, or remove it from this post.</span>
                    </div>
                  )}
                  {xVideoConflict && (
                    <div style={{ marginTop: 12, display: "flex", alignItems: "flex-start", gap: 8, background: "var(--vm-red-tint-l)", border: "1px solid rgba(255,31,61,.35)", borderRadius: 10, padding: "9px 11px", fontSize: 12.5, fontWeight: 600, color: "var(--vm-red-deep)", lineHeight: 1.45 }}>
                      <Icon name="alert-triangle" size={15} stroke="var(--vm-red)" />
                      <span>X can't post video — its target will be marked failed. Use images for X, or remove it from this post.</span>
                    </div>
                  )}
                  {xImageOverflow && (
                    <div style={{ marginTop: 12, display: "flex", alignItems: "flex-start", gap: 8, background: "var(--paper-2)", border: "1px solid var(--line-1)", borderRadius: 10, padding: "9px 11px", fontSize: 12.5, fontWeight: 600, color: "var(--ink-on-paper-2)", lineHeight: 1.45 }}>
                      <Icon name="info" size={15} stroke="var(--ink-on-paper-3)" />
                      <span>X takes up to {X_MAX_IMAGES} images — only the first {X_MAX_IMAGES} will be posted there.</span>
                    </div>
                  )}
                </>
              )}
            </Card>

            {c.postType === "media" && (
            <Card>
              <SectionLabel hint={`${c.media.length} item${c.media.length !== 1 ? "s" : ""}`}>Media</SectionLabel>
              <div style={{ display: "flex", gap: 10, alignItems: "center", flexWrap: "wrap" }}>
                {c.media.map((m, i) => (
                  <MediaTile
                    key={m.id}
                    item={m}
                    idx={i}
                    total={c.media.length}
                    onRemove={() => c.removeMedia(m.id)}
                    onRetry={() => c.retryMedia(m.id)}
                    onDownload={() => downloadMedia(m)}
                    draggable={c.media.length > 1}
                    dragging={dragIdx === i}
                    onDragStart={() => setDragIdx(i)}
                    onDragOver={(e) => e.preventDefault()}
                    onDragEnter={() => { if (dragIdx !== null && dragIdx !== i) { c.reorderMedia(dragIdx, i); setDragIdx(i); } }}
                    onDragEnd={() => setDragIdx(null)}
                  />
                ))}
                {/* One uploader for any content type — images or video. */}
                <input ref={mediaInput} type="file" accept="image/*,video/mp4,video/quicktime" hidden onChange={onPickFile} />
                <button onClick={() => mediaInput.current?.click()} title="Add photo or video" style={{ width: 72, height: 72, borderRadius: 12, border: "1.5px dashed var(--line-2)", background: "var(--paper-1)", cursor: "pointer", display: "flex", flexDirection: "column", gap: 4, alignItems: "center", justifyContent: "center", color: "var(--ink-on-paper-3)" }}>
                  <Icon name="image-plus" size={20} stroke="var(--ink-on-paper-3)" />
                  <span style={{ fontSize: 10, fontWeight: 700, fontFamily: "var(--font-body)" }}>Add media</span>
                </button>
              </div>
              {c.media.length > 1 && (
                <div style={{ marginTop: 8, fontSize: 11.5, color: "var(--ink-on-paper-3)", lineHeight: 1.5 }}>
                  Drag to reorder — the order here is the slideshow order.
                </div>
              )}

              {/* Upload progress — shown directly under the media row. */}
              {uploadingMedia && (
                <div style={{ marginTop: 14 }}>
                  <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", marginBottom: 6 }}>
                    <span style={{ display: "inline-flex", alignItems: "center", gap: 6, fontSize: 12.5, fontWeight: 600, color: "var(--ink-on-paper-2)" }}>
                      <Icon name="rotate-cw" size={13} stroke="var(--ink-on-paper-3)" /> Uploading {uploadingMedia.type}…
                    </span>
                    <span style={{ fontFamily: "var(--font-mono)", fontSize: 12, fontWeight: 700, color: "var(--ink-on-paper-2)" }}>{uploadingMedia.progress ?? 0}%</span>
                  </div>
                  <div style={{ height: 6, borderRadius: 999, background: "var(--paper-2)", overflow: "hidden" }}>
                    <div style={{ height: "100%", width: `${uploadingMedia.progress ?? 0}%`, background: "var(--vm-volt-deep)", borderRadius: 999, transition: "width .2s ease" }} />
                  </div>
                </div>
              )}

              {/* Failed upload — clear, with retry/remove. */}
              {!uploadingMedia && failedMedia.length > 0 && (
                <div style={{ marginTop: 14, display: "flex", alignItems: "center", gap: 10, background: "var(--vm-red-tint-l)", border: "1px solid rgba(255,31,61,.35)", borderRadius: 10, padding: "9px 11px" }}>
                  <Icon name="alert-circle" size={16} stroke="var(--vm-red)" />
                  <span style={{ fontSize: 12.5, fontWeight: 600, color: "var(--vm-red-deep)" }}>
                    {failedMedia.length === 1 ? "A file didn't upload." : `${failedMedia.length} files didn't upload.`} Retry or remove before posting.
                  </span>
                  <button onClick={() => failedMedia.forEach((m) => c.retryMedia(m.id))} style={{ marginLeft: "auto", display: "inline-flex", alignItems: "center", gap: 5, background: "none", border: "none", color: "var(--vm-red)", fontWeight: 700, fontSize: 12.5, cursor: "pointer", fontFamily: "var(--font-body)" }}>
                    <Icon name="rotate-cw" size={13} stroke="var(--vm-red)" /> Retry all
                  </button>
                </div>
              )}
            </Card>
            )}

            <Card>
              <SectionLabel>Caption</SectionLabel>
              <div style={{ display: "flex", gap: 6, marginBottom: 12, flexWrap: "wrap" }}>
                <button onClick={() => setTab("all")} style={tabStyle(tab === "all")}>
                  <Icon name="layers" size={13} stroke={tab === "all" ? "#fff" : "var(--ink-on-paper-3)"} /> All platforms
                </button>
                {c.selected.map((id) => (
                  <button key={id} onClick={() => setTab(id)} style={tabStyle(tab === id)}>
                    <PAvatar id={id} size={16} />
                    {PMAP[id].name}
                    {c.overrides[id] != null && c.overrides[id] !== "" && <span style={{ width: 5, height: 5, borderRadius: "50%", background: tab === id ? "#fff" : "var(--vm-volt-deep)" }} />}
                  </button>
                ))}
              </div>

              {!editingOverride ? (
                <>
                  <MentionTextarea value={c.caption} onChangeText={c.setCaption} mentions={c.selected.includes("x")} accountId={c.accountSel["x"]?.[0]} rows={6} style={{ ...fieldBase, resize: "vertical", lineHeight: 1.55, minHeight: 130 }} placeholder="Write your caption…" />
                  <div style={{ display: "flex", alignItems: "center", gap: 7, marginTop: 12, flexWrap: "wrap" }}>
                    {c.selected.map((id) => <CountChip key={id} id={id} status={c.status} />)}
                  </div>
                  {captionHasDelimiter && c.selected.some((id) => id !== "x") && (
                    <div style={{ marginTop: 10, fontSize: 11.5, color: "var(--ink-on-paper-3)", lineHeight: 1.5 }}>
                      <code style={{ fontFamily: "var(--font-mono)", background: "var(--paper-2)", padding: "1px 5px", borderRadius: 4 }}>---</code> starts a new tweet on X only — other platforms post it as plain text.
                    </div>
                  )}
                </>
              ) : tab === "x" ? (
                <>
                  <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", marginBottom: 8 }}>
                    <span style={{ fontSize: 12.5, color: "var(--ink-on-paper-2)", fontWeight: 600 }}>Tweets for X</span>
                    {c.overrides.x != null && c.overrides.x !== "" && (
                      <button onClick={() => { c.clearOverride("x"); setXActive(0); }} style={{ background: "none", border: "none", color: "var(--vm-red)", fontWeight: 700, fontSize: 12, cursor: "pointer", fontFamily: "var(--font-body)" }}>Reset to main</button>
                    )}
                  </div>
                  <XTweetEditor c={c} active={xActive} onSelect={setXActive} />
                </>
              ) : (
                <>
                  <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", marginBottom: 8 }}>
                    <span style={{ fontSize: 12.5, color: "var(--ink-on-paper-2)", fontWeight: 600 }}>Custom text for {PMAP[tab].name}</span>
                    {c.overrides[tab] != null && c.overrides[tab] !== "" && (
                      <button onClick={() => c.clearOverride(tab)} style={{ background: "none", border: "none", color: "var(--vm-red)", fontWeight: 700, fontSize: 12, cursor: "pointer", fontFamily: "var(--font-body)" }}>Reset to main</button>
                    )}
                  </div>
                  <textarea value={overrideVal} onChange={(e) => c.setOverride(tab, e.target.value)} rows={6} style={{ ...fieldBase, resize: "vertical", lineHeight: 1.55, minHeight: 130 }} placeholder="Leave blank to use the main caption…" />
                  <div style={{ marginTop: 12 }}><CountChip id={tab} status={c.status} /></div>
                </>
              )}
              {showXThread && xThread && (
                <XThreadPanel
                  thread={xThread}
                  activeIdx={tab === "x" ? Math.min(xActive, xSegCount - 1) : null}
                  onSelect={openXTweet}
                  onAdd={addXTweet}
                  onAutoSplit={() => { c.setOverride("x", autoSplitX(c.textFor("x"))); setXActive(0); }}
                />
              )}
              <CommentsCard comments={c.comments} setComments={c.setComments} selected={c.selected} xAccountId={c.accountSel["x"]?.[0]} />
            </Card>

            {optionTabs.length > 0 && (
              <Card>
                <SectionLabel>Platform options</SectionLabel>
                <div style={{ display: "flex", gap: 6, marginBottom: 14, flexWrap: "wrap" }}>
                  {optionTabs.map((id) => (
                    <button key={id} onClick={() => setOptTab(id)} style={tabStyle(activeOptTab === id)}>
                      <PAvatar id={id} size={16} /> {PMAP[id].name}
                    </button>
                  ))}
                </div>

                {activeOptTab === "tiktok" && (
                  c.creatorInfoLoading ? (
                    <div style={{ fontSize: 12.5, color: "var(--ink-on-paper-3)" }}>Loading TikTok options…</div>
                  ) : c.creatorInfoError ? (
                    <div style={{ display: "flex", alignItems: "center", gap: 8, fontSize: 12.5, color: "var(--vm-red)" }}>
                      <Icon name="alert-circle" size={15} stroke="var(--vm-red)" /> {c.creatorInfoError}
                      <button onClick={() => c.loadCreatorInfo()} style={{ background: "none", border: "none", color: "var(--vm-red)", fontWeight: 700, cursor: "pointer", textDecoration: "underline" }}>Retry</button>
                    </div>
                  ) : (
                    <div style={{ display: "flex", flexDirection: "column", gap: 14 }}>
                      {/* Which TikTok account this uploads to (creator nickname). */}
                      {(c.creatorInfo?.creator_nickname || c.creatorInfo?.creator_username) && (
                        <div style={{ display: "flex", alignItems: "center", gap: 8, fontSize: 12.5, color: "var(--ink-on-paper-2)" }}>
                          {c.creatorInfo?.creator_avatar_url && (
                            <img src={c.creatorInfo.creator_avatar_url} alt="" width={22} height={22} style={{ borderRadius: "50%", flexShrink: 0 }} />
                          )}
                          <span>Uploading to <strong>{c.creatorInfo?.creator_nickname || `@${c.creatorInfo?.creator_username}`}</strong></span>
                        </div>
                      )}

                      {/* Privacy — no default; the user must choose. "Only me" is
                          disabled while Branded content is on. */}
                      <label style={{ display: "flex", flexDirection: "column", gap: 6 }}>
                        <span style={{ fontSize: 11.5, fontWeight: 700, color: "var(--ink-on-paper-3)" }}>Who can view this</span>
                        <select
                          value={c.tiktok.privacy_level}
                          onChange={(e) => c.setPrivacyLevel(e.target.value)}
                          style={{ ...fieldBase, width: 260, color: c.tiktok.privacy_level ? "var(--ink-on-paper-1)" : "var(--ink-on-paper-3)" }}
                        >
                          <option value="" disabled>Select who can view…</option>
                          {(c.creatorInfo?.privacy_level_options ?? []).map((opt) => (
                            <option
                              key={opt}
                              value={opt}
                              disabled={opt === "SELF_ONLY" && !!c.tiktok.branded_content}
                              title={opt === "SELF_ONLY" && c.tiktok.branded_content ? "Branded content visibility cannot be set to private." : undefined}
                            >{PRIVACY_LABELS[opt] ?? opt}</option>
                          ))}
                        </select>
                        {c.tiktok.branded_content && (
                          <span style={{ fontSize: 11, color: "var(--ink-on-paper-3)", lineHeight: 1.5 }}>Branded content visibility cannot be set to private.</span>
                        )}
                      </label>

                      {/* Interactions — none enabled by default. Duet/Stitch don't
                          apply to photo posts, so they only show for video. */}
                      <div style={{ display: "flex", gap: 18, flexWrap: "wrap" }}>
                        <Toggle label="Allow comments" checked={!c.tiktok.disable_comment} disabled={c.creatorInfo?.comment_disabled} onChange={(v) => c.patchTiktok({ disable_comment: !v })} />
                        {mediaVideoPost && <Toggle label="Allow Duet" checked={!c.tiktok.disable_duet} disabled={c.creatorInfo?.duet_disabled} onChange={(v) => c.patchTiktok({ disable_duet: !v })} />}
                        {mediaVideoPost && <Toggle label="Allow Stitch" checked={!c.tiktok.disable_stitch} disabled={c.creatorInfo?.stitch_disabled} onChange={(v) => c.patchTiktok({ disable_stitch: !v })} />}
                      </div>

                      <div style={{ display: "flex", flexDirection: "column", gap: 6 }}>
                        <Toggle label="Auto-add music" checked={!!c.tiktok.auto_add_music} onChange={(v) => c.patchTiktok({ auto_add_music: v })} />
                        <span style={{ fontSize: 11, color: "var(--ink-on-paper-3)", lineHeight: 1.5 }}>
                          TikTok adds its recommended background music to photo / slideshow posts. Slideshows only — no effect on video.
                        </span>
                      </div>

                      {/* Commercial content disclosure — off by default; when on,
                          at least one of the two must be chosen. */}
                      <div style={{ borderTop: "1px solid var(--line-1)", paddingTop: 12, display: "flex", flexDirection: "column", gap: 10 }}>
                        <Toggle label="Disclose that this content promotes yourself, a brand, product or service" checked={!!c.tiktok.disclose_commercial} onChange={(v) => c.patchTiktok({ disclose_commercial: v, your_brand: v ? c.tiktok.your_brand : false, branded_content: v ? c.tiktok.branded_content : false })} />
                        {c.tiktok.disclose_commercial && (
                          <div style={{ display: "flex", flexDirection: "column", gap: 8, paddingLeft: 6 }}>
                            <div style={{ display: "flex", gap: 18, flexWrap: "wrap" }}>
                              <Toggle label="Your brand" checked={!!c.tiktok.your_brand} onChange={(v) => c.patchTiktok({ your_brand: v })} />
                              <Toggle label="Branded content" checked={!!c.tiktok.branded_content} onChange={(v) => c.patchTiktok({ branded_content: v, ...(v && c.tiktok.privacy_level === "SELF_ONLY" ? { privacy_level: "" } : {}) })} />
                            </div>
                            {(c.tiktok.your_brand || c.tiktok.branded_content) && (
                              <span style={{ fontSize: 11.5, fontWeight: 600, color: "var(--ink-on-paper-2)", lineHeight: 1.5 }}>
                                Your photo/video will be labeled as ‘{c.tiktok.branded_content ? "Paid partnership" : "Promotional content"}’.
                              </span>
                            )}
                            {tiktokNeedsBrand && (
                              <span style={{ fontSize: 11.5, fontWeight: 600, color: "var(--vm-red)", lineHeight: 1.5 }}>
                                You need to indicate if your content promotes yourself, a third party, or both.
                              </span>
                            )}
                          </div>
                        )}
                      </div>

                      {tiktokDurationOver && (
                        <div style={{ display: "flex", alignItems: "flex-start", gap: 8, background: "var(--vm-red-tint-l)", border: "1px solid rgba(255,31,61,.35)", borderRadius: 10, padding: "9px 11px", fontSize: 12, fontWeight: 600, color: "var(--vm-red-deep)", lineHeight: 1.45 }}>
                          <Icon name="alert-triangle" size={15} stroke="var(--vm-red)" />
                          <span>This video is {Math.round(tiktokVideoDuration)}s — TikTok allows up to {tiktokMaxDur}s on this account. Trim it before posting.</span>
                        </div>
                      )}

                      {mediaVideoPost && (
                        <label style={{ display: "flex", flexDirection: "column", gap: 6, borderTop: "1px solid var(--line-1)", paddingTop: 12 }}>
                          <span style={{ fontSize: 11.5, fontWeight: 700, color: "var(--ink-on-paper-3)" }}>Cover frame (seconds into the video)</span>
                          <input type="number" min={0} step={0.5} value={c.tiktokCoverSec} onChange={(e) => c.setTiktokCoverSec(Math.max(0, Number(e.target.value) || 0))} style={{ ...fieldBase, width: 160, fontFamily: "var(--font-mono)", fontSize: 13 }} />
                          <span style={{ fontSize: 11, color: "var(--ink-on-paper-3)", lineHeight: 1.5 }}>TikTok only supports picking a frame time as the cover, not a custom image.</span>
                        </label>
                      )}
                    </div>
                  )
                )}

                {activeOptTab === "youtube" && (
                  <div style={{ display: "flex", flexDirection: "column", gap: 14 }}>
                    <label style={{ display: "flex", flexDirection: "column", gap: 6 }}>
                      <span style={{ fontSize: 11.5, fontWeight: 700, color: "var(--ink-on-paper-3)" }}>Visibility</span>
                      <select
                        value={c.youtube.privacy_status}
                        onChange={(e) => c.patchYoutube({ privacy_status: e.target.value as "public" | "unlisted" | "private" })}
                        style={{ ...fieldBase, width: 260 }}
                      >
                        <option value="public">Public</option>
                        <option value="unlisted">Unlisted (anyone with the link)</option>
                        <option value="private">Private (only you)</option>
                      </select>
                    </label>
                    <span style={{ fontSize: 11, color: "var(--ink-on-paper-3)", lineHeight: 1.5 }}>
                      The caption becomes the video title (first 95 chars) and description.
                    </span>
                    {mediaVideoPost && coverUploaderBlock("YouTube")}
                  </div>
                )}

                {activeOptTab === "instagram" && (
                  <div style={{ display: "flex", flexDirection: "column", gap: 14 }}>
                    {coverUploaderBlock("Instagram")}
                  </div>
                )}

              </Card>
            )}

            <Card>
              <SectionLabel>Links</SectionLabel>
              <Toggle
                label="Shorten & track links"
                checked={c.shortenLinks}
                onChange={c.setShortenLinks}
              />
              <div style={{ marginTop: 8, fontSize: 11.5, color: "var(--ink-on-paper-3)", lineHeight: 1.5 }}>
                URLs in your caption and comments are replaced with short ViewsMax links when the post is saved, so every click gets counted.
              </div>
            </Card>

            <Card>
              <SectionLabel>When to publish</SectionLabel>
              <div style={{ display: "inline-flex", background: "var(--paper-2)", borderRadius: 999, padding: 4, gap: 4, marginBottom: c.mode === "schedule" ? 14 : 0 }}>
                {([["now", "Post now"], ["schedule", "Schedule"]] as const).map(([m, l]) => (
                  <button key={m} onClick={() => c.setMode(m)} style={{ border: "none", cursor: "pointer", padding: "8px 18px", borderRadius: 999, fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 13, background: c.mode === m ? "var(--paper-0)" : "transparent", color: c.mode === m ? "var(--ink-on-paper-1)" : "var(--ink-on-paper-3)", boxShadow: c.mode === m ? "0 1px 3px rgba(10,10,12,.12)" : "none" }}>{l}</button>
                ))}
              </div>
              {c.mode === "schedule" && (
                <div style={{ display: "flex", gap: 12, alignItems: "flex-end", flexWrap: "wrap" }}>
                  <label style={{ display: "flex", flexDirection: "column", gap: 6 }}>
                    <span style={{ fontSize: 11.5, fontWeight: 700, color: "var(--ink-on-paper-3)" }}>Date</span>
                    <input type="date" value={c.date} onChange={(e) => c.setDate(e.target.value)} style={{ ...fieldBase, width: 170, fontFamily: "var(--font-mono)", fontSize: 13 }} />
                  </label>
                  <label style={{ display: "flex", flexDirection: "column", gap: 6 }}>
                    <span style={{ fontSize: 11.5, fontWeight: 700, color: "var(--ink-on-paper-3)" }}>Time</span>
                    <input type="time" value={c.time} onChange={(e) => c.setTime(e.target.value)} style={{ ...fieldBase, width: 130, fontFamily: "var(--font-mono)", fontSize: 13 }} />
                  </label>
                  <span style={{ display: "inline-flex", alignItems: "center", gap: 6, padding: "8px 12px", background: "var(--vm-volt-tint-l)", borderRadius: 10, fontSize: 12, fontWeight: 600, color: "var(--vm-volt-deep)" }}>
                    <Icon name="trending-up" size={14} stroke="var(--vm-volt-deep)" /> Peak audience 6–8pm
                  </span>
                </div>
              )}
            </Card>
          </div>

          {/* ============ PREVIEW ============ */}
          <div style={{ minWidth: 0, position: "sticky", top: 18, display: "flex", flexDirection: "column", gap: 14 }}>
            <div style={{ display: "flex", alignItems: "center", gap: 8, flexWrap: "wrap", paddingBottom: 2 }}>
              {c.selected.map((id) => (
                <button key={id} onClick={() => setPreviewId(id)} style={{ display: "flex", alignItems: "center", gap: 7, flexShrink: 0, background: previewId === id ? "var(--ink-on-paper-1)" : "var(--paper-0)", color: previewId === id ? "#fff" : "var(--ink-on-paper-2)", border: "1px solid " + (previewId === id ? "var(--ink-on-paper-1)" : "var(--line-1)"), borderRadius: 999, padding: "6px 12px 6px 6px", cursor: "pointer", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 12.5 }}>
                  <PAvatar id={id} size={20} /> {PMAP[id].name}
                </button>
              ))}
            </div>

            {/* phone */}
            <div style={{ background: "var(--ink-1000)", borderRadius: 34, padding: 11, boxShadow: "var(--hard)", border: "1px solid var(--ink-700)" }}>
              <div style={{ background: "var(--paper-1)", borderRadius: 25, overflow: "hidden", position: "relative", minHeight: 460 }}>
                <div style={{ height: 32, display: "flex", alignItems: "center", justifyContent: "space-between", padding: "0 18px", fontFamily: "var(--font-mono)", fontSize: 11, fontWeight: 700, color: "var(--ink-on-paper-1)" }}>
                  <span>9:41</span>
                  <span style={{ position: "absolute", left: "50%", transform: "translateX(-50%)", top: 8, width: 90, height: 18, background: "var(--ink-1000)", borderRadius: 999 }} />
                  <span style={{ display: "flex", gap: 4 }}><Icon name="signal" size={13} stroke="var(--ink-on-paper-1)" /><Icon name="battery-full" size={15} stroke="var(--ink-on-paper-1)" /></span>
                </div>
                <div style={{ padding: 12 }}>
                  {c.selected.includes(previewId) && c.status[previewId].over && (
                    <div style={{ display: "flex", alignItems: "center", gap: 8, background: "var(--vm-red-tint-l)", border: "1px solid rgba(255,31,61,.35)", color: "var(--vm-red-deep)", borderRadius: 12, padding: "9px 11px", marginBottom: 10, fontSize: 12, fontWeight: 600 }}>
                      <Icon name="alert-triangle" size={15} stroke="var(--vm-red)" />
                      {c.status[previewId].len > PMAP[previewId].limit
                        ? `Caption is ${c.status[previewId].len - PMAP[previewId].limit} over ${PMAP[previewId].name}'s ${PMAP[previewId].limit} limit`
                        : "Fix the X thread — a tweet is empty or the thread is too long"}
                    </div>
                  )}
                  <PostPreview id={previewId} text={c.textFor(previewId)} media={c.media} />
                </div>
              </div>
            </div>

            {/* action footer */}
            <Card style={{ padding: 16 }}>
              {done ? (
                <div style={{ display: "flex", alignItems: "center", gap: 10, color: "var(--up)", fontWeight: 700, fontSize: 14, justifyContent: "center", padding: 4 }}>
                  <Icon name="check-circle-2" size={20} stroke="var(--up)" /> {isEditing ? "Changes saved" : done === "now" ? "Posted to" : "Scheduled for"} {isEditing ? "" : `${c.selected.length} platform${c.selected.length === 1 ? "" : "s"}`}
                </div>
              ) : (
                <>
                  {uploadingMedia ? (
                    <div style={{ display: "flex", alignItems: "center", gap: 7, marginBottom: 12, fontSize: 12.5, fontWeight: 600, color: "var(--ink-on-paper-2)" }}>
                      <Icon name="rotate-cw" size={15} stroke="var(--ink-on-paper-3)" />
                      Uploading {uploadingMedia.type}… {uploadingMedia.progress ?? 0}%
                    </div>
                  ) : failedMedia.length > 0 ? (
                    <div style={{ display: "flex", alignItems: "center", gap: 7, marginBottom: 12, fontSize: 12.5, fontWeight: 600, color: "var(--vm-red)" }}>
                      <Icon name="alert-circle" size={15} stroke="var(--vm-red)" />
                      Media upload failed — retry or remove it above before posting
                    </div>
                  ) : c.issues.length > 0 && (
                    <div style={{ display: "flex", alignItems: "center", gap: 7, marginBottom: 12, fontSize: 12.5, fontWeight: 600, color: "var(--vm-red)" }}>
                      <Icon name="alert-circle" size={15} stroke="var(--vm-red)" />
                      {c.issues.length} platform{c.issues.length > 1 ? "s" : ""} need{c.issues.length > 1 ? "" : "s"} attention
                    </div>
                  )}
                  {/* TikTok requires this declaration immediately before the
                      publish button; the wording changes for branded content. */}
                  {tiktokSelected && (
                    <div style={{ marginBottom: 12, fontSize: 11.5, color: "var(--ink-on-paper-3)", lineHeight: 1.5 }}>
                      By posting, you agree to TikTok's {c.tiktok.branded_content ? "Branded Content Policy and " : ""}Music Usage Confirmation.
                    </div>
                  )}
                  {tiktokBlockReason && (
                    <div style={{ display: "flex", alignItems: "center", gap: 7, marginBottom: 12, fontSize: 12.5, fontWeight: 600, color: "var(--vm-red)" }}>
                      <Icon name="alert-circle" size={15} stroke="var(--vm-red)" /> {tiktokBlockReason}
                    </div>
                  )}
                  <div style={{ display: "flex", gap: 10 }}>
                    <Btn kind="ghost" onClick={saving ? undefined : () => persist("draft")}>Save draft</Btn>
                    <div title={tiktokBlockReason ?? undefined} style={{ flex: 1, opacity: uploadingMedia || failedMedia.length > 0 || c.issues.length > 0 || tiktokBlockReason ? 0.55 : 1 }}>
                      <Btn kind={c.issues.length || uploadingMedia || failedMedia.length || tiktokBlockReason ? "dark" : "primary"} full icon={uploadingMedia ? "rotate-cw" : c.mode === "now" ? "send" : "calendar-clock"} onClick={saving || tiktokBlockReason ? undefined : () => persist(c.mode === "now" ? "posted" : "scheduled")}>
                        {saving ? "Saving…" : uploadingMedia ? `Uploading… ${uploadingMedia.progress ?? 0}%` : c.mode === "now" ? "Post now" : isEditing ? "Save changes" : "Schedule post"}
                      </Btn>
                    </div>
                  </div>
                </>
              )}
            </Card>
          </div>
        </div>
      </div>
  );
}
