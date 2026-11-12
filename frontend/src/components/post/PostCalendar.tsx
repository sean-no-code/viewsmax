// Content calendar — month grid of scheduled / posted social content. Each
// entry shows the post's type, thumbnail, time and platforms; hovering (or
// tapping) it opens a native-post mockup per platform with edit, reschedule
// and delete. Ported from the "Content Calendar" design.
import { useCallback, useEffect, useMemo, useRef, useState, type CSSProperties } from "react";
import { useTranslation } from "react-i18next";
import type { TFunction } from "i18next";
import { Check, ChevronLeft, ChevronRight, Clock, ExternalLink, Image as ImageIcon, Images, Trash2, Type, Video, type LucideIcon } from "lucide-react";
import { Btn } from "@/components/analytics/primitives";
import { PMAP } from "@/components/post/composer";
import { BrandIcon, hasBrandIcon } from "@/components/post/brand-icons";
import { publishedUrl } from "@/components/post/PostList";
import { useAccountDirectory } from "@/components/post/useAccountDirectory";
import { resolveMediaUrl, type Post, type PostTarget } from "@/lib/api-service";

// Month and weekday names come from the browser in the UI language
// (English output is unchanged: "October 2026", "Mon".."Sun").
const monthYear = (d: Date, lang: string) => new Intl.DateTimeFormat(lang, { month: "long", year: "numeric" }).format(d);
const weekdayNames = (lang: string) => {
  const fmt = new Intl.DateTimeFormat(lang, { weekday: "short" });
  return Array.from({ length: 7 }, (_, i) => fmt.format(new Date(2024, 0, 1 + i))); // 2024-01-01 is a Monday
};

const dayKey = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;

const parseWhen = (p: Post): Date | null => {
  if (!p.scheduled_at) return null;
  const d = new Date(p.scheduled_at.replace(" ", "T"));
  return Number.isNaN(d.getTime()) ? null : d;
};

// The value a datetime-local input expects, in local time.
const toLocalInput = (d: Date) => {
  const z = (n: number) => String(n).padStart(2, "0");
  return `${d.getFullYear()}-${z(d.getMonth() + 1)}-${z(d.getDate())}T${z(d.getHours())}:${z(d.getMinutes())}`;
};

/* ---------------- post type (from its media) ---------------- */
type PostType = "video" | "image" | "carousel" | "text";

const TYPES: Record<PostType, { icon: LucideIcon; bg: string }> = {
  video: { icon: Video, bg: "var(--vm-red)" },
  image: { icon: ImageIcon, bg: "var(--data-violet)" },
  carousel: { icon: Images, bg: "var(--data-amber)" },
  text: { icon: Type, bg: "var(--data-blue)" },
};

const typeLabel = (t: TFunction, type: PostType) => ({
  video: t("post.calendar.typeVideo", "Video"),
  image: t("post.calendar.typeImage", "Image"),
  carousel: t("post.calendar.typeCarousel", "Carousel"),
  text: t("post.calendar.typeText", "Text"),
})[type];

const postType = (p: Post): PostType => {
  const media = p.media || [];
  if (media.length === 0) return "text";
  if (media.some((m) => m.type === "video")) return "video";
  return media.length > 1 ? "carousel" : "image";
};

// First image to show as the post's picture; videos have no stored still.
const imageUrl = (p: Post): string | undefined => resolveMediaUrl((p.media || []).find((m) => m.type === "image" && m.url)?.url);
const videoUrl = (p: Post): string | undefined => resolveMediaUrl((p.media || []).find((m) => m.type === "video" && m.url)?.url);

function TypeBadge({ type, size, style }: { type: PostType; size: number; style?: CSSProperties }) {
  const { icon: Cmp, bg } = TYPES[type];
  return (
    <span style={{ width: size, height: size, borderRadius: Math.round(size * 0.32), background: bg, display: "grid", placeItems: "center", flex: "none", ...style }}>
      <Cmp size={Math.round(size * 0.6)} color="#fff" strokeWidth={2.2} />
    </span>
  );
}

/* ---------------- platform tile (rounded square, brand colour) ---------------- */
const TILE_BG: Record<string, string> = {
  instagram: "linear-gradient(45deg,#FEDA75 0%,#FA7E1E 25%,#D62976 50%,#962FBF 75%,#4F5BD5 100%)",
};

// Native media shape per platform, for the preview mockup.
const RATIO: Record<string, string> = {
  instagram: "4/5", tiktok: "3/4", youtube: "16/9", linkedin: "1/1", x: "16/9",
  facebook: "1/1", threads: "1/1", bluesky: "16/9", pinterest: "3/4",
};

export function PlatformTile({ id, size, overlap, ring = true }: { id: string; size: number; overlap?: boolean; ring?: boolean }) {
  const p = PMAP[id];
  if (!p) return null;
  return (
    <span style={{
      width: size, height: size, borderRadius: Math.round(size * 0.3), background: TILE_BG[id] || p.accent,
      display: "grid", placeItems: "center", flex: "none", marginLeft: overlap ? -Math.round(size * 0.25) : 0,
      boxShadow: ring ? "0 0 0 1.5px var(--paper-0)" : "none", color: "#fff",
      fontFamily: "var(--font-display)", fontWeight: 800, fontSize: size * 0.4, lineHeight: 1,
    }}>
      {hasBrandIcon(id) ? <BrandIcon platform={id} size={Math.round(size * 0.58)} color="#fff" /> : p.glyph}
    </span>
  );
}

const statusChip = (status: Post["status"]): CSSProperties => ({
  flex: "none", padding: "3px 9px", borderRadius: 999, fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 11.5,
  background: status === "posted" ? "var(--vm-volt-tint-l)" : status === "draft" ? "var(--paper-2)" : "var(--vm-red-tint-l)",
  color: status === "posted" ? "#056B5B" : status === "draft" ? "var(--ink-on-paper-2)" : "var(--vm-red-deep)",
});

const statusLabel = (t: TFunction, status: Post["status"]) =>
  status === "posted" ? t("post.calendar.statusPosted", "Posted")
  : status === "draft" ? t("post.calendar.statusDraft", "Draft")
  : t("post.calendar.statusScheduled", "Scheduled");

const iconBtn: CSSProperties = {
  width: 34, height: 34, borderRadius: 999, border: "1px solid var(--line-1)", background: "var(--paper-0)",
  cursor: "pointer", display: "grid", placeItems: "center", flex: "none",
};

const POP_W = 320;
const POP_H = 560;

type Hover = { id: number; left: number; top: number };

export default function PostCalendar({ posts, onSelect, onReschedule, onDelete }: {
  posts: Post[];
  /** Edit a draft / scheduled post. */
  onSelect?: (p: Post) => void;
  /** Move a post to a new time; resolve true when it saved. */
  onReschedule?: (p: Post, when: Date) => Promise<boolean>;
  onDelete?: (p: Post) => void;
}) {
  const { t, i18n } = useTranslation();
  const lang = i18n.resolvedLanguage || "en";
  const today = new Date();
  const [cursor, setCursor] = useState(new Date(today.getFullYear(), today.getMonth(), 1));
  const [hover, setHover] = useState<Hover | null>(null);
  const closeTimer = useRef<ReturnType<typeof setTimeout>>();

  const byDay = useMemo(() => {
    const m = new Map<string, Post[]>();
    for (const p of posts) {
      const d = parseWhen(p);
      if (!d) continue;
      const k = dayKey(d);
      if (!m.has(k)) m.set(k, []);
      m.get(k)!.push(p);
    }
    for (const list of m.values()) list.sort((a, b) => parseWhen(a)!.getTime() - parseWhen(b)!.getTime());
    return m;
  }, [posts]);

  // Build a 6-week grid starting Monday.
  const cells = useMemo(() => {
    const first = new Date(cursor.getFullYear(), cursor.getMonth(), 1);
    const offset = (first.getDay() + 6) % 7; // Mon = 0
    const start = new Date(first);
    start.setDate(first.getDate() - offset);
    return Array.from({ length: 42 }, (_, i) => {
      const d = new Date(start);
      d.setDate(start.getDate() + i);
      return d;
    });
  }, [cursor]);

  const move = (delta: number) => { setHover(null); setCursor(new Date(cursor.getFullYear(), cursor.getMonth() + delta, 1)); };

  const keepOpen = useCallback(() => clearTimeout(closeTimer.current), []);
  const closeSoon = useCallback(() => {
    clearTimeout(closeTimer.current);
    closeTimer.current = setTimeout(() => setHover(null), 140);
  }, []);

  // Place the popover beside the entry, flipping left / clamping to the viewport.
  const open = (p: Post, el: HTMLElement) => {
    clearTimeout(closeTimer.current);
    const r = el.getBoundingClientRect();
    let left = r.right + 10;
    if (left + POP_W > window.innerWidth - 12) left = r.left - POP_W - 10;
    if (left < 12) left = Math.max(12, Math.min(r.left, window.innerWidth - POP_W - 12));
    const top = Math.max(12, Math.min(r.top - 24, window.innerHeight - POP_H - 12));
    setHover((h) => (h && h.id === p.id ? h : { id: p.id, left, top }));
  };

  // The popover is fixed-position, so it would drift off its entry on scroll.
  useEffect(() => {
    if (!hover) return;
    const close = () => setHover(null);
    const onKey = (e: KeyboardEvent) => { if (e.key === "Escape") close(); };
    window.addEventListener("scroll", close, true);
    window.addEventListener("keydown", onKey);
    return () => { window.removeEventListener("scroll", close, true); window.removeEventListener("keydown", onKey); };
  }, [hover]);

  useEffect(() => () => clearTimeout(closeTimer.current), []);

  const hovered = hover ? posts.find((p) => p.id === hover.id) : undefined;

  return (
    <div style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 18, overflow: "hidden", boxShadow: "0 1px 2px rgba(10,10,12,.04)" }}>
      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 16, flexWrap: "wrap", padding: "16px 20px", borderBottom: "1px solid var(--line-1)" }}>
        <div style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 20, letterSpacing: "-.02em", color: "var(--ink-on-paper-1)" }}>
          {monthYear(cursor, lang)}
        </div>
        <div style={{ display: "flex", alignItems: "center", gap: 18, flexWrap: "wrap" }}>
          <div style={{ display: "flex", alignItems: "center", gap: 14, flexWrap: "wrap" }}>
            {(Object.keys(TYPES) as PostType[]).map((type) => (
              <span key={type} style={{ display: "inline-flex", alignItems: "center", gap: 6, fontFamily: "var(--font-body)", fontSize: 12, fontWeight: 600, color: "var(--ink-on-paper-2)" }}>
                <TypeBadge type={type} size={20} />
                {typeLabel(t, type)}
              </span>
            ))}
          </div>
          <div style={{ display: "flex", gap: 8 }}>
            {([[ChevronLeft, -1, t("post.calendar.prevMonth", "Previous month")], [ChevronRight, 1, t("post.calendar.nextMonth", "Next month")]] as const).map(([Cmp, d, label]) => (
              <button key={d} onClick={() => move(d)} aria-label={label} style={iconBtn}>
                <Cmp size={17} color="var(--ink-on-paper-2)" />
              </button>
            ))}
          </div>
        </div>
      </div>

      {/* Narrow screens scroll the grid sideways rather than crushing the entries.
          width 0 + minWidth 100% keeps the grid's width from widening the page. */}
      <div style={{ overflowX: "auto", width: 0, minWidth: "100%" }}>
        <div style={{ minWidth: 980 }}>
          <div style={{ display: "grid", gridTemplateColumns: "repeat(7,minmax(0,1fr))", borderBottom: "1px solid var(--line-1)" }}>
            {weekdayNames(lang).map((d) => (
              <div key={d} style={{ padding: "10px 12px", fontFamily: "var(--font-mono)", fontSize: 10.5, letterSpacing: ".06em", textTransform: "uppercase", color: "var(--ink-on-paper-3)", fontWeight: 600 }}>{d}</div>
            ))}
          </div>
          <div style={{ display: "grid", gridTemplateColumns: "repeat(7,minmax(0,1fr))" }}>
            {cells.map((d, i) => {
              const inMonth = d.getMonth() === cursor.getMonth();
              const isToday = dayKey(d) === dayKey(today);
              const dayPosts = byDay.get(dayKey(d)) || [];
              return (
                <div key={i} style={{ minHeight: 128, padding: 8, borderRight: (i % 7 !== 6) ? "1px solid var(--paper-2)" : "none", borderBottom: i < 35 ? "1px solid var(--paper-2)" : "none", background: inMonth ? "var(--paper-0)" : "var(--paper-1)", display: "flex", flexDirection: "column", gap: 6, minWidth: 0 }}>
                  <span style={{ alignSelf: "flex-start", fontFamily: "var(--font-mono)", fontSize: 11.5, fontWeight: 700, width: 22, height: 22, borderRadius: "50%", display: "grid", placeItems: "center", color: isToday ? "#fff" : inMonth ? "var(--ink-on-paper-2)" : "var(--ink-on-paper-3)", background: isToday ? "var(--vm-red)" : "transparent" }}>{d.getDate()}</span>
                  {dayPosts.map((p) => (
                    <CalendarEntry key={p.id} post={p} active={hover?.id === p.id} onOpen={open} onLeave={closeSoon} />
                  ))}
                </div>
              );
            })}
          </div>
        </div>
      </div>

      {hover && hovered && (
        <div onMouseEnter={keepOpen} onMouseLeave={closeSoon} style={{ position: "fixed", left: hover.left, top: hover.top, zIndex: 50 }}>
          <NativePreview key={hovered.id} post={hovered} onSelect={onSelect} onReschedule={onReschedule} onDelete={onDelete} onDone={() => setHover(null)} />
        </div>
      )}
    </div>
  );
}

/* ---------------- one post in a day cell ---------------- */
function CalendarEntry({ post, active, onOpen, onLeave }: {
  post: Post; active: boolean; onOpen: (p: Post, el: HTMLElement) => void; onLeave: () => void;
}) {
  const { t } = useTranslation();
  const [hovering, setHovering] = useState(false);
  const type = postType(post);
  const img = imageUrl(post);
  const posted = post.status === "posted";
  const targets = (post.targets || []).filter((tg) => PMAP[tg.platform]);
  const when = parseWhen(post);

  return (
    <button
      onMouseEnter={(e) => { setHovering(true); onOpen(post, e.currentTarget); }}
      onMouseLeave={() => { setHovering(false); onLeave(); }}
      onFocus={(e) => onOpen(post, e.currentTarget)}
      onBlur={onLeave}
      onClick={(e) => onOpen(post, e.currentTarget)}
      title={post.caption || t("post.calendar.postFallbackTitle", "Post")}
      style={{
        textAlign: "left", width: "100%", boxSizing: "border-box", cursor: "pointer", background: "var(--paper-0)",
        border: "1px solid " + (active || hovering ? "var(--ink-on-paper-1)" : "var(--line-1)"), borderRadius: 10,
        padding: "8px 8px 6px 8px", display: "flex", alignItems: "center", gap: 9, minWidth: 0,
        boxShadow: hovering ? "0 4px 14px rgba(10,10,12,.10)" : "none",
        transition: "box-shadow .12s, border-color .12s", opacity: posted ? 0.92 : 1,
      }}
    >
      <span style={{ position: "relative", flex: "none", width: 34, height: 34 }}>
        {type === "text" ? (
          <span style={{ width: 34, height: 34, borderRadius: 8, background: "var(--paper-2)", display: "grid", placeItems: "center", fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 13, color: "var(--ink-on-paper-2)" }}>Aa</span>
        ) : (
          <span style={{ width: 34, height: 34, borderRadius: 8, display: "block", background: img ? `var(--paper-2) url("${img}") center/cover` : "var(--ink-900)" }} />
        )}
        <TypeBadge type={type} size={17} style={{ position: "absolute", left: -4, top: -4, boxShadow: "0 0 0 2px var(--paper-0)" }} />
      </span>
      <span style={{ flex: 1, minWidth: 0, display: "flex", flexDirection: "column", gap: 3 }}>
        <span style={{ display: "flex", alignItems: "center", gap: 4, fontFamily: "var(--font-mono)", fontSize: 11, fontWeight: 700, color: "var(--ink-on-paper-1)", lineHeight: 1, whiteSpace: "nowrap" }}>
          <span>{when ? when.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" }) : ""}</span>
          {posted && (
            <span title={t("post.calendar.statusPosted", "Posted")} style={{ width: 12, height: 12, borderRadius: "50%", background: "var(--vm-volt-deep)", display: "grid", placeItems: "center" }}>
              <Check size={9} color="#fff" strokeWidth={3} />
            </span>
          )}
        </span>
        <span style={{ display: "flex", alignItems: "center" }}>
          {targets.slice(0, 3).map((tg, i) => <PlatformTile key={tg.id} id={tg.platform} size={18} overlap={i > 0} />)}
          {targets.length > 3 && (
            <span style={{ marginLeft: 4, fontFamily: "var(--font-mono)", fontSize: 10, fontWeight: 700, color: "var(--ink-on-paper-3)" }}>+{targets.length - 3}</span>
          )}
        </span>
      </span>
    </button>
  );
}

/* ---------------- native post mockup (the hover popover) ---------------- */
function NativePreview({ post, onSelect, onReschedule, onDelete, onDone }: {
  post: Post;
  onSelect?: (p: Post) => void;
  onReschedule?: (p: Post, when: Date) => Promise<boolean>;
  onDelete?: (p: Post) => void;
  onDone: () => void;
}) {
  const { t, i18n } = useTranslation();
  const lang = i18n.resolvedLanguage || "en";
  const { directory } = useAccountDirectory();
  const targets = (post.targets || []).filter((tg) => PMAP[tg.platform]);
  const [tab, setTab] = useState(0);
  const [rescheduling, setRescheduling] = useState(false);
  const [newWhen, setNewWhen] = useState("");
  const [saving, setSaving] = useState(false);
  const [confirmDelete, setConfirmDelete] = useState(false);

  const active: PostTarget | undefined = targets[Math.min(tab, targets.length - 1)];
  const platform = active?.platform;
  const type = postType(post);
  const img = imageUrl(post);
  const vid = videoUrl(post);
  const when = parseWhen(post);
  const posted = post.status === "posted";
  const caption = ((active?.caption_override || "").trim() || post.caption || "").trim();

  // The account this platform posts through, falling back to the user's newest one.
  const account = active?.social_account;
  const dirEntry = platform ? directory[platform] : undefined;
  const accountName = account?.username ? `@${account.username}` : account?.name || dirEntry?.name || (platform ? PMAP[platform].name : "");
  const avatar = account?.avatar_url || dirEntry?.avatarUrl || null;
  const initials = accountName.replace(/^@/, "").split(/[\s._-]+/).filter(Boolean).slice(0, 2).map((w) => w[0]!.toUpperCase()).join("") || "?";

  const whenLabel = when
    ? new Intl.DateTimeFormat(lang, { weekday: "short", day: "numeric", month: "short" }).format(when) + " · " + when.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })
    : "";
  const liveUrl = posted && active ? publishedUrl(active) : null;

  const startReschedule = () => {
    setNewWhen(toLocalInput(when && when > new Date() ? when : new Date(Date.now() + 3600_000)));
    setRescheduling(true);
  };

  const saveReschedule = async () => {
    const d = new Date(newWhen);
    if (!onReschedule || Number.isNaN(d.getTime())) return;
    setSaving(true);
    const ok = await onReschedule(post, d);
    setSaving(false);
    if (ok) onDone();
  };

  return (
    <div style={{ width: POP_W, background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 18, overflow: "hidden", boxShadow: "0 18px 48px rgba(10,10,12,.22),0 2px 6px rgba(10,10,12,.08)", animation: "vmPop .14s ease-out" }}>
      <style>{"@keyframes vmPop{from{opacity:0;transform:translateY(4px) scale(.98)}to{opacity:1;transform:none}}"}</style>

      {/* One tab per platform this post goes to. */}
      <div style={{ display: "flex", alignItems: "center", gap: 6, padding: "10px 12px", background: "var(--paper-1)", borderBottom: "1px solid var(--line-1)" }}>
        {targets.map((tg, i) => (
          <button key={tg.id} onClick={() => setTab(i)} title={PMAP[tg.platform].name} aria-pressed={tg === active}
            style={{ border: "none", background: "none", padding: 3, borderRadius: 10, cursor: "pointer", display: "grid", outline: tg === active ? "2px solid var(--ink-on-paper-1)" : "none", opacity: tg === active ? 1 : 0.45 }}>
            <PlatformTile id={tg.platform} size={24} ring={false} />
          </button>
        ))}
        <span style={{ flex: 1 }} />
        {platform && (
          <span style={{ fontFamily: "var(--font-mono)", fontSize: 10.5, fontWeight: 600, letterSpacing: ".04em", textTransform: "uppercase", color: "var(--ink-on-paper-3)" }}>
            {t("post.calendar.preview", "{{platform}} preview", { platform: PMAP[platform].name })}
          </span>
        )}
      </div>

      <div style={{ display: "flex", alignItems: "center", gap: 9, padding: "11px 14px" }}>
        <span style={{ width: 30, height: 30, borderRadius: "50%", overflow: "hidden", background: "var(--ink-900)", color: "#fff", display: "grid", placeItems: "center", fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 12, flex: "none" }}>
          {avatar ? <img src={avatar} alt="" style={{ width: "100%", height: "100%", objectFit: "cover", display: "block" }} /> : initials}
        </span>
        <span style={{ display: "flex", flexDirection: "column", lineHeight: 1.2, minWidth: 0 }}>
          <span style={{ fontWeight: 700, fontSize: 13, color: "var(--ink-on-paper-1)", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>{accountName}</span>
          <span style={{ display: "inline-flex", alignItems: "center", gap: 4, fontFamily: "var(--font-mono)", fontSize: 10.5, color: "var(--ink-on-paper-3)" }}>
            <Clock size={11} color="var(--ink-on-paper-3)" />{whenLabel}
          </span>
        </span>
        <span style={{ flex: 1 }} />
        <span style={statusChip(post.status)}>{statusLabel(t, post.status)}</span>
      </div>

      {type !== "text" && (
        <div style={{ position: "relative", aspectRatio: RATIO[platform || ""] || "1/1", maxHeight: 300, width: "100%", background: "var(--paper-2)", overflow: "hidden" }}>
          {img ? (
            <span style={{ width: "100%", height: "100%", display: "block", background: `var(--paper-2) url("${img}") center/cover` }} />
          ) : vid ? (
            <video src={vid} muted playsInline preload="metadata" style={{ width: "100%", height: "100%", objectFit: "cover", display: "block", background: "var(--ink-900)" }} />
          ) : null}
          <TypeBadge type={type} size={28} style={{ position: "absolute", right: 10, top: 10, boxShadow: "0 2px 8px rgba(10,10,12,.35)" }} />
        </div>
      )}

      <div style={{ padding: "12px 14px 14px", display: "flex", flexDirection: "column", gap: 12 }}>
        <p style={{ margin: 0, fontFamily: "var(--font-body)", fontSize: 13.5, lineHeight: 1.5, color: "var(--ink-on-paper-1)", display: "-webkit-box", WebkitLineClamp: type === "text" ? 8 : 4, WebkitBoxOrient: "vertical", overflow: "hidden", textWrap: "pretty" } as CSSProperties}>
          <strong style={{ fontWeight: 700 }}>{accountName}</strong>{" "}
          {caption || <span style={{ color: "var(--ink-on-paper-3)" }}>{t("post.list.noCaption", "No caption")}</span>}
        </p>

        {posted ? (
          liveUrl && (
            <a href={liveUrl} target="_blank" rel="noreferrer" style={{ display: "inline-flex", alignItems: "center", gap: 6, alignSelf: "flex-start", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 12.5, color: "var(--vm-red)" }}>
              {t("post.calendar.viewOn", "View on {{platform}}", { platform: PMAP[platform!].name })}
              <ExternalLink size={13} />
            </a>
          )
        ) : rescheduling ? (
          <div style={{ display: "flex", flexDirection: "column", gap: 8 }}>
            <label style={{ fontFamily: "var(--font-mono)", fontSize: 10.5, fontWeight: 600, letterSpacing: ".04em", textTransform: "uppercase", color: "var(--ink-on-paper-3)" }}>
              {t("post.calendar.newTime", "New date and time")}
              <input type="datetime-local" value={newWhen} min={toLocalInput(new Date())} onChange={(e) => setNewWhen(e.target.value)}
                style={{ display: "block", width: "100%", boxSizing: "border-box", marginTop: 6, padding: "8px 10px", borderRadius: 10, border: "1px solid var(--line-1)", background: "var(--paper-0)", fontFamily: "var(--font-body)", fontSize: 13, color: "var(--ink-on-paper-1)" }} />
            </label>
            <div style={{ display: "flex", gap: 8 }}>
              <Btn size="sm" onClick={saving ? undefined : saveReschedule}>{saving ? t("post.calendar.saving", "Saving…") : t("post.calendar.save", "Save")}</Btn>
              <Btn size="sm" kind="ghost" onClick={() => setRescheduling(false)}>{t("post.calendar.cancel", "Cancel")}</Btn>
            </div>
          </div>
        ) : (
          <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
            {onSelect && <Btn size="sm" onClick={() => onSelect(post)}>{t("post.calendar.editPost", "Edit post")}</Btn>}
            {onReschedule && <Btn size="sm" kind="ghost" onClick={startReschedule}>{t("post.calendar.reschedule", "Reschedule")}</Btn>}
            <span style={{ flex: 1 }} />
            {onDelete && (
              <button
                onClick={() => (confirmDelete ? onDelete(post) : setConfirmDelete(true))}
                aria-label={confirmDelete ? t("post.calendar.confirmDelete", "Click again to delete") : t("post.calendar.delete", "Delete post")}
                title={confirmDelete ? t("post.calendar.confirmDelete", "Click again to delete") : t("post.calendar.delete", "Delete post")}
                style={{ ...iconBtn, background: confirmDelete ? "var(--vm-red)" : "var(--paper-0)", borderColor: confirmDelete ? "var(--vm-red)" : "var(--line-1)" }}
              >
                <Trash2 size={15} color={confirmDelete ? "#fff" : "var(--ink-on-paper-2)"} />
              </button>
            )}
          </div>
        )}
      </div>
    </div>
  );
}
