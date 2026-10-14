// Create Post — Direction A "Split Studio": editor on the left, live phone
// preview on the right. Ported from the ViewsMax design kit; wired to the
// posts backend (createPost).
import { useEffect, useMemo, useRef, useState, type CSSProperties } from "react";
import { useNavigate } from "react-router-dom";
import { toast } from "sonner";
import { Trans, useTranslation } from "react-i18next";
import type { TFunction } from "i18next";
import { Icon, Btn } from "@/components/analytics/primitives";
import { PLATFORMS, PMAP, PAvatar, MediaTile, PostPreview, CountChip, usePostComposer, platformAllowedForType, type Composer } from "@/components/post/composer";
import { viewsMaxApi, resolveMediaUrl, type Brand, type PinterestBoard } from "@/lib/api-service";
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

// Content Sharing Guidelines, Required UX 2 NOTE and 4: the declarations name two
// policies, and both must be reachable rather than plain text.
// https://developers.tiktok.com/doc/content-sharing-guidelines/
const policyLink: React.CSSProperties = { color: "inherit", textDecoration: "underline" };

// Keys are TikTok's privacy_level values (sent to the API); only labels are translated.
const privacyLabels = (t: TFunction): Record<string, string> => ({
  PUBLIC_TO_EVERYONE: t("post.composer.privacyEveryone", "Everyone"),
  MUTUAL_FOLLOW_FRIENDS: t("post.composer.privacyFriends", "Friends"),
  FOLLOWER_OF_CREATOR: t("post.composer.privacyFollowers", "Followers"),
  SELF_ONLY: t("post.composer.privacySelfOnly", "Only me (private)"),
});

// Platforms surfaced in the "Publishing to" picker — the ones the connections
// screen can actually connect today. Order follows the PLATFORMS catalog.
const SUPPORTED_PLATFORM_IDS = ["youtube", "tiktok", "instagram", "x", "linkedin", "facebook", "threads", "bluesky"];
const SHOWN_WHEN_CONNECTED_IDS = ["pinterest"];

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
// previous message in the chain (Postiz-style presets + custom minutes).
const youtubeTitleMsg = (t: TFunction) =>
  t("post.composer.youtubeTitleMissing", "YouTube needs a title — add a caption, it's used as the video title.");

const COMMENT_PLATFORMS = ["x", "linkedin", "threads", "instagram"];
const DELAY_SECONDS = [0, 60, 120, 300, 600, 900, 1800, 3600, 7200];
const delayPresets = (t: TFunction): Array<{ label: string; seconds: number }> => [
  { label: t("post.composer.delayImmediately", "Immediately"), seconds: 0 },
  { label: t("post.composer.delay1m", "1m"), seconds: 60 },
  { label: t("post.composer.delay2m", "2m"), seconds: 120 },
  { label: t("post.composer.delay5m", "5m"), seconds: 300 },
  { label: t("post.composer.delay10m", "10m"), seconds: 600 },
  { label: t("post.composer.delay15m", "15m"), seconds: 900 },
  { label: t("post.composer.delay30m", "30m"), seconds: 1800 },
  { label: t("post.composer.delay1h", "1h"), seconds: 3600 },
  { label: t("post.composer.delay2h", "2h"), seconds: 7200 },
];

type ComposerComment = { body: string; delaySeconds: number };

function CommentsCard({ comments, setComments, selected, xAccountId }: {
  comments: ComposerComment[];
  setComments: React.Dispatch<React.SetStateAction<ComposerComment[]>>;
  selected: string[];
  /** Connected X account backing the @mention typeahead (comments post as X replies). */
  xAccountId?: number | null;
}) {
  const { t } = useTranslation();
  const supported = selected.filter((id) => COMMENT_PLATFORMS.includes(id));
  const unsupported = selected.filter((id) => !COMMENT_PLATFORMS.includes(id));
  const patch = (i: number, p: Partial<ComposerComment>) =>
    setComments((list) => list.map((cm, idx) => (idx === i ? { ...cm, ...p } : cm)));
  const remove = (i: number) => setComments((list) => list.filter((_, idx) => idx !== i));

  return (
    <div style={{ borderTop: "1px solid var(--line-1)", marginTop: 18, paddingTop: 16 }}>
      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 10, marginBottom: 10 }}>
        <span className="vm-eyebrow" style={{ color: "var(--ink-on-paper-3)" }}>
          {t("post.composer.comments", "Comments")}{comments.length ? ` · ${comments.length}` : ""}
        </span>
        <button
          onClick={() => setComments((list) => [...list, { body: "", delaySeconds: 0 }])}
          style={{ background: "var(--vm-red)", color: "#fff", border: "none", borderRadius: 999, padding: "7px 13px", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 12.5, cursor: "pointer", flexShrink: 0 }}
        >
          {t("post.composer.addComment", "+ Add comment")}
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
                  placeholder={i === 0 ? t("post.composer.commentPlaceholderFirst", "e.g. Register to my newsletter → link") : t("post.composer.commentPlaceholderNext", "Another follow-up…")}
                  style={{ width: "100%", border: "1px solid var(--line-1)", borderRadius: 10, padding: "9px 11px", fontFamily: "var(--font-body)", fontSize: 13.5, lineHeight: 1.55, resize: "vertical", background: "var(--paper-0)", color: "var(--ink-on-paper-1)" }}
                />
              </div>
              <button onClick={() => remove(i)} title={t("post.composer.removeComment", "Remove comment")} style={{ width: 30, height: 30, borderRadius: 999, border: "1px solid var(--line-1)", background: "var(--paper-0)", cursor: "pointer", display: "grid", placeItems: "center", flexShrink: 0 }}>
                <Icon name="trash-2" size={13} stroke="var(--ink-on-paper-3)" />
              </button>
            </div>
            <div style={{ display: "flex", alignItems: "center", gap: 8, marginTop: 8, flexWrap: "wrap" }}>
              <span style={{ fontSize: 11, fontWeight: 700, color: "var(--ink-on-paper-3)" }}>{t("post.composer.delay", "Delay")}</span>
              <select
                value={DELAY_SECONDS.includes(cm.delaySeconds) ? String(cm.delaySeconds) : "custom"}
                onChange={(e) => {
                  // "Custom…" flips to a non-preset value so the minutes input appears.
                  patch(i, { delaySeconds: e.target.value === "custom" ? 180 : Number(e.target.value) });
                }}
                style={{ border: "1px solid var(--line-1)", borderRadius: 8, padding: "5px 8px", fontFamily: "var(--font-body)", fontSize: 12, background: "var(--paper-0)", color: "var(--ink-on-paper-1)" }}
              >
                {delayPresets(t).map((p) => <option key={p.seconds} value={p.seconds}>{p.label}</option>)}
                <option value="custom">{t("post.composer.delayCustom", "Custom…")}</option>
              </select>
              {!DELAY_SECONDS.includes(cm.delaySeconds) ? (
                <label style={{ display: "inline-flex", alignItems: "center", gap: 5, fontSize: 12, color: "var(--ink-on-paper-2)" }}>
                  <input
                    type="number"
                    min={0}
                    max={120}
                    value={Math.round(cm.delaySeconds / 60)}
                    onChange={(e) => patch(i, { delaySeconds: Math.max(0, Math.min(120, Number(e.target.value) || 0)) * 60 })}
                    style={{ width: 60, border: "1px solid var(--line-1)", borderRadius: 8, padding: "5px 8px", fontFamily: "var(--font-mono)", fontSize: 12, background: "var(--paper-0)", color: "var(--ink-on-paper-1)" }}
                  /> {t("post.composer.minutesShort", "min")}
                </label>
              ) : null}
              {i > 0 && <span style={{ fontSize: 11, color: "var(--ink-on-paper-3)" }}>{t("post.composer.afterPreviousComment", "after the previous comment")}</span>}
              {selected.includes("x") && (
                <span style={{ marginLeft: "auto", fontFamily: "var(--font-mono)", fontSize: 11, color: xOver ? "var(--vm-red)" : "var(--ink-on-paper-3)" }}>
                  {t("post.composer.leftOnX", "{{n}} left on X", { n: xRemaining(cm.body) })}
                </span>
              )}
            </div>
          </div>
        );
      })}
      {comments.length > 0 && (
        <div style={{ marginTop: 10, fontSize: 11.5, color: "var(--ink-on-paper-3)", lineHeight: 1.5 }}>
          {supported.length > 0 && <>{t("post.composer.commentsWillPostTo", "Will post to: {{platforms}}.", { platforms: supported.map((id) => PMAP[id]?.name ?? id).join(", ") })}</>}
          {unsupported.length > 0 && <> {t("post.composer.commentsUnsupported", { count: unsupported.length, platforms: unsupported.map((id) => PMAP[id]?.name ?? id).join(", "), defaultValue_one: "{{platforms}} doesn't support comments and will skip them.", defaultValue_other: "{{platforms}} don't support comments and will skip them." })}</>}
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
  const { t } = useTranslation();
  const anyOver = thread.segments.some((s) => s.over) || thread.tooMany;
  const count = Math.max(1, thread.segments.length);
  const segments = thread.segments.length ? thread.segments : [{ text: "", len: 0, over: false, empty: true }];
  return (
    <div style={{ marginTop: 12, display: "flex", flexDirection: "column", gap: 8 }}>
      <div style={{ display: "flex", alignItems: "center", gap: 7, flexWrap: "wrap" }}>
        <span style={{ display: "inline-flex", alignItems: "center", gap: 6, fontFamily: "var(--font-mono)", fontSize: 11, fontWeight: 700, color: "var(--ink-on-paper-3)", textTransform: "uppercase", letterSpacing: ".04em" }}>
          <PAvatar id="x" size={16} /> {count <= 1 ? t("post.composer.xPost", "X post") : t("post.composer.xThreadCount", { count, defaultValue_one: "X thread · {{count}} post", defaultValue_other: "X thread · {{count}} posts" })}
        </span>
        {segments.map((s, i) => {
          const active = i === activeIdx;
          const bad = s.over || (s.empty && count > 1);
          return (
            <button key={i} onClick={() => onSelect(i)} title={t("post.composer.editTweet", "Edit tweet {{n}}", { n: i + 1 })}
              style={{
                display: "inline-flex", alignItems: "center", gap: 5, cursor: "pointer",
                background: bad ? "var(--vm-red-tint-l)" : active ? "var(--ink-on-paper-1)" : "var(--paper-2)",
                border: bad ? "1px solid rgba(255,31,61,.4)" : "1px solid transparent",
                borderRadius: 999, padding: "3px 9px", fontFamily: "var(--font-mono)", fontSize: 11, fontWeight: 700,
                color: bad ? "var(--vm-red)" : active ? "#fff" : "var(--ink-on-paper-3)",
              }}>
              {i + 1} · {s.empty ? t("post.composer.tweetEmpty", "empty") : s.over ? `−${s.len - X_LIMIT}` : X_LIMIT - s.len}
            </button>
          );
        })}
        <button onClick={onAdd} title={t("post.composer.addTweetToThread", "Add a tweet to the thread")}
          style={{ display: "inline-flex", alignItems: "center", cursor: "pointer", background: "var(--paper-0)", border: "1px dashed var(--line-2)", borderRadius: 999, padding: "3px 10px", fontFamily: "var(--font-mono)", fontSize: 11, fontWeight: 700, color: "var(--ink-on-paper-2)" }}>
          +
        </button>
        {anyOver && (
          <button onClick={onAutoSplit} style={{ display: "inline-flex", alignItems: "center", gap: 5, background: "var(--ink-on-paper-1)", color: "#fff", border: "none", borderRadius: 999, padding: "5px 12px", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 12, cursor: "pointer" }}>
            <Icon name="zap" size={12} stroke="#fff" /> {t("post.composer.autoSplit", "Auto-split into a thread")}
          </button>
        )}
      </div>
      {thread.tooMany && (
        <span style={{ fontSize: 12, fontWeight: 600, color: "var(--vm-red)" }}>{t("post.composer.threadLimit", "Threads are limited to 25 tweets.")}</span>
      )}
    </div>
  );
}

// Per-tweet editor shown on the X caption tab: a chain of clickable line
// segments (one per tweet), then ONE textarea editing the selected tweet.
// Users never see or type the raw `---` separator here — the serialization
// into the X override happens in the composer hook.
function XTweetEditor({ c, active, onSelect }: { c: Composer; active: number; onSelect: (i: number) => void }) {
  const { t } = useTranslation();
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
          <button key={i} onClick={() => onSelect(i)} title={s.over ? t("post.composer.tweetNOverLimit", "Tweet {{n}} — over limit", { n: i + 1 }) : t("post.composer.tweetN", "Tweet {{n}}", { n: i + 1 })}
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
        placeholder={idx === 0 ? t("post.composer.firstTweetPlaceholder", "Write the first tweet…") : t("post.composer.tweetPlaceholder", "Write this tweet…")}
      />
      <div style={{ display: "flex", alignItems: "center", gap: 10, marginTop: 10, flexWrap: "wrap" }}>
        <span style={{ fontFamily: "var(--font-mono)", fontSize: 11.5, fontWeight: 700, color: "var(--ink-on-paper-2)" }}>
          {t("post.composer.tweetNOfTotal", "Tweet {{n}} of {{total}}", { n: idx + 1, total: segments.length })}
        </span>
        <span style={{ fontFamily: "var(--font-mono)", fontSize: 11.5, fontWeight: 700, color: current.over ? "var(--vm-red)" : remaining < X_LIMIT * 0.15 ? "var(--warn)" : "var(--ink-on-paper-3)" }}>
          {current.over ? `−${current.len - X_LIMIT}` : remaining}
        </span>
        <button onClick={() => { c.addXSegment(idx); onSelect(idx + 1); }}
          style={{ display: "inline-flex", alignItems: "center", gap: 5, background: "var(--paper-2)", border: "1px solid var(--line-1)", borderRadius: 999, padding: "5px 12px", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 12, color: "var(--ink-on-paper-1)", cursor: "pointer" }}>
          <Icon name="plus" size={12} stroke="var(--ink-on-paper-1)" /> {t("post.composer.addTweet", "Add tweet")}
        </button>
        {segments.length > 1 && (
          <button onClick={() => { c.removeXSegment(idx); onSelect(Math.min(idx, segments.length - 2)); }}
            style={{ display: "inline-flex", alignItems: "center", gap: 5, background: "none", border: "none", color: "var(--vm-red)", fontWeight: 700, fontSize: 12, cursor: "pointer", fontFamily: "var(--font-body)" }}>
            <Icon name="trash-2" size={12} stroke="var(--vm-red)" /> {t("post.composer.deleteTweet", "Delete tweet")}
          </button>
        )}
      </div>
    </div>
  );
}

export default function CreatePost({ onSaved, editId }: { onSaved?: () => void; editId?: number }) {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const c = usePostComposer();
  const [tab, setTab] = useState("all"); // 'all' or platform id
  // Connected accounts, merged from both backends: the legacy Connection store
  // (YouTube/TikTok/Instagram — drives posting) and the newer SocialAccount
  // store (X, LinkedIn, Threads, …). The directory also carries each account's
  // avatar + display name so the picker can show who you're posting as.
  // reconnectIds: platforms whose account exists but can't publish until the
  // user re-runs OAuth (expired/revoked token) — shown as "Reconnect".
  const { directory: accountDirectory, accountsByPlatform, connectedIds, reconnectIds, loading: connLoading } = useAccountDirectory();
  // The picker always shows these; connected ones are selectable, the rest get
  // a red "connect" badge linking to the connections screen. Platforms still
  // waiting on their app approval only show once an account is connected.
  const supportedPlatforms = PLATFORMS.filter((p) => SUPPORTED_PLATFORM_IDS.includes(p.id)
    || (SHOWN_WHEN_CONNECTED_IDS.includes(p.id) && connectedIds.includes(p.id)));
  // Narrow the picker to platforms that can carry the chosen post type. A "text"
  // post hides media-only platforms (YouTube/TikTok/Instagram); a "media" post
  // hides text-only ones (X).
  const visiblePlatforms = supportedPlatforms.filter((p) => platformAllowedForType(p.id, c.postType));
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
      toast.error(t("post.composer.brandNoAccounts", "None of {{brand}}'s accounts can post right now — reconnect them on the Connections page.", { brand: b.name }));
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
      else setLoadError(res.error || t("post.composer.loadFailed", "Couldn't load this post."));
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
    if (!res.success) toast.error(res.error || t("post.composer.uploadFailed", "Upload failed."));
  };

  const onPickCover = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    e.target.value = "";
    if (!file) return;
    const res = await c.uploadCover(file);
    if (!res.success) toast.error(res.error || t("post.composer.coverUploadFailed", "Cover upload failed."));
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
  //
  // Known limitation: creator info is not refetched when the selected TikTok
  // account changes, so with more than one account selected the panel shows the
  // first account's nickname, privacy options and interaction flags and applies
  // them to all of them. TikTok requires the options shown to match the account
  // being posted to, so the others can be rejected.
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
  // Pinterest posts 2-5 images as one carousel Pin; extra images are left out.
  const PINTEREST_MAX_IMAGES = 5;
  const pinterestImageOverflow = c.postType === "media" && !hasVideo && imageCount > PINTEREST_MAX_IMAGES && c.selected.includes("pinterest");
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
    id === "tiktok" || id === "youtube" || id === "pinterest" || (id === "instagram" && mediaVideoPost)
  );
  const activeOptTab = optionTabs.includes(optTab) ? optTab : (optionTabs[0] ?? "");

  // Pinterest: one board picker per picked account (a board belongs to one
  // account), loaded live. Nothing is chosen for the user; publishing waits
  // until every account has a board.
  const pinterestSelected = c.selected.includes("pinterest");
  const pinterestAccounts = (accountsByPlatform["pinterest"] ?? []).filter((a) => a.id !== null
    && (c.accountSel["pinterest"]?.length ? c.accountSel["pinterest"].includes(a.id) : true))
    .slice(0, c.accountSel["pinterest"]?.length ? undefined : 1);
  const pinAccountKey = pinterestAccounts.map((a) => a.id).join(",");
  const [pinBoards, setPinBoards] = useState<Record<number, { state: "loading" | "idle" | "error"; boards: PinterestBoard[] }>>({});
  const loadPinBoards = async (accountId: number) => {
    setPinBoards((m) => ({ ...m, [accountId]: { state: "loading", boards: m[accountId]?.boards ?? [] } }));
    const res = await viewsMaxApi.getPinterestBoards(accountId);
    setPinBoards((m) => ({ ...m, [accountId]: res.success ? { state: "idle", boards: res.data ?? [] } : { state: "error", boards: [] } }));
    // One board: nothing to choose between, so it's selected (and shown).
    if (res.success && res.data?.length === 1) c.selectOnlyPinterestBoard(accountId, res.data[0].id);
  };
  useEffect(() => {
    if (pinterestSelected) pinterestAccounts.forEach((a) => loadPinBoards(a.id!));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pinterestSelected, pinAccountKey]);
  // Inline "New board" form per Pinterest account.
  const [newBoard, setNewBoard] = useState<Record<number, { open: boolean; name: string; saving: boolean; error: string | null }>>({});
  const patchNewBoard = (id: number, p: Partial<{ open: boolean; name: string; saving: boolean; error: string | null }>) =>
    setNewBoard((m) => ({ ...m, [id]: { open: false, name: "", saving: false, error: null, ...m[id], ...p } }));
  const createPinBoard = async (accountId: number) => {
    const name = (newBoard[accountId]?.name ?? "").trim();
    if (!name) return;
    patchNewBoard(accountId, { saving: true, error: null });
    const res = await viewsMaxApi.createPinterestBoard(accountId, name);
    if (!res.success || !res.data) {
      patchNewBoard(accountId, { saving: false, error: res.error ?? "Couldn't create the board." });
      return;
    }
    const board = res.data;
    setPinBoards((m) => ({ ...m, [accountId]: { state: "idle", boards: [...(m[accountId]?.boards ?? []), board] } }));
    c.patchPinterest({ board_ids: { ...c.pinterest.board_ids, [String(accountId)]: board.id } });
    patchNewBoard(accountId, { open: false, name: "", saving: false, error: null });
  };
  const pinterestMissingBoard = pinterestSelected && pinterestAccounts.some((a) => !c.pinterest.board_ids[String(a.id)]);
  // A video Pin needs a cover image, chosen on purpose in the Pinterest tab.
  const pinterestNeedsCover = pinterestSelected && mediaVideoPost && !c.cover?.url;
  // Pinterest refuses a carousel whose images have different width-to-height
  // ratios (seen on the real API). Measure the images that would be posted.
  const carouselImages = pinterestSelected && c.postType === "media" && !hasVideo
    ? c.media.filter((m) => m.type === "image" && (m.preview || m.url)).slice(0, PINTEREST_MAX_IMAGES)
    : [];
  const carouselKey = carouselImages.map((m) => m.id).join(",");
  const [imageRatios, setImageRatios] = useState<Record<number, number>>({});
  useEffect(() => {
    carouselImages.forEach((m) => {
      if (imageRatios[m.id]) return;
      const img = new Image();
      img.onload = () => setImageRatios((r) => ({ ...r, [m.id]: img.naturalWidth / img.naturalHeight }));
      img.src = m.preview || resolveMediaUrl(m.url!);
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [carouselKey]);
  const measured = carouselImages.map((m) => imageRatios[m.id]).filter((r): r is number => !!r);
  const pinterestMixedShapes = carouselImages.length > 1 && measured.length === carouselImages.length
    && Math.max(...measured) - Math.min(...measured) > 0.01;

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
    : c.creatorCannotPost ? c.creatorCannotPost
    // A failed creator_info lookup leaves the privacy options empty, so without
    // this the button would blame the user for not choosing one. Show TikTok's
    // own reason — being throttled and being offline need different responses.
    : c.creatorInfoError ? c.creatorInfoError
    : tiktokNeedsPrivacy ? t("post.composer.tiktokNeedsPrivacy", "Choose who can view your TikTok post before publishing.")
    : tiktokNeedsBrand ? t("post.composer.tiktokNeedsBrand", "You need to indicate if your content promotes yourself, a third party, or both.")
    : tiktokBrandedPrivate ? t("post.composer.tiktokBrandedPrivate", "Branded content can't be set to private on TikTok.")
    : tiktokDurationOver ? t("post.composer.tiktokDurationBlock", "Your TikTok video is {{duration}}s — this account allows up to {{max}}s.", { duration: Math.round(tiktokVideoDuration), max: tiktokMaxDur })
    : null;
  const pinterestBlockReason: string | null = !pinterestSelected ? null
    : pinterestMissingBoard ? "Choose a Pinterest board before publishing."
    : pinterestNeedsCover ? "Add a cover image for the Pinterest video Pin."
    : pinterestMixedShapes ? "Pinterest carousel images must all have the same shape (width-to-height ratio). Crop them to match, or post one image."
    : null;
  const blockReason = tiktokBlockReason ?? pinterestBlockReason;

  // Shared cover uploader — one uploaded image drives both the YouTube thumbnail
  // and the Instagram Reel cover, so the same control renders in either tab.
  const coverUploaderBlock = (label: string) => (
    <div style={{ display: "flex", flexDirection: "column", gap: 10 }}>
      <span style={{ fontSize: 12, color: "var(--ink-on-paper-3)", lineHeight: 1.5 }}>{t("post.composer.customCoverFor", "Custom cover image for {{platform}}.", { platform: label })}</span>
      <div style={{ display: "flex", gap: 12, alignItems: "center", flexWrap: "wrap" }}>
        <input ref={coverInput} type="file" accept="image/*" hidden onChange={onPickCover} />
        {c.cover?.url ? (
          <div style={{ position: "relative", width: 96, height: 96, borderRadius: 12, overflow: "hidden", background: `center/cover no-repeat url(${resolveMediaUrl(c.cover.url)})`, boxShadow: "inset 0 0 0 1px rgba(0,0,0,.06)" }}>
            <button onClick={c.removeCover} title={t("post.composer.removeCover", "Remove cover")} style={{ position: "absolute", top: 4, right: 4, width: 20, height: 20, borderRadius: "50%", border: "none", background: "rgba(10,10,12,.62)", color: "#fff", cursor: "pointer", fontSize: 12, lineHeight: 1, display: "grid", placeItems: "center" }}>✕</button>
          </div>
        ) : (
          <button onClick={() => coverInput.current?.click()} disabled={c.coverUploading} style={{ width: 96, height: 96, borderRadius: 12, border: "1.5px dashed var(--line-2)", background: "var(--paper-1)", cursor: c.coverUploading ? "default" : "pointer", display: "flex", flexDirection: "column", gap: 4, alignItems: "center", justifyContent: "center", color: "var(--ink-on-paper-3)" }}>
            <Icon name="image-plus" size={20} stroke="var(--ink-on-paper-3)" />
            <span style={{ fontSize: 10, fontWeight: 700, fontFamily: "var(--font-body)" }}>{c.coverUploading ? t("post.composer.uploading", "Uploading…") : t("post.composer.addCover", "Add cover")}</span>
          </button>
        )}
        {c.cover?.url && (
          <button onClick={() => coverInput.current?.click()} style={{ background: "none", border: "none", color: "var(--vm-volt-deep)", fontWeight: 700, fontSize: 12.5, cursor: "pointer", fontFamily: "var(--font-body)" }}>{t("post.composer.replace", "Replace")}</button>
        )}
      </div>
      {c.coverError && <span style={{ fontSize: 12, color: "var(--vm-red)" }}>{c.coverError}</span>}
    </div>
  );

  const persist = async (status: "draft" | "scheduled" | "posted") => {
    if (c.selected.length === 0) { toast.error(t("post.composer.pickPlatform", "Pick at least one platform.")); return; }
    if (status === "scheduled" && !c.date) { toast.error(t("post.composer.chooseDate", "Choose a date to schedule.")); return; }
    // Over-limit captions are a hard stop for publishing/scheduling — the
    // backend enforces the same rule with a 422, so don't send a doomed post.
    if (status !== "draft" && c.issues.length > 0) {
      toast.error(t("post.composer.fixOverLimit", "Fix the over-limit captions before posting — drafts can still be saved."));
      return;
    }
    if (status !== "draft" && c.youtubeTitleMissing) { toast.error(youtubeTitleMsg(t)); return; }
    if (c.mediaUploading) { toast.error(t("post.composer.waitForUpload", "Wait for media to finish uploading.")); return; }
    if (c.mediaFailed) { toast.error(t("post.composer.removeFailedMedia", "Remove or re-upload the failed media.")); return; }
    // TikTok accepts a video or a photo slideshow (one or more images).
    if (status !== "draft" && c.selected.includes("tiktok") && !c.media.some((m) => (m.type === "video" || m.type === "image") && m.url)) {
      toast.error(t("post.composer.tiktokNeedsMedia", "TikTok needs an uploaded video or image.")); return;
    }
    // TikTok audit compliance — mirror the composer gating (also enforced with a
    // 422 by the backend) so a non-compliant TikTok post can never be sent.
    if (status !== "draft" && tiktokBlockReason) { toast.error(tiktokBlockReason); return; }
    // YouTube uploads the stored video file directly.
    if (status !== "draft" && c.selected.includes("youtube") && !c.media.some((m) => m.type === "video" && m.url)) {
      toast.error(t("post.composer.youtubeNeedsVideo", "YouTube needs an uploaded video.")); return;
    }
    // Every Pin is an image or a video, and a video Pin needs a cover image.
    if (status !== "draft" && c.selected.includes("pinterest") && !c.media.some((m) => m.url)) {
      toast.error("Pinterest needs an uploaded image or video."); return;
    }
    if (status !== "draft" && pinterestBlockReason) { toast.error(pinterestBlockReason); return; }
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
      // Pinterest: exactly what was typed. Empty fields are left out, so the
      // Pin has no title/link/alt text rather than one we made up.
      if (c.selected.includes("pinterest")) {
        const pin: Record<string, unknown> = { board_ids: c.pinterest.board_ids };
        // Single-account posts send board_id too (targets without a pinned account use it).
        if (pinterestAccounts.length === 1) {
          const only = c.pinterest.board_ids[String(pinterestAccounts[0].id)];
          if (only) pin.board_id = only;
        }
        for (const k of ["title", "link", "alt_text"] as const) {
          if (c.pinterest[k].trim() !== "") pin[k] = c.pinterest[k].trim();
        }
        if (mediaPost && c.cover?.url) pin.cover_url = c.cover.url;
        o.pinterest = pin;
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
        toast.success(isEditing ? t("post.composer.changesSavedToast", "Changes saved.") : t("post.composer.draftSaved", "Draft saved."));
      } else if (status === "posted") {
        // Publishing runs asynchronously on the queue — the post is NOT live
        // yet, so don't claim "Posted". Send the user to History to watch it.
        //
        // Content Sharing Guidelines, Required UX 5(d) applies to TikTok only:
        // those users must be told it may take a few minutes to process and that
        // it then becomes visible on their profile, so that notice stays on
        // screen until dismissed. Every other platform keeps the short toast it
        // has always had — a TikTok rule should not change how posting to X or
        // LinkedIn behaves.
        if (c.selected.includes("tiktok")) {
          toast(t("post.composer.publishingTiktok", "Publishing — it may take a few minutes for your post to finish processing and appear on your profile. Track it in History."), { duration: Infinity, closeButton: true });
        } else {
          toast(t("post.composer.processing", "Processing — we're publishing your post. Track it in History."));
        }
        navigate("/dashboard/post/history");
      } else {
        setDone("schedule");
        toast.success(isEditing ? t("post.composer.changesSavedToast", "Changes saved.") : t("post.composer.scheduledToast", "Scheduled!"));
      }
    } else {
      toast.error(res.error || t("post.composer.saveFailed", "Couldn't save the post."));
    }
  };

  if (loadingPost) {
    return <div style={{ padding: 48, textAlign: "center", color: "var(--ink-on-paper-3)", fontFamily: "var(--font-body)", fontSize: 14 }}>{t("post.composer.loadingPost", "Loading post…")}</div>;
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
        <style>{`
          .cp-grid { display:grid; grid-template-columns:minmax(0,1fr) 440px; gap:24px; align-items:start; }
          @media (max-width:1280px){ .cp-grid { grid-template-columns:minmax(0,1fr) 360px; } }
          @media (max-width:960px){
            .cp-grid { grid-template-columns:minmax(0,1fr); padding-bottom:88px; }
            .cp-side { position:static !important; }
            .cp-actions {
              position:fixed; left:0; right:0; bottom:0; z-index:30;
              padding:12px 16px calc(12px + env(safe-area-inset-bottom));
              background:var(--paper-0); border-top:1px solid var(--line-1);
              border-radius:0 !important; box-shadow:0 -6px 20px -10px rgba(10,10,12,.18);
            }
          }
        `}</style>
        <div className="cp-grid">
          {/* ============ EDITOR ============ */}
          <div style={{ minWidth: 0, display: "flex", flexDirection: "column", gap: 16 }}>
            <Card>
              <SectionLabel>{t("post.composer.postType", "Post type")}</SectionLabel>
              <div style={{ display: "inline-flex", background: "var(--paper-2)", borderRadius: 999, padding: 4, gap: 4 }}>
                {([["media", t("post.composer.mediaPost", "Media post"), "image"], ["text", t("post.composer.textPost", "Text post"), "type"]] as const).map(([pt, l, ic]) => (
                  <button key={pt} onClick={() => c.setPostType(pt)} style={{ display: "inline-flex", alignItems: "center", gap: 6, border: "none", cursor: "pointer", padding: "8px 16px", borderRadius: 999, fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 13, background: c.postType === pt ? "var(--paper-0)" : "transparent", color: c.postType === pt ? "var(--ink-on-paper-1)" : "var(--ink-on-paper-3)", boxShadow: c.postType === pt ? "0 1px 3px rgba(10,10,12,.12)" : "none" }}>
                    <Icon name={ic} size={14} stroke={c.postType === pt ? "var(--ink-on-paper-1)" : "var(--ink-on-paper-3)"} /> {l}
                  </button>
                ))}
              </div>
              <div style={{ marginTop: 10, fontSize: 12, color: "var(--ink-on-paper-3)", lineHeight: 1.5 }}>
                {c.postType === "media"
                  ? t("post.composer.mediaPostHint", "Photo or video. Platforms that only take media are shown.")
                  : t("post.composer.textPostHint", "Text-only. Only platforms that can post without media are shown.")}
              </div>
            </Card>

            <Card>
              <SectionLabel hint={connectedCount ? t("post.composer.selectedOfConnected", "{{selected}} of {{total}} selected", { selected: c.selected.length, total: connectedCount }) : undefined}>{t("post.composer.publishingTo", "Publishing to")}</SectionLabel>
              {connLoading ? (
                <div style={{ fontSize: 12.5, color: "var(--ink-on-paper-3)" }}>{t("post.composer.loadingAccounts", "Loading your connected accounts…")}</div>
              ) : (
                <>
                  {brands.length > 0 && (
                    <div style={{ display: "flex", alignItems: "center", gap: 8, flexWrap: "wrap", marginBottom: 14 }}>
                      <span className="vm-eyebrow" style={{ color: "var(--ink-on-paper-3)" }}>{t("post.composer.brands", "Brands")}</span>
                      {brands.map((b) => (
                        <button key={b.id} onClick={() => applyBrand(b)} title={t("post.composer.selectBrandAccounts", "Select all of {{brand}}'s accounts", { brand: b.name })} style={tabStyle(activeBrandId === b.id)}>
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
                          const label = broken ? t("post.composer.reconnect", "Reconnect") : (a.name || p.name);
                          return (
                            <button
                              key={`${p.id}-${a.id}`}
                              onClick={() => (broken ? navigate("/dashboard/connections") : c.toggleAccount(p.id, a.id!))}
                              title={broken ? t("post.composer.authExpired", "{{platform}} authorization expired — reconnect the account", { platform: p.name }) : `${p.name} — ${a.name ?? t("post.composer.connectedAccount", "connected account")}`}
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
                          title={connected ? p.name : needsReconnect ? t("post.composer.authExpired", "{{platform}} authorization expired — reconnect the account", { platform: p.name }) : t("post.composer.connectPlatform", "Connect {{platform}}", { platform: p.name })}
                          style={{ display: "flex", flexDirection: "column", alignItems: "center", gap: 5, background: "none", border: "none", cursor: "pointer", padding: 2 }}
                        >
                          <PAvatar id={p.id} size={44} ring={on} dim={connected && !on} connect={!connected} badge={on && c.status[p.id].over ? "!" : null} avatarUrl={accountDirectory[p.id]?.avatarUrl} />
                          <span style={{ fontSize: 10.5, fontWeight: 600, maxWidth: 72, overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap", color: !connected ? "var(--vm-red)" : on ? "var(--ink-on-paper-1)" : "var(--ink-on-paper-3)", fontFamily: "var(--font-body)" }}>{connected ? (accountDirectory[p.id]?.name ?? p.name) : needsReconnect ? t("post.composer.reconnect", "Reconnect") : t("post.composer.connect", "Connect")}</span>
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
                      }} style={{ marginLeft: 4, alignSelf: "flex-start", marginTop: 2, background: "var(--paper-2)", border: "1px dashed var(--line-2)", borderRadius: 999, padding: "7px 13px", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 12, color: "var(--ink-on-paper-2)", cursor: "pointer" }}>{t("post.composer.selectAll", "All")}</button>
                    )}
                  </div>
                  {connectedCount === 0 && (
                    <div style={{ marginTop: 12, fontSize: 12.5, color: "var(--ink-on-paper-3)", lineHeight: 1.5 }}>
                      <Trans
                        i18nKey="post.composer.noConnectedHint"
                        defaults="Tap a platform to connect it, or <0>open the connections screen</0>."
                        components={[<button key="open" onClick={() => navigate("/dashboard/connections")} style={{ background: "none", border: "none", padding: 0, color: "var(--vm-red)", fontWeight: 700, cursor: "pointer", font: "inherit" }} />]}
                      />
                    </div>
                  )}
                  {linkedInVideoConflict && (
                    <div style={{ marginTop: 12, display: "flex", alignItems: "flex-start", gap: 8, background: "var(--vm-red-tint-l)", border: "1px solid rgba(255,31,61,.35)", borderRadius: 10, padding: "9px 11px", fontSize: 12.5, fontWeight: 600, color: "var(--vm-red-deep)", lineHeight: 1.45 }}>
                      <Icon name="alert-triangle" size={15} stroke="var(--vm-red)" />
                      <span>{t("post.composer.linkedinVideoConflict", "LinkedIn can't post video — its target will be marked failed. Use an image for LinkedIn, or remove it from this post.")}</span>
                    </div>
                  )}
                  {xVideoConflict && (
                    <div style={{ marginTop: 12, display: "flex", alignItems: "flex-start", gap: 8, background: "var(--vm-red-tint-l)", border: "1px solid rgba(255,31,61,.35)", borderRadius: 10, padding: "9px 11px", fontSize: 12.5, fontWeight: 600, color: "var(--vm-red-deep)", lineHeight: 1.45 }}>
                      <Icon name="alert-triangle" size={15} stroke="var(--vm-red)" />
                      <span>{t("post.composer.xVideoConflict", "X can't post video — its target will be marked failed. Use images for X, or remove it from this post.")}</span>
                    </div>
                  )}
                  {xImageOverflow && (
                    <div style={{ marginTop: 12, display: "flex", alignItems: "flex-start", gap: 8, background: "var(--paper-2)", border: "1px solid var(--line-1)", borderRadius: 10, padding: "9px 11px", fontSize: 12.5, fontWeight: 600, color: "var(--ink-on-paper-2)", lineHeight: 1.45 }}>
                      <Icon name="info" size={15} stroke="var(--ink-on-paper-3)" />
                      <span>{t("post.composer.xImageOverflow", "X takes up to {{max}} images — only the first {{max}} will be posted there.", { max: X_MAX_IMAGES })}</span>
                    </div>
                  )}
                  {pinterestImageOverflow && (
                    <div style={{ marginTop: 12, display: "flex", alignItems: "flex-start", gap: 8, background: "var(--paper-2)", border: "1px solid var(--line-1)", borderRadius: 10, padding: "9px 11px", fontSize: 12.5, fontWeight: 600, color: "var(--ink-on-paper-2)", lineHeight: 1.45 }}>
                      <Icon name="info" size={15} stroke="var(--ink-on-paper-3)" />
                      <span>Pinterest takes up to {PINTEREST_MAX_IMAGES} images (as one carousel Pin) — only the first {PINTEREST_MAX_IMAGES} will be posted there.</span>
                    </div>
                  )}
                </>
              )}
            </Card>

            {c.postType === "media" && (
            <Card>
              <SectionLabel hint={t("post.composer.mediaCount", { count: c.media.length, defaultValue_one: "{{count}} item", defaultValue_other: "{{count}} items" })}>{t("post.composer.media", "Media")}</SectionLabel>
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
                <button onClick={() => mediaInput.current?.click()} title={t("post.composer.addPhotoOrVideo", "Add photo or video")} style={{ width: 72, height: 72, borderRadius: 12, border: "1.5px dashed var(--line-2)", background: "var(--paper-1)", cursor: "pointer", display: "flex", flexDirection: "column", gap: 4, alignItems: "center", justifyContent: "center", color: "var(--ink-on-paper-3)" }}>
                  <Icon name="image-plus" size={20} stroke="var(--ink-on-paper-3)" />
                  <span style={{ fontSize: 10, fontWeight: 700, fontFamily: "var(--font-body)" }}>{t("post.composer.addMedia", "Add media")}</span>
                </button>
              </div>
              {c.media.length > 1 && (
                <div style={{ marginTop: 8, fontSize: 11.5, color: "var(--ink-on-paper-3)", lineHeight: 1.5 }}>
                  {t("post.composer.dragToReorder", "Drag to reorder — the order here is the slideshow order.")}
                </div>
              )}

              {/* Upload progress — shown directly under the media row. */}
              {uploadingMedia && (
                <div style={{ marginTop: 14 }}>
                  <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", marginBottom: 6 }}>
                    <span style={{ display: "inline-flex", alignItems: "center", gap: 6, fontSize: 12.5, fontWeight: 600, color: "var(--ink-on-paper-2)" }}>
                      <Icon name="rotate-cw" size={13} stroke="var(--ink-on-paper-3)" /> {uploadingMedia.type === "video" ? t("post.composer.uploadingVideo", "Uploading video…") : t("post.composer.uploadingImage", "Uploading image…")}
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
                    {t("post.composer.filesFailed", { count: failedMedia.length, defaultValue_one: "A file didn't upload. Retry or remove before posting.", defaultValue_other: "{{count}} files didn't upload. Retry or remove before posting." })}
                  </span>
                  <button onClick={() => failedMedia.forEach((m) => c.retryMedia(m.id))} style={{ marginLeft: "auto", display: "inline-flex", alignItems: "center", gap: 5, background: "none", border: "none", color: "var(--vm-red)", fontWeight: 700, fontSize: 12.5, cursor: "pointer", fontFamily: "var(--font-body)" }}>
                    <Icon name="rotate-cw" size={13} stroke="var(--vm-red)" /> {t("post.composer.retryAll", "Retry all")}
                  </button>
                </div>
              )}
            </Card>
            )}

            <Card>
              {/* TikTok audit 2a: the post text is the platform "Title/Description"
                  metadata (TikTok title, YouTube title+description, etc.). */}
              <SectionLabel>{t("post.composer.titleDescription", "Title / Description")}</SectionLabel>
              <div style={{ display: "flex", gap: 6, marginBottom: 12, flexWrap: "wrap" }}>
                <button onClick={() => setTab("all")} style={tabStyle(tab === "all")}>
                  <Icon name="layers" size={13} stroke={tab === "all" ? "#fff" : "var(--ink-on-paper-3)"} /> {t("post.composer.allPlatforms", "All platforms")}
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
                  <MentionTextarea value={c.caption} onChangeText={c.setCaption} mentions={c.selected.includes("x")} accountId={c.accountSel["x"]?.[0]} rows={6} style={{ ...fieldBase, resize: "vertical", lineHeight: 1.55, minHeight: 130 }} placeholder={t("post.composer.captionPlaceholder", "Write your title / description…")} />
                  <div style={{ display: "flex", alignItems: "center", gap: 7, marginTop: 12, flexWrap: "wrap" }}>
                    {c.selected.map((id) => <CountChip key={id} id={id} status={c.status} />)}
                  </div>
                  {c.selected.includes("pinterest") && (
                    <div style={{ marginTop: 10, fontSize: 11.5, color: "var(--ink-on-paper-3)", lineHeight: 1.5 }}>
                      This text is the Pinterest description. Pinterest also has a title:{" "}
                      <button onClick={() => setTab("pinterest")} style={{ background: "none", border: "none", padding: 0, color: "var(--vm-red)", fontWeight: 700, fontSize: 11.5, cursor: "pointer", textDecoration: "underline", fontFamily: "var(--font-body)" }}>open the Pinterest tab</button>.
                    </div>
                  )}
                  {captionHasDelimiter && c.selected.some((id) => id !== "x") && (
                    <div style={{ marginTop: 10, fontSize: 11.5, color: "var(--ink-on-paper-3)", lineHeight: 1.5 }}>
                      <Trans
                        i18nKey="post.composer.delimiterHint"
                        defaults="<0>---</0> starts a new tweet on X only — other platforms post it as plain text."
                        components={[<code key="delim" style={{ fontFamily: "var(--font-mono)", background: "var(--paper-2)", padding: "1px 5px", borderRadius: 4 }} />]}
                      />
                    </div>
                  )}
                </>
              ) : tab === "x" ? (
                <>
                  <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", marginBottom: 8 }}>
                    <span style={{ fontSize: 12.5, color: "var(--ink-on-paper-2)", fontWeight: 600 }}>{t("post.composer.tweetsForX", "Tweets for X")}</span>
                    {c.overrides.x != null && c.overrides.x !== "" && (
                      <button onClick={() => { c.clearOverride("x"); setXActive(0); }} style={{ background: "none", border: "none", color: "var(--vm-red)", fontWeight: 700, fontSize: 12, cursor: "pointer", fontFamily: "var(--font-body)" }}>{t("post.composer.resetToMain", "Reset to main")}</button>
                    )}
                  </div>
                  <XTweetEditor c={c} active={xActive} onSelect={setXActive} />
                </>
              ) : (
                <>
                  {tab === "pinterest" && (
                    <label style={{ display: "flex", flexDirection: "column", gap: 6, marginBottom: 14 }}>
                      <span style={{ display: "flex", justifyContent: "space-between", fontSize: 12.5, color: "var(--ink-on-paper-2)", fontWeight: 600 }}>
                        <span>Pinterest title (optional)</span>
                        <span style={{ fontFamily: "var(--font-mono)", fontWeight: 500, fontSize: 11.5, color: "var(--ink-on-paper-3)" }}>{c.pinterest.title.length}/100</span>
                      </span>
                      <input
                        aria-label="Pinterest title"
                        value={c.pinterest.title}
                        maxLength={100}
                        onChange={(e) => c.patchPinterest({ title: e.target.value })}
                        style={fieldBase}
                        placeholder="The bold headline under the image. Leave empty for a Pin without a title."
                      />
                    </label>
                  )}
                  <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", marginBottom: 8 }}>
                    <span style={{ fontSize: 12.5, color: "var(--ink-on-paper-2)", fontWeight: 600 }}>{tab === "pinterest" ? t("post.composer.pinterestDescription", "Pinterest description") : t("post.composer.customTextFor", "Custom text for {{platform}}", { platform: PMAP[tab].name })}</span>
                    {c.overrides[tab] != null && c.overrides[tab] !== "" && (
                      <button onClick={() => c.clearOverride(tab)} style={{ background: "none", border: "none", color: "var(--vm-red)", fontWeight: 700, fontSize: 12, cursor: "pointer", fontFamily: "var(--font-body)" }}>{t("post.composer.resetToMain", "Reset to main")}</button>
                    )}
                  </div>
                  <textarea value={overrideVal} onChange={(e) => c.setOverride(tab, e.target.value)} rows={6} style={{ ...fieldBase, resize: "vertical", lineHeight: 1.55, minHeight: 130 }} placeholder={t("post.composer.overridePlaceholder", "Leave blank to use the main caption…")} />
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
                <SectionLabel>{t("post.composer.platformOptions", "Platform options")}</SectionLabel>
                <div style={{ display: "flex", gap: 6, marginBottom: 14, flexWrap: "wrap" }}>
                  {optionTabs.map((id) => (
                    <button key={id} onClick={() => setOptTab(id)} style={tabStyle(activeOptTab === id)}>
                      <PAvatar id={id} size={16} /> {PMAP[id].name}
                    </button>
                  ))}
                </div>

                {activeOptTab === "tiktok" && (
                  c.creatorInfoLoading ? (
                    <div style={{ fontSize: 12.5, color: "var(--ink-on-paper-3)" }}>{t("post.composer.loadingTiktokOptions", "Loading TikTok options…")}</div>
                  ) : c.creatorCannotPost ? (
                    // Content Sharing Guidelines, Required UX 1(b): the creator cannot post
                    // right now, so stop here rather than showing an options panel
                    // that cannot be used.
                    <div style={{ display: "flex", alignItems: "flex-start", gap: 8, background: "var(--vm-red-tint-l)", border: "1px solid rgba(255,31,61,.35)", borderRadius: 10, padding: "11px 13px" }}>
                      <Icon name="alert-triangle" size={16} stroke="var(--vm-red)" />
                      <div style={{ display: "flex", flexDirection: "column", gap: 6 }}>
                        <span style={{ fontSize: 12.5, fontWeight: 700, color: "var(--vm-red-deep)", lineHeight: 1.45 }}>{c.creatorCannotPost}</span>
                        <span style={{ fontSize: 11.5, color: "var(--ink-on-paper-2)", lineHeight: 1.5 }}>
                          {t("post.composer.tiktokCannotPostHint", "You can still save this as a draft. TikTok publishing is unavailable for this account until the limit clears.")}
                        </span>
                        <button onClick={() => c.loadCreatorInfo()} style={{ alignSelf: "flex-start", background: "none", border: "none", padding: 0, color: "var(--vm-red)", fontWeight: 700, fontSize: 12, cursor: "pointer", textDecoration: "underline", fontFamily: "var(--font-body)" }}>{t("post.composer.checkAgain", "Check again")}</button>
                      </div>
                    </div>
                  ) : c.creatorInfoError ? (
                    <div style={{ display: "flex", alignItems: "center", gap: 8, fontSize: 12.5, color: "var(--vm-red)" }}>
                      <Icon name="alert-circle" size={15} stroke="var(--vm-red)" /> {c.creatorInfoError}
                      <button onClick={() => c.loadCreatorInfo()} style={{ background: "none", border: "none", color: "var(--vm-red)", fontWeight: 700, cursor: "pointer", textDecoration: "underline" }}>{t("post.composer.retry", "Retry")}</button>
                    </div>
                  ) : (
                    <div style={{ display: "flex", flexDirection: "column", gap: 14 }}>
                      {/* Which TikTok account this uploads to (creator nickname). */}
                      {(c.creatorInfo?.creator_nickname || c.creatorInfo?.creator_username) && (
                        <div style={{ display: "flex", alignItems: "center", gap: 8, fontSize: 12.5, color: "var(--ink-on-paper-2)" }}>
                          {c.creatorInfo?.creator_avatar_url && (
                            <img src={c.creatorInfo.creator_avatar_url} alt="" width={22} height={22} style={{ borderRadius: "50%", flexShrink: 0 }} />
                          )}
                          <span>
                            <Trans
                              i18nKey="post.composer.uploadingTo"
                              defaults="Uploading to <0>{{account}}</0>"
                              values={{ account: c.creatorInfo?.creator_nickname || `@${c.creatorInfo?.creator_username}` }}
                              components={[<strong key="acct" />]}
                            />
                          </span>
                        </div>
                      )}

                      {/* Privacy — no default; the user must choose. "Only me" is
                          disabled while Branded content is on. */}
                      <label style={{ display: "flex", flexDirection: "column", gap: 6 }}>
                        <span style={{ fontSize: 11.5, fontWeight: 700, color: "var(--ink-on-paper-3)" }}>{t("post.composer.whoCanView", "Who can view this")}</span>
                        <select
                          value={c.tiktok.privacy_level}
                          onChange={(e) => c.setPrivacyLevel(e.target.value)}
                          style={{ ...fieldBase, width: 260, maxWidth: "100%", color: c.tiktok.privacy_level ? "var(--ink-on-paper-1)" : "var(--ink-on-paper-3)" }}
                        >
                          <option value="" disabled>{t("post.composer.selectWhoCanView", "Select who can view…")}</option>
                          {(c.creatorInfo?.privacy_level_options ?? []).map((opt) => (
                            <option
                              key={opt}
                              value={opt}
                              disabled={opt === "SELF_ONLY" && !!c.tiktok.branded_content}
                              title={opt === "SELF_ONLY" && c.tiktok.branded_content ? t("post.composer.brandedNotPrivate", "Branded content visibility cannot be set to private.") : undefined}
                            >{privacyLabels(t)[opt] ?? opt}</option>
                          ))}
                        </select>
                        {c.tiktok.branded_content && (
                          <span style={{ fontSize: 11, color: "var(--ink-on-paper-3)", lineHeight: 1.5 }}>{t("post.composer.brandedNotPrivate", "Branded content visibility cannot be set to private.")}</span>
                        )}
                      </label>

                      {/* Interactions — none enabled by default. Duet/Stitch don't
                          apply to photo posts, so they only show for video. */}
                      <div style={{ display: "flex", gap: 18, flexWrap: "wrap" }}>
                        <Toggle label={t("post.composer.allowComments", "Allow comments")} checked={!c.tiktok.disable_comment} disabled={c.creatorInfo?.comment_disabled} onChange={(v) => c.patchTiktok({ disable_comment: !v })} />
                        {mediaVideoPost && <Toggle label={t("post.composer.allowDuet", "Allow Duet")} checked={!c.tiktok.disable_duet} disabled={c.creatorInfo?.duet_disabled} onChange={(v) => c.patchTiktok({ disable_duet: !v })} />}
                        {mediaVideoPost && <Toggle label={t("post.composer.allowStitch", "Allow Stitch")} checked={!c.tiktok.disable_stitch} disabled={c.creatorInfo?.stitch_disabled} onChange={(v) => c.patchTiktok({ disable_stitch: !v })} />}
                      </div>

                      <div style={{ display: "flex", flexDirection: "column", gap: 6 }}>
                        <Toggle label={t("post.composer.autoAddMusic", "Auto-add music")} checked={!!c.tiktok.auto_add_music} onChange={(v) => c.patchTiktok({ auto_add_music: v })} />
                        <span style={{ fontSize: 11, color: "var(--ink-on-paper-3)", lineHeight: 1.5 }}>
                          {t("post.composer.autoAddMusicHint", "TikTok adds its recommended background music to photo / slideshow posts. Slideshows only — no effect on video.")}
                        </span>
                      </div>

                      {/* Commercial content disclosure — off by default; when on,
                          at least one of the two must be chosen. */}
                      <div style={{ borderTop: "1px solid var(--line-1)", paddingTop: 12, display: "flex", flexDirection: "column", gap: 10 }}>
                        <Toggle label={t("post.composer.discloseCommercial", "Disclose that this content promotes yourself, a brand, product or service")} checked={!!c.tiktok.disclose_commercial} onChange={(v) => c.patchTiktok({ disclose_commercial: v, your_brand: v ? c.tiktok.your_brand : false, branded_content: v ? c.tiktok.branded_content : false })} />
                        {c.tiktok.disclose_commercial && (
                          <div style={{ display: "flex", flexDirection: "column", gap: 8, paddingLeft: 6 }}>
                            {/* Content Sharing Guidelines, Required UX 3(b): when the visibility is
                                private, branded content is unavailable — say so, rather than
                                only greying the control out. */}
                            {c.tiktok.privacy_level === "SELF_ONLY" && (
                              <span style={{ fontSize: 11.5, fontWeight: 600, color: "var(--ink-on-paper-2)", lineHeight: 1.5 }}>
                                {t("post.composer.brandedPrivateHint", "Branded content visibility cannot be set to private — choose a different audience above to use it.")}
                              </span>
                            )}
                            <div style={{ display: "flex", gap: 18, flexWrap: "wrap" }}>
                              <Toggle label={t("post.composer.yourBrand", "Your brand")} checked={!!c.tiktok.your_brand} onChange={(v) => c.patchTiktok({ your_brand: v })} />
                              {/* Content Sharing Guidelines, Required UX 3(b): branded content only
                                  works with public/friends visibility, so it is disabled —
                                  with the required explanation on hover — while "only me"
                                  is chosen. */}
                              <span title={c.tiktok.privacy_level === "SELF_ONLY" ? t("post.composer.brandedNotPrivate", "Branded content visibility cannot be set to private.") : undefined}>
                                <Toggle
                                  label={t("post.composer.brandedContent", "Branded content")}
                                  checked={!!c.tiktok.branded_content}
                                  disabled={c.tiktok.privacy_level === "SELF_ONLY"}
                                  onChange={(v) => c.patchTiktok({ branded_content: v })}
                                />
                              </span>
                            </div>
                            {(c.tiktok.your_brand || c.tiktok.branded_content) && (
                              <span style={{ fontSize: 11.5, fontWeight: 600, color: "var(--ink-on-paper-2)", lineHeight: 1.5 }}>
                                {c.tiktok.branded_content
                                  ? t("post.composer.labeledPaidPartnership", "Your photo/video will be labeled as ‘Paid partnership’.")
                                  : t("post.composer.labeledPromotional", "Your photo/video will be labeled as ‘Promotional content’.")}
                              </span>
                            )}
                            {tiktokNeedsBrand && (
                              <span style={{ fontSize: 11.5, fontWeight: 600, color: "var(--vm-red)", lineHeight: 1.5 }}>
                                {t("post.composer.tiktokNeedsBrand", "You need to indicate if your content promotes yourself, a third party, or both.")}
                              </span>
                            )}
                          </div>
                        )}
                      </div>

                      {tiktokDurationOver && (
                        <div style={{ display: "flex", alignItems: "flex-start", gap: 8, background: "var(--vm-red-tint-l)", border: "1px solid rgba(255,31,61,.35)", borderRadius: 10, padding: "9px 11px", fontSize: 12, fontWeight: 600, color: "var(--vm-red-deep)", lineHeight: 1.45 }}>
                          <Icon name="alert-triangle" size={15} stroke="var(--vm-red)" />
                          <span>{t("post.composer.tiktokDurationOver", "This video is {{duration}}s — TikTok allows up to {{max}}s on this account. Trim it before posting.", { duration: Math.round(tiktokVideoDuration), max: tiktokMaxDur })}</span>
                        </div>
                      )}

                      {mediaVideoPost && (
                        <label style={{ display: "flex", flexDirection: "column", gap: 6, borderTop: "1px solid var(--line-1)", paddingTop: 12 }}>
                          <span style={{ fontSize: 11.5, fontWeight: 700, color: "var(--ink-on-paper-3)" }}>{t("post.composer.coverFrame", "Cover frame (seconds into the video)")}</span>
                          <input type="number" min={0} step={0.5} value={c.tiktokCoverSec} onChange={(e) => c.setTiktokCoverSec(Math.max(0, Number(e.target.value) || 0))} style={{ ...fieldBase, width: 160, fontFamily: "var(--font-mono)", fontSize: 13 }} />
                          <span style={{ fontSize: 11, color: "var(--ink-on-paper-3)", lineHeight: 1.5 }}>{t("post.composer.coverFrameHint", "TikTok only supports picking a frame time as the cover, not a custom image.")}</span>
                        </label>
                      )}
                    </div>
                  )
                )}

                {activeOptTab === "youtube" && (
                  <div style={{ display: "flex", flexDirection: "column", gap: 14 }}>
                    <label style={{ display: "flex", flexDirection: "column", gap: 6 }}>
                      <span style={{ fontSize: 11.5, fontWeight: 700, color: "var(--ink-on-paper-3)" }}>{t("post.composer.visibility", "Visibility")}</span>
                      <select
                        value={c.youtube.privacy_status}
                        onChange={(e) => c.patchYoutube({ privacy_status: e.target.value as "public" | "unlisted" | "private" })}
                        style={{ ...fieldBase, width: 260, maxWidth: "100%" }}
                      >
                        <option value="public">{t("post.composer.youtubePublic", "Public")}</option>
                        <option value="unlisted">{t("post.composer.youtubeUnlisted", "Unlisted (anyone with the link)")}</option>
                        <option value="private">{t("post.composer.youtubePrivate", "Private (only you)")}</option>
                      </select>
                    </label>
                    <span style={{ fontSize: 11, color: "var(--ink-on-paper-3)", lineHeight: 1.5 }}>
                      {t("post.composer.youtubeCaptionHint", "The caption becomes the video title (first 95 chars) and description.")}
                    </span>
                    {mediaVideoPost && coverUploaderBlock("YouTube")}
                  </div>
                )}

                {activeOptTab === "instagram" && (
                  <div style={{ display: "flex", flexDirection: "column", gap: 14 }}>
                    {coverUploaderBlock("Instagram")}
                  </div>
                )}

                {activeOptTab === "pinterest" && (
                  <div style={{ display: "flex", flexDirection: "column", gap: 16 }}>
                    {pinterestAccounts.map((a) => {
                      const entry = pinBoards[a.id!];
                      const value = c.pinterest.board_ids[String(a.id)] ?? "";
                      const nb = newBoard[a.id!];
                      const linkBtn: CSSProperties = { background: "none", border: "none", padding: 0, color: "var(--vm-red)", fontWeight: 700, fontSize: 11.5, cursor: "pointer", textDecoration: "underline", fontFamily: "var(--font-body)" };
                      return (
                        <div key={a.id} style={{ display: "flex", flexDirection: "column", gap: 6 }}>
                          <span style={{ fontSize: 11.5, fontWeight: 700, color: "var(--ink-on-paper-3)" }}>
                            Board{pinterestAccounts.length > 1 ? ` for ${a.name ?? "this account"}` : ""}
                          </span>
                          <select
                            aria-label={`Pinterest board${a.name ? ` for ${a.name}` : ""}`}
                            value={value}
                            onChange={(e) => c.patchPinterest({ board_ids: { ...c.pinterest.board_ids, [String(a.id)]: e.target.value } })}
                            disabled={entry?.state === "loading"}
                            style={{ ...fieldBase, width: 260, maxWidth: "100%", color: value ? "var(--ink-on-paper-1)" : "var(--ink-on-paper-3)" }}
                          >
                            <option value="" disabled>{entry?.state === "loading" ? "Loading boards…" : "Choose a board…"}</option>
                            {(entry?.boards ?? []).map((b) => (
                              <option key={b.id} value={b.id}>{b.name}{b.privacy === "SECRET" ? " (secret)" : ""}</option>
                            ))}
                          </select>
                          {nb?.open ? (
                            <div style={{ display: "flex", gap: 8, alignItems: "center", flexWrap: "wrap" }}>
                              <input
                                aria-label={`New Pinterest board name${a.name ? ` for ${a.name}` : ""}`}
                                value={nb.name}
                                autoFocus
                                placeholder="Board name"
                                onChange={(e) => patchNewBoard(a.id!, { name: e.target.value })}
                                onKeyDown={(e) => { if (e.key === "Enter") { e.preventDefault(); createPinBoard(a.id!); } }}
                                style={{ ...fieldBase, width: 260, maxWidth: "100%" }}
                              />
                              <button onClick={() => createPinBoard(a.id!)} disabled={nb.saving || !nb.name.trim()} style={linkBtn}>
                                {nb.saving ? "Creating…" : "Create board"}
                              </button>
                              <button onClick={() => patchNewBoard(a.id!, { open: false, name: "", error: null })} style={{ ...linkBtn, color: "var(--ink-on-paper-3)" }}>Cancel</button>
                            </div>
                          ) : (
                            <button onClick={() => patchNewBoard(a.id!, { open: true })} style={{ ...linkBtn, alignSelf: "flex-start" }}>+ New board</button>
                          )}
                          {nb?.error && (
                            <span style={{ fontSize: 11.5, color: "var(--vm-red-deep)", lineHeight: 1.5 }}>{nb.error}</span>
                          )}
                          <span style={{ fontSize: 11, color: "var(--ink-on-paper-3)", lineHeight: 1.5 }}>
                            A board is a folder on your Pinterest profile. The Pin is saved into the one you pick. New boards are public.
                          </span>
                          {entry?.state === "error" && (
                            <span style={{ fontSize: 11.5, color: "var(--vm-red-deep)", lineHeight: 1.5 }}>
                              Couldn't load the boards.{" "}
                              <button onClick={() => loadPinBoards(a.id!)} style={linkBtn}>Try again</button>
                            </span>
                          )}
                          {entry?.state === "idle" && entry.boards.length === 0 && !nb?.open && (
                            <span style={{ fontSize: 11.5, color: "var(--vm-red-deep)", lineHeight: 1.5 }}>
                              This account has no boards yet. Create one with "+ New board".
                            </span>
                          )}
                        </div>
                      );
                    })}
                    {([
                      ["link", "Link", 2048, "The page people open when they tap the Pin. Leave empty for a Pin without a link.", "url"],
                      ["alt_text", "Alt text", 500, "Describes the image for people using screen readers. Leave empty to skip.", "text"],
                    ] as const).map(([key, label, max, help, type]) => (
                      <label key={key} style={{ display: "flex", flexDirection: "column", gap: 6 }}>
                        <span style={{ fontSize: 11.5, fontWeight: 700, color: "var(--ink-on-paper-3)" }}>{label}</span>
                        <input
                          type={type}
                          aria-label={`Pinterest ${label.toLowerCase()}`}
                          value={c.pinterest[key]}
                          maxLength={max}
                          onChange={(e) => c.patchPinterest({ [key]: e.target.value })}
                          style={{ ...fieldBase, maxWidth: 480 }}
                        />
                        <span style={{ fontSize: 11, color: "var(--ink-on-paper-3)", lineHeight: 1.5 }}>{help}</span>
                      </label>
                    ))}
                    <div style={{ fontSize: 11.5, color: "var(--ink-on-paper-3)", lineHeight: 1.5, borderTop: "1px solid var(--line-1)", paddingTop: 12 }}>
                      The Pin's title and description are written above, in Title / Description → Pinterest.
                    </div>
                    {mediaVideoPost && coverUploaderBlock("Pinterest")}
                    {pinterestNeedsCover && (
                      <span style={{ fontSize: 11.5, fontWeight: 600, color: "var(--vm-red)", lineHeight: 1.5 }}>
                        A video Pin needs a cover image. Add one above.
                      </span>
                    )}
                  </div>
                )}

              </Card>
            )}

            <Card>
              <SectionLabel>{t("post.composer.links", "Links")}</SectionLabel>
              <Toggle
                label={t("post.composer.shortenLinks", "Shorten & track links")}
                checked={c.shortenLinks}
                onChange={c.setShortenLinks}
              />
              <div style={{ marginTop: 8, fontSize: 11.5, color: "var(--ink-on-paper-3)", lineHeight: 1.5 }}>
                {t("post.composer.shortenLinksHint", "URLs in your caption and comments are replaced with short ViewsMax links when the post is saved, so every click gets counted.")}
              </div>
            </Card>

            <Card>
              <SectionLabel>{t("post.composer.whenToPublish", "When to publish")}</SectionLabel>
              <div style={{ display: "inline-flex", background: "var(--paper-2)", borderRadius: 999, padding: 4, gap: 4, marginBottom: c.mode === "schedule" ? 14 : 0 }}>
                {([["now", t("post.composer.postNow", "Post now")], ["schedule", t("post.composer.schedule", "Schedule")]] as const).map(([m, l]) => (
                  <button key={m} onClick={() => c.setMode(m)} style={{ border: "none", cursor: "pointer", padding: "8px 18px", borderRadius: 999, fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 13, background: c.mode === m ? "var(--paper-0)" : "transparent", color: c.mode === m ? "var(--ink-on-paper-1)" : "var(--ink-on-paper-3)", boxShadow: c.mode === m ? "0 1px 3px rgba(10,10,12,.12)" : "none" }}>{l}</button>
                ))}
              </div>
              {c.mode === "schedule" && (
                <div style={{ display: "flex", gap: 12, alignItems: "flex-end", flexWrap: "wrap" }}>
                  <label style={{ display: "flex", flexDirection: "column", gap: 6 }}>
                    <span style={{ fontSize: 11.5, fontWeight: 700, color: "var(--ink-on-paper-3)" }}>{t("post.composer.date", "Date")}</span>
                    <input type="date" value={c.date} onChange={(e) => c.setDate(e.target.value)} style={{ ...fieldBase, width: 170, maxWidth: "100%", fontFamily: "var(--font-mono)", fontSize: 13 }} />
                  </label>
                  <label style={{ display: "flex", flexDirection: "column", gap: 6 }}>
                    <span style={{ fontSize: 11.5, fontWeight: 700, color: "var(--ink-on-paper-3)" }}>{t("post.composer.time", "Time")}</span>
                    <input type="time" value={c.time} onChange={(e) => c.setTime(e.target.value)} style={{ ...fieldBase, width: 130, maxWidth: "100%", fontFamily: "var(--font-mono)", fontSize: 13 }} />
                  </label>
                  <span style={{ display: "inline-flex", alignItems: "center", gap: 6, padding: "8px 12px", background: "var(--vm-volt-tint-l)", borderRadius: 10, fontSize: 12, fontWeight: 600, color: "var(--vm-volt-deep)" }}>
                    <Icon name="trending-up" size={14} stroke="var(--vm-volt-deep)" /> {t("post.composer.peakAudience", "Peak audience 6–8pm")}
                  </span>
                </div>
              )}
            </Card>
          </div>

          {/* ============ PREVIEW ============ */}
          <div className="cp-side" style={{ minWidth: 0, position: "sticky", top: 18, display: "flex", flexDirection: "column", gap: 14 }}>
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
                  {previewId === "youtube" && c.youtubeTitleMissing && (
                    <div style={{ display: "flex", alignItems: "center", gap: 8, background: "var(--vm-red-tint-l)", border: "1px solid rgba(255,31,61,.35)", color: "var(--vm-red-deep)", borderRadius: 12, padding: "9px 11px", marginBottom: 10, fontSize: 12, fontWeight: 600 }}>
                      <Icon name="alert-triangle" size={15} stroke="var(--vm-red)" />
                      {youtubeTitleMsg(t)}
                    </div>
                  )}
                  {c.selected.includes(previewId) && c.status[previewId].over && (
                    <div style={{ display: "flex", alignItems: "center", gap: 8, background: "var(--vm-red-tint-l)", border: "1px solid rgba(255,31,61,.35)", color: "var(--vm-red-deep)", borderRadius: 12, padding: "9px 11px", marginBottom: 10, fontSize: 12, fontWeight: 600 }}>
                      <Icon name="alert-triangle" size={15} stroke="var(--vm-red)" />
                      {c.status[previewId].len > PMAP[previewId].limit
                        ? t("post.composer.captionOverLimit", "Caption is {{over}} over {{platform}}'s {{limit}} limit", { over: c.status[previewId].len - PMAP[previewId].limit, platform: PMAP[previewId].name, limit: PMAP[previewId].limit })
                        : t("post.composer.fixXThread", "Fix the X thread — a tweet is empty or the thread is too long")}
                    </div>
                  )}
                  <PostPreview
                    id={previewId}
                    text={c.textFor(previewId)}
                    media={c.media}
                    handle={previewId === "tiktok"
                      ? (c.creatorInfo?.creator_username ? `@${c.creatorInfo.creator_username}` : c.creatorInfo?.creator_nickname) || undefined
                      : undefined}
                  />
                </div>
              </div>
            </div>

            {/* action footer */}
            <Card style={{ padding: 16 }}>
              {done ? (
                <div style={{ display: "flex", alignItems: "center", gap: 10, color: "var(--up)", fontWeight: 700, fontSize: 14, justifyContent: "center", padding: 4 }}>
                  <Icon name="check-circle-2" size={20} stroke="var(--up)" /> {isEditing
                    ? t("post.composer.changesSaved", "Changes saved")
                    : done === "now"
                      ? t("post.composer.postedToCount", { count: c.selected.length, defaultValue_one: "Posted to {{count}} platform", defaultValue_other: "Posted to {{count}} platforms" })
                      : t("post.composer.scheduledForCount", { count: c.selected.length, defaultValue_one: "Scheduled for {{count}} platform", defaultValue_other: "Scheduled for {{count}} platforms" })}
                </div>
              ) : (
                <>
                  {uploadingMedia ? (
                    <div style={{ display: "flex", alignItems: "center", gap: 7, marginBottom: 12, fontSize: 12.5, fontWeight: 600, color: "var(--ink-on-paper-2)" }}>
                      <Icon name="rotate-cw" size={15} stroke="var(--ink-on-paper-3)" />
                      {uploadingMedia.type === "video"
                        ? t("post.composer.uploadingVideoProgress", "Uploading video… {{progress}}%", { progress: uploadingMedia.progress ?? 0 })
                        : t("post.composer.uploadingImageProgress", "Uploading image… {{progress}}%", { progress: uploadingMedia.progress ?? 0 })}
                    </div>
                  ) : failedMedia.length > 0 ? (
                    <div style={{ display: "flex", alignItems: "center", gap: 7, marginBottom: 12, fontSize: 12.5, fontWeight: 600, color: "var(--vm-red)" }}>
                      <Icon name="alert-circle" size={15} stroke="var(--vm-red)" />
                      {t("post.composer.mediaUploadFailed", "Media upload failed — retry or remove it above before posting")}
                    </div>
                  ) : c.issues.length > 0 && (
                    <div style={{ display: "flex", alignItems: "center", gap: 7, marginBottom: 12, fontSize: 12.5, fontWeight: 600, color: "var(--vm-red)" }}>
                      <Icon name="alert-circle" size={15} stroke="var(--vm-red)" />
                      {t("post.composer.platformsNeedAttention", { count: c.issues.length, defaultValue_one: "{{count}} platform needs attention", defaultValue_other: "{{count}} platforms need attention" })}
                    </div>
                  )}
                  {/* TikTok requires this declaration immediately before the
                      publish button; the wording changes for branded content. */}
                  {tiktokSelected && (
                    <div style={{ marginBottom: 12, fontSize: 11.5, color: "var(--ink-on-paper-3)", lineHeight: 1.5 }}>
                      {c.tiktok.branded_content ? (
                        <Trans
                          i18nKey="post.composer.tiktokDeclarationBranded"
                          defaults="By posting, you agree to TikTok's <0>Branded Content Policy</0> and <1>Music Usage Confirmation</1>."
                          components={[
                            <a key="bc" href="https://www.tiktok.com/legal/page/global/bc-policy/en" target="_blank" rel="noopener noreferrer" style={policyLink} />,
                            <a key="music" href="https://www.tiktok.com/legal/page/global/music-usage-confirmation/en" target="_blank" rel="noopener noreferrer" style={policyLink} />,
                          ]}
                        />
                      ) : (
                        <Trans
                          i18nKey="post.composer.tiktokDeclaration"
                          defaults="By posting, you agree to TikTok's <0>Music Usage Confirmation</0>."
                          components={[
                            <a key="music" href="https://www.tiktok.com/legal/page/global/music-usage-confirmation/en" target="_blank" rel="noopener noreferrer" style={policyLink} />,
                          ]}
                        />
                      )}
                    </div>
                  )}
                  {c.youtubeTitleMissing && (
                    <div style={{ display: "flex", alignItems: "center", gap: 7, marginBottom: 12, fontSize: 12.5, fontWeight: 600, color: "var(--vm-red)" }}>
                      <Icon name="alert-circle" size={15} stroke="var(--vm-red)" /> {youtubeTitleMsg(t)}
                    </div>
                  )}
                  {blockReason && (
                    <div style={{ display: "flex", alignItems: "center", gap: 7, marginBottom: 12, fontSize: 12.5, fontWeight: 600, color: "var(--vm-red)" }}>
                      <Icon name="alert-circle" size={15} stroke="var(--vm-red)" /> {blockReason}
                    </div>
                  )}
                  <div className="cp-actions" style={{ display: "flex", gap: 10 }}>
                    <Btn kind="ghost" onClick={saving ? undefined : () => persist("draft")}>{t("post.composer.saveDraft", "Save draft")}</Btn>
                    <div title={blockReason ?? undefined} style={{ flex: 1, opacity: uploadingMedia || failedMedia.length > 0 || c.issues.length > 0 || c.youtubeTitleMissing || blockReason ? 0.55 : 1 }}>
                      <Btn kind={c.issues.length || c.youtubeTitleMissing || uploadingMedia || failedMedia.length || blockReason ? "dark" : "primary"} full icon={uploadingMedia ? "rotate-cw" : c.mode === "now" ? "send" : "calendar-clock"} onClick={saving || blockReason ? undefined : () => persist(c.mode === "now" ? "posted" : "scheduled")}>
                        {saving ? t("post.composer.saving", "Saving…") : uploadingMedia ? t("post.composer.uploadingProgress", "Uploading… {{progress}}%", { progress: uploadingMedia.progress ?? 0 }) : c.mode === "now" ? t("post.composer.postNow", "Post now") : isEditing ? t("post.composer.saveChanges", "Save changes") : t("post.composer.schedulePost", "Schedule post")}
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
