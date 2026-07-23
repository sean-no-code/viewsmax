// Create Post — shared model + atoms, ported from the ViewsMax design kit.
// Platform catalog (with real caption limits), composer state hook, avatars,
// media tiles, live per-platform feed preview, and the over-limit counter chip.
import { useMemo, useRef, useState, type CSSProperties, type DragEvent } from "react";
import { Icon } from "@/components/analytics/primitives";
import { BrandIcon, hasBrandIcon } from "@/components/post/brand-icons";
import { viewsMaxApi, resolveMediaUrl, type TikTokOptions, type TikTokCreatorInfo, type YouTubeOptions, type LinkedInOptions, type Post, type PostMediaItem } from "@/lib/api-service";
import { platformLength, splitXThread, xThreadStatus, xWeightedLength, X_LIMIT, X_MAX_IMAGES, type XThreadStatus } from "@/lib/text-metrics";

export interface Platform {
  id: string;
  name: string;
  glyph: string;
  handle: string;
  accent: string;
  limit: number;
  followers: string;
  kind: string;
}

export const PLATFORMS: Platform[] = [
  { id: "youtube", name: "YouTube", glyph: "YT", handle: "Creator Lab", accent: "#FF0000", limit: 5000, followers: "248K", kind: "Shorts" },
  { id: "tiktok", name: "TikTok", glyph: "TT", handle: "@creatorlab", accent: "#111114", limit: 2200, followers: "1.2M", kind: "Video" },
  { id: "instagram", name: "Instagram", glyph: "IG", handle: "@creator.lab", accent: "#E1306C", limit: 2200, followers: "96.4K", kind: "Reel / Post" },
  { id: "x", name: "X", glyph: "X", handle: "@creatorlab", accent: "#0A0A0C", limit: 280, followers: "54.1K", kind: "Post" },
  { id: "linkedin", name: "LinkedIn", glyph: "in", handle: "Creator Lab", accent: "#0A66C2", limit: 3000, followers: "12.3K", kind: "Post" },
  { id: "facebook", name: "Facebook", glyph: "f", handle: "Creator Lab", accent: "#1877F2", limit: 5000, followers: "38.0K", kind: "Post" },
  { id: "threads", name: "Threads", glyph: "@", handle: "@creator.lab", accent: "#0A0A0C", limit: 500, followers: "21.7K", kind: "Post" },
  { id: "bluesky", name: "Bluesky", glyph: "bs", handle: "@creator.bsky.social", accent: "#0085FF", limit: 300, followers: "8.2K", kind: "Post" },
];
export const PMAP: Record<string, Platform> = Object.fromEntries(PLATFORMS.map((p) => [p.id, p]));

// What each platform's API can actually publish in this codebase. Drives the
// Media/Text post-type selector: a "text" post only offers text-capable
// platforms; a "media" post offers image/video-capable ones. LinkedIn accepts
// text + image but NOT video (a video target is failed with a clear note by the
// backend — see LinkedInProvider).
export const PLATFORM_CAPS: Record<string, { text: boolean; image: boolean; video: boolean }> = {
  youtube: { text: false, image: false, video: true },
  tiktok: { text: false, image: true, video: true },
  instagram: { text: false, image: true, video: true },
  x: { text: true, image: true, video: false },
  linkedin: { text: true, image: true, video: false },
  facebook: { text: true, image: true, video: true },
  threads: { text: true, image: true, video: true },
  // Bluesky embeds up to 4 images; the API has no video path yet.
  bluesky: { text: true, image: true, video: false },
};
export type PostType = "media" | "text";
export function platformAllowedForType(id: string, postType: PostType): boolean {
  const caps = PLATFORM_CAPS[id];
  if (!caps) return false;
  return postType === "text" ? caps.text : caps.image || caps.video;
}

export const MEDIA_GRADIENTS = [
  "linear-gradient(135deg,#FF1F3D 0%,#7A0A18 100%)",
  "linear-gradient(150deg,#16161B 0%,#FF1F3D 120%)",
  "linear-gradient(135deg,#FF4D63 0%,#16E0C4 130%)",
  "linear-gradient(140deg,#FFB020 0%,#FF1F3D 110%)",
  "linear-gradient(150deg,#0A0A0C 0%,#2C2C37 100%)",
  "linear-gradient(135deg,#9B6BFF 0%,#FF1F3D 120%)",
];

export interface MediaItem {
  id: number;
  type: "image" | "video";
  g: string;            // gradient placeholder shown while uploading / as preview bg
  url?: string;         // public URL once uploaded
  path?: string;
  preview?: string;     // local objectURL — instant image/video preview before & during upload
  uploading?: boolean;
  progress?: number;    // upload progress 0–100
  error?: string;
  duration?: number;    // video length in seconds (read client-side after upload); used for TikTok's max-duration check
  file?: File;          // kept locally for retry; never sent to the backend
}
export interface PlatformStatus {
  len: number;
  limit: number;
  over: boolean;
  /** X only: per-segment thread breakdown when the caption contains `---`. */
  thread?: XThreadStatus;
}

let MID = 100;
function makeMedia(type: "image" | "video", extra: Partial<MediaItem> = {}): MediaItem {
  MID += 1;
  return { id: MID, type, g: MEDIA_GRADIENTS[MID % MEDIA_GRADIENTS.length], ...extra };
}

/* ---------------- Composer state hook ---------------- */
export function usePostComposer() {
  const [selected, setSelected] = useState<string[]>([]);
  // Multi-account platforms: which connected account ids publish per platform.
  // A platform with entries here is selected iff its list is non-empty. All
  // OAuth platforms (incl. youtube/tiktok now) can carry several accounts.
  const [accountSel, setAccountSel] = useState<Record<string, number[]>>({});
  const [postType, setPostType] = useState<PostType>("media");
  const [caption, setCaption] = useState("");
  const [overrides, setOverrides] = useState<Record<string, string>>({});
  const [media, setMedia] = useState<MediaItem[]>([]);
  // Follow-up comments posted after each supporting target publishes; each
  // waits delaySeconds after the previous message in the chain.
  const [comments, setComments] = useState<Array<{ body: string; delaySeconds: number }>>([]);
  // Swap URLs in caption/comments for tracked /l/ short links at save time.
  const [shortenLinks, setShortenLinks] = useState(false);
  const [mode, setMode] = useState<"now" | "schedule">("schedule");
  const [date, setDate] = useState("");
  const [time, setTime] = useState("18:30");

  // TikTok required publish options + creator info (drives allowed choices).
  // TikTok audit compliance: privacy has NO default (user must choose), and no
  // interaction is enabled by default (disable_* start true = "Allow X" off).
  const [tiktok, setTiktok] = useState<TikTokOptions>({
    privacy_level: "",
    disable_comment: true,
    disable_duet: true,
    disable_stitch: true,
    disclose_commercial: false,
    your_brand: false,
    branded_content: false,
    auto_add_music: false,
  });
  const patchTiktok = (p: Partial<TikTokOptions>) => setTiktok((t) => ({ ...t, ...p }));
  // Once the privacy level is set explicitly — by the user, or hydrated from a
  // saved post — we stop auto-defaulting it when creator-info loads.
  const privacyTouched = useRef(false);
  const setPrivacyLevel = (level: string) => {
    privacyTouched.current = true;
    patchTiktok({ privacy_level: level });
  };

  // YouTube publish options. Default to public to match the "Post now" intent.
  const [youtube, setYoutube] = useState<YouTubeOptions>({ privacy_status: "public" });
  const patchYoutube = (p: Partial<YouTubeOptions>) => setYoutube((y) => ({ ...y, ...p }));

  // LinkedIn publish options — a scheduled first comment (the usual spot for
  // links, which get reach-penalized in the post body).
  const [linkedin, setLinkedin] = useState<LinkedInOptions>({ first_comment: "" });
  const patchLinkedin = (p: Partial<LinkedInOptions>) => setLinkedin((l) => ({ ...l, ...p }));

  // Custom cover: one uploaded image drives the YouTube thumbnail + Instagram
  // Reel cover; TikTok only supports a cover-frame time (seconds into the video).
  const [cover, setCover] = useState<PostMediaItem | null>(null);
  const [coverUploading, setCoverUploading] = useState(false);
  const [coverError, setCoverError] = useState<string | null>(null);
  const [tiktokCoverSec, setTiktokCoverSec] = useState(0);

  const uploadCover = async (file: File) => {
    setCoverUploading(true);
    setCoverError(null);
    const res = await viewsMaxApi.uploadPostMedia(file, "image");
    setCoverUploading(false);
    if (res.success && res.data) setCover(res.data);
    else setCoverError(res.error || "Cover upload failed.");
    return res;
  };
  const removeCover = () => { setCover(null); setCoverError(null); };

  const [creatorInfo, setCreatorInfo] = useState<TikTokCreatorInfo | null>(null);
  const [creatorInfoError, setCreatorInfoError] = useState<string | null>(null);
  const [creatorInfoLoading, setCreatorInfoLoading] = useState(false);

  const loadCreatorInfo = async () => {
    setCreatorInfoLoading(true);
    setCreatorInfoError(null);
    const res = await viewsMaxApi.getTikTokCreatorInfo(accountSel["tiktok"]?.[0]);
    setCreatorInfoLoading(false);
    if (res.success && res.data) {
      setCreatorInfo(res.data);
      // TikTok audit compliance: NEVER auto-select a privacy level — the user
      // must choose one manually. Only clear a hydrated selection that this
      // account no longer permits, so the dropdown re-prompts.
      const opts = res.data.privacy_level_options || [];
      if (opts.length) {
        setTiktok((t) => (t.privacy_level && !opts.includes(t.privacy_level) ? { ...t, privacy_level: "" } : t));
      }
    } else {
      setCreatorInfoError(res.error || "Couldn't load TikTok options.");
    }
  };

  const toggle = (id: string) => {
    setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]));
    // Platform-level off wipes any per-account picks it had.
    setAccountSel((m) => (m[id]?.length ? { ...m, [id]: [] } : m));
  };
  // Toggle one specific account of a multi-account platform. The platform
  // stays selected while it has at least one account picked.
  const toggleAccount = (platform: string, accountId: number) => {
    setAccountSel((m) => {
      const cur = m[platform] ?? [];
      const next = cur.includes(accountId) ? cur.filter((i) => i !== accountId) : [...cur, accountId];
      setSelected((sel) => next.length > 0
        ? (sel.includes(platform) ? sel : [...sel, platform])
        : sel.filter((s) => s !== platform));
      return { ...m, [platform]: next };
    });
  };
  const selectAll = () => setSelected(PLATFORMS.map((p) => p.id));
  const textFor = (id: string) => (overrides[id] != null && overrides[id] !== "" ? overrides[id] : caption);
  const setOverride = (id: string, v: string) => setOverrides((o) => ({ ...o, [id]: v }));
  const clearOverride = (id: string) => setOverrides((o) => { const n = { ...o }; delete n[id]; return n; });

  // X thread segment editing. Users never touch the raw `---` format — the
  // segmented editor reads segments from the effective X text and writes them
  // back serialized into the X override (keeping the shared caption clean).
  const xSegments = () => {
    const s = splitXThread(textFor("x"));
    return s.length ? s : [""];
  };
  const writeXSegments = (segs: string[]) => setOverride("x", segs.join("\n---\n"));
  const setXSegment = (i: number, text: string) => {
    const s = xSegments();
    s[Math.min(i, s.length - 1)] = text;
    writeXSegments(s);
  };
  const addXSegment = (after: number) => {
    const s = xSegments();
    s.splice(Math.min(after, s.length - 1) + 1, 0, "");
    writeXSegments(s);
  };
  const removeXSegment = (i: number) => {
    const s = xSegments();
    if (s.length <= 1) return;
    s.splice(i, 1);
    writeXSegments(s);
  };

  const status = useMemo(() => {
    const m: Record<string, PlatformStatus> = {};
    for (const p of PLATFORMS) {
      const t = textFor(p.id);
      if (p.id === "x") {
        // X: URLs weigh 23 chars and `---` lines split the caption into a
        // thread — the chip shows the longest segment; any bad segment flags it.
        const thread = xThreadStatus(t);
        m[p.id] = { len: thread.maxLen, limit: p.limit, over: thread.invalid, thread };
      } else {
        const len = platformLength(p.id, t);
        m[p.id] = { len, limit: p.limit, over: len > p.limit };
      }
    }
    return m;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [caption, overrides]);

  const issues = selected.filter((id) => status[id].over);

  // Drive a single upload for an existing media item, streaming progress into
  // its state and recording the final url/path or an error.
  const runUpload = async (id: number, file: File, type: "image" | "video") => {
    setMedia((m) => m.map((x) => (x.id === id ? { ...x, uploading: true, error: undefined, progress: 0 } : x)));
    const res = await viewsMaxApi.uploadPostMedia(file, type, (pct) =>
      setMedia((m) => m.map((x) => (x.id === id ? { ...x, progress: pct } : x)))
    );
    setMedia((m) =>
      m.map((x) =>
        x.id === id
          ? res.success && res.data
            ? { ...x, uploading: false, progress: 100, url: res.data.url, path: res.data.path }
            : { ...x, uploading: false, error: res.error || "Upload failed" }
          : x
      )
    );
    return res;
  };

  // Upload a real file. A local objectURL gives an instant preview (image OR
  // video) while the bytes upload. Only one video is allowed per post
  // (TikTok/Reels/Shorts are single-video).
  const uploadMedia = async (file: File, type: "image" | "video") => {
    const preview = URL.createObjectURL(file);
    const item = makeMedia(type, { uploading: true, progress: 0, preview, file });
    setMedia((m) => {
      if (type !== "video") return [...m, item];
      // Replacing an existing video — free its preview URL first.
      m.filter((x) => x.type === "video" && x.preview).forEach((x) => URL.revokeObjectURL(x.preview!));
      return [...m.filter((x) => x.type !== "video"), item];
    });
    // Read the video's duration client-side so the composer can enforce
    // TikTok's max_video_post_duration_sec before publishing.
    if (type === "video") {
      const probe = document.createElement("video");
      probe.preload = "metadata";
      probe.onloadedmetadata = () => {
        const d = probe.duration;
        if (Number.isFinite(d)) setMedia((m) => m.map((x) => (x.id === item.id ? { ...x, duration: d } : x)));
      };
      probe.src = preview;
    }
    return runUpload(item.id, file, type);
  };

  // Re-run the upload for a failed item using the file we kept locally.
  const retryMedia = (id: number) => {
    const item = media.find((x) => x.id === id);
    if (!item?.file) return Promise.resolve({ success: false, error: "Nothing to retry — re-select the file." });
    return runUpload(id, item.file, item.type);
  };

  const removeMedia = (id: number) =>
    setMedia((m) => {
      const target = m.find((x) => x.id === id);
      if (target?.preview) URL.revokeObjectURL(target.preview);
      return m.filter((x) => x.id !== id);
    });
  // Reorder slideshow media by moving the item at `from` to index `to`. Order is
  // just array order, so a splice reshuffle is all that's needed (the tile index
  // badges and the preview carousel both read straight off this array).
  const reorderMedia = (from: number, to: number) =>
    setMedia((m) => {
      if (from === to || from < 0 || to < 0 || from >= m.length || to >= m.length) return m;
      const next = [...m];
      const [moved] = next.splice(from, 1);
      next.splice(to, 0, moved);
      return next;
    });
  const mediaUploading = media.some((m) => m.uploading);
  const mediaFailed = media.some((m) => m.error);

  // Seed the composer from an existing post so it can be edited. Mirrors how
  // persist() serialises state in CreatePost: targets -> selected platforms +
  // per-platform caption overrides + TikTok options; scheduled_at -> mode/date/time.
  const hydrate = (post: Post) => {
    const targets = post.targets ?? [];
    setSelected([...new Set(targets.map((t) => t.platform))]);
    const acct: Record<string, number[]> = {};
    for (const t of targets) {
      if (t.social_account_id) (acct[t.platform] ??= []).push(t.social_account_id);
    }
    setAccountSel(acct);
    setCaption(post.caption ?? "");
    setComments((post.comments ?? []).map((cm) => ({ body: cm.body, delaySeconds: cm.delay_seconds })));
    setShortenLinks(!!post.shorten_links);

    const ov: Record<string, string> = {};
    for (const t of targets) if (t.caption_override) ov[t.platform] = t.caption_override;
    setOverrides(ov);

    const seededMedia = post.media ?? [];
    setMedia(seededMedia.map((m) => makeMedia(m.type, { url: m.url, path: m.path, progress: 100 })));
    // A post with no media is a text post; anything with media is a media post.
    setPostType(seededMedia.length ? "media" : "text");

    if (post.status === "scheduled" && post.scheduled_at) {
      setMode("schedule");
      // scheduled_at comes back as a UTC ISO string; show it in the user's
      // LOCAL wall-clock so the composer matches the calendar and what they
      // originally typed (the write path stores UTC, see CreatePost.persist).
      const dt = new Date(post.scheduled_at);
      if (!Number.isNaN(dt.getTime())) {
        const p2 = (n: number) => String(n).padStart(2, "0");
        setDate(`${dt.getFullYear()}-${p2(dt.getMonth() + 1)}-${p2(dt.getDate())}`);
        setTime(`${p2(dt.getHours())}:${p2(dt.getMinutes())}`);
      }
    } else {
      setMode("now");
    }

    const tt = targets.find((t) => t.platform === "tiktok");
    if (tt?.options) {
      const opts = tt.options as Partial<TikTokOptions>;
      // A saved post already carries its chosen privacy — respect it.
      if (opts.privacy_level) privacyTouched.current = true;
      setTiktok((cur) => ({ ...cur, ...opts }));
    }

    const yt = targets.find((t) => t.platform === "youtube");
    if (yt?.options) setYoutube((cur) => ({ ...cur, ...(yt.options as Partial<YouTubeOptions>) }));

    const li = targets.find((t) => t.platform === "linkedin");
    if (li?.options) setLinkedin((cur) => ({ ...cur, ...(li.options as Partial<LinkedInOptions>) }));
    // Drafts saved before the generic comments system stored a LinkedIn-only
    // first_comment option — surface it as the first composed comment.
    const legacyFirst = (li?.options as Partial<LinkedInOptions> | undefined)?.first_comment?.trim();
    if (!(post.comments ?? []).length && legacyFirst) {
      setComments([{ body: legacyFirst, delaySeconds: 0 }]);
    }

    // Restore the custom cover: prefer the YouTube thumbnail object, fall back to
    // the Instagram cover_url. TikTok's cover-frame time is stored in ms.
    const ytThumb = (yt?.options as Record<string, unknown> | undefined)?.thumbnail as PostMediaItem | undefined;
    const igCover = (targets.find((t) => t.platform === "instagram")?.options as Record<string, unknown> | undefined)?.cover_url as string | undefined;
    if (ytThumb?.url) setCover(ytThumb);
    else if (igCover) setCover({ type: "image", url: igCover });

    const ttCoverMs = (tt?.options as Record<string, unknown> | undefined)?.video_cover_timestamp_ms as number | undefined;
    if (ttCoverMs) setTiktokCoverSec(Math.round(ttCoverMs / 1000));
  };

  return {
    selected, toggle, selectAll, setSelected,
    accountSel, toggleAccount, setAccountSel,
    postType, setPostType,
    caption, setCaption, overrides, setOverride, clearOverride, textFor,
    setXSegment, addXSegment, removeXSegment,
    media, uploadMedia, retryMedia, removeMedia, reorderMedia, mediaUploading, mediaFailed,
    comments, setComments,
    shortenLinks, setShortenLinks,
    mode, setMode, date, setDate, time, setTime,
    tiktok, patchTiktok, setPrivacyLevel, creatorInfo, creatorInfoError, creatorInfoLoading, loadCreatorInfo,
    youtube, patchYoutube,
    linkedin, patchLinkedin,
    cover, uploadCover, removeCover, coverUploading, coverError, tiktokCoverSec, setTiktokCoverSec,
    status, issues, hydrate,
  };
}
export type Composer = ReturnType<typeof usePostComposer>;

/* ---------------- Platform avatar ---------------- */
// `connect` renders the "not connected" state: muted avatar, red ring and a red
// "+" badge — a tap target that links to the connections screen.
// `avatarUrl` swaps the brand-glyph circle for the account's real profile photo
// with a small platform badge pinned bottom-right (badge only when size > 20 —
// smaller than that it's unreadable). Broken/missing images fall back to the glyph.
export function PAvatar({ id, size = 40, ring, dim, badge, connect, avatarUrl }: { id: string; size?: number; ring?: boolean; dim?: boolean; badge?: string | null; connect?: boolean; avatarUrl?: string | null }) {
  const p = PMAP[id];
  const [imgFailed, setImgFailed] = useState(false);
  if (!p) return null;
  const muted = dim || connect;
  const iconColor = muted ? "var(--ink-on-paper-3)" : "#fff";
  const showImg = !!avatarUrl && !imgFailed && !connect;
  const badgeSize = Math.max(14, Math.round(size * 0.44));
  return (
    <span style={{ position: "relative", display: "inline-flex", flexShrink: 0 }}>
      <span style={{
        width: size, height: size, borderRadius: "50%", display: "grid", placeItems: "center", overflow: "hidden",
        background: showImg ? "var(--paper-2)" : muted ? "var(--paper-2)" : p.accent, color: iconColor,
        fontFamily: "var(--font-display)", fontWeight: 800, fontSize: size * 0.4, lineHeight: 1, letterSpacing: "-0.02em",
        boxShadow: connect ? "0 0 0 2px var(--paper-0), 0 0 0 4px var(--vm-red)"
          : ring ? `0 0 0 2px var(--paper-0), 0 0 0 4px ${p.accent}` : "none",
        filter: muted ? "grayscale(1)" : "none", transition: "all var(--dur)",
      }}>
        {showImg
          ? <img src={avatarUrl!} alt="" onError={() => setImgFailed(true)} style={{ width: "100%", height: "100%", objectFit: "cover", display: "block" }} />
          : hasBrandIcon(id) ? <BrandIcon platform={id} size={Math.round(size * 0.52)} color={iconColor} /> : p.glyph}
      </span>
      {showImg && size > 20 && !connect && (
        <span style={{
          position: "absolute", bottom: -3, right: -3, width: badgeSize, height: badgeSize,
          borderRadius: "50%", background: muted ? "var(--paper-2)" : p.accent, border: "2px solid var(--paper-0)",
          display: "grid", placeItems: "center", filter: muted ? "grayscale(1)" : "none",
        }}>
          {hasBrandIcon(id)
            ? <BrandIcon platform={id} size={Math.max(8, Math.round(badgeSize * 0.58))} color={iconColor} />
            : <span style={{ color: iconColor, fontSize: badgeSize * 0.55, fontFamily: "var(--font-display)", fontWeight: 800 }}>{p.glyph}</span>}
        </span>
      )}
      {connect ? (
        <span style={{
          position: "absolute", bottom: -3, right: -3, width: 18, height: 18,
          borderRadius: 999, background: "var(--vm-red)", border: "2px solid var(--paper-0)",
          display: "grid", placeItems: "center",
        }}>
          <Icon name="plus" size={11} stroke="#fff" />
        </span>
      ) : badge != null ? (
        <span style={{
          position: "absolute", top: -3, right: -3, minWidth: 16, height: 16, padding: "0 4px",
          borderRadius: 999, background: "var(--vm-red)", color: "#fff", border: "2px solid var(--paper-0)",
          fontFamily: "var(--font-mono)", fontSize: 9, fontWeight: 700, display: "grid", placeItems: "center",
        }}>{badge}</span>
      ) : null}
    </span>
  );
}

/* ---------------- Media thumbnail ---------------- */
export function MediaTile({ item, size = 72, onRemove, onRetry, onDownload, idx, total, draggable, onDragStart, onDragEnter, onDragEnd, onDragOver, dragging }: {
  item: MediaItem; size?: number; onRemove?: () => void; onRetry?: () => void; onDownload?: () => void; idx: number; total: number;
  draggable?: boolean; onDragStart?: () => void; onDragEnter?: () => void; onDragEnd?: () => void; onDragOver?: (e: DragEvent) => void; dragging?: boolean;
}) {
  const src = item.preview || resolveMediaUrl(item.url);  // prefer instant local preview
  const showImage = item.type === "image" && src;
  const showVideo = item.type === "video" && src;
  const bg = showImage ? `center/cover no-repeat url(${src})` : item.g;
  const ready = !item.uploading && !item.error;
  return (
    <div
      draggable={draggable && ready ? true : undefined}
      onDragStart={draggable && ready ? onDragStart : undefined}
      onDragEnter={draggable ? onDragEnter : undefined}
      onDragOver={draggable ? onDragOver : undefined}
      onDragEnd={draggable ? onDragEnd : undefined}
      style={{ position: "relative", width: size, height: size, borderRadius: 12, flexShrink: 0, background: bg, overflow: "hidden", boxShadow: item.error ? "inset 0 0 0 2px var(--vm-red)" : "inset 0 0 0 1px rgba(0,0,0,.06)", cursor: draggable && ready ? "grab" : "default", opacity: dragging ? 0.4 : 1, transition: "opacity var(--dur)" }}>
      {showVideo && (
        <video src={src} muted playsInline preload="metadata" style={{ position: "absolute", inset: 0, width: "100%", height: "100%", objectFit: "cover" }} />
      )}
      {/* play glyph — only on a ready video */}
      {item.type === "video" && !item.uploading && !item.error && (
        <span style={{ position: "absolute", inset: 0, display: "grid", placeItems: "center" }}>
          <span style={{ width: 0, height: 0, borderLeft: "12px solid #fff", borderTop: "8px solid transparent", borderBottom: "8px solid transparent", marginLeft: 3, filter: "drop-shadow(0 1px 2px rgba(0,0,0,.4))" }} />
        </span>
      )}
      {/* uploading: dim overlay with live percentage + bottom progress bar */}
      {item.uploading && (
        <>
          <span style={{ position: "absolute", inset: 0, display: "grid", placeItems: "center", background: "rgba(10,10,12,.5)", color: "#fff", fontFamily: "var(--font-mono)", fontSize: 11, fontWeight: 700 }}>
            {item.progress != null ? `${item.progress}%` : "…"}
          </span>
          <span style={{ position: "absolute", left: 0, right: 0, bottom: 0, height: 4, background: "rgba(255,255,255,.28)" }}>
            <span style={{ display: "block", height: "100%", width: `${item.progress ?? 0}%`, background: "var(--vm-volt-deep)", transition: "width .2s ease" }} />
          </span>
        </>
      )}
      {/* failed: clear state + inline retry */}
      {item.error && (
        <span style={{ position: "absolute", inset: 0, display: "flex", flexDirection: "column", gap: 4, alignItems: "center", justifyContent: "center", background: "rgba(255,31,61,.32)", color: "#fff", fontFamily: "var(--font-mono)", fontSize: 8, fontWeight: 700, padding: 4, textAlign: "center" }}>
          <span>FAILED</span>
          {onRetry && (
            <button onClick={onRetry} title="Retry upload" style={{ display: "inline-flex", alignItems: "center", gap: 3, background: "rgba(255,255,255,.92)", color: "var(--vm-red-deep)", border: "none", borderRadius: 999, padding: "2px 7px", fontFamily: "var(--font-mono)", fontSize: 8.5, fontWeight: 800, cursor: "pointer" }}>
              <Icon name="rotate-cw" size={9} stroke="var(--vm-red-deep)" /> RETRY
            </button>
          )}
        </span>
      )}
      {!item.uploading && !item.error && (
        <span style={{ position: "absolute", left: 5, bottom: 5, fontFamily: "var(--font-mono)", fontSize: 9, fontWeight: 700, color: "#fff", background: "rgba(0,0,0,.45)", padding: "1px 5px", borderRadius: 999, textTransform: "uppercase", letterSpacing: ".04em" }}>
          {item.type === "video" ? "video" : `${idx + 1}/${total}`}
        </span>
      )}
      {onDownload && ready && (item.url || item.preview) && (
        <button onClick={onDownload} title="Download" style={{ position: "absolute", top: 4, left: 4, width: 18, height: 18, borderRadius: "50%", border: "none", background: "rgba(10,10,12,.62)", color: "#fff", cursor: "pointer", display: "grid", placeItems: "center", backdropFilter: "blur(2px)" }}>
          <Icon name="download" size={11} stroke="#fff" />
        </button>
      )}
      {onRemove && (
        <button onClick={onRemove} title="Remove" style={{ position: "absolute", top: 4, right: 4, width: 18, height: 18, borderRadius: "50%", border: "none", background: "rgba(10,10,12,.62)", color: "#fff", cursor: "pointer", fontSize: 11, lineHeight: 1, display: "grid", placeItems: "center", backdropFilter: "blur(2px)" }}>✕</button>
      )}
    </div>
  );
}

/* ---------------- X thread preview ---------------- */
// X-style media grid: 1 image full-width, 2 side-by-side, 3 with the first
// spanning the left column, 4 in a 2×2 — extras collapse into a "+N" overlay.
function XMediaGrid({ images }: { images: MediaItem[] }) {
  const shown = images.slice(0, X_MAX_IMAGES);
  if (shown.length === 0) return null;
  const hidden = images.length - shown.length;
  return (
    <div style={{
      marginTop: 8, borderRadius: 14, overflow: "hidden", border: "1px solid var(--line-1)",
      aspectRatio: "16 / 10", display: "grid", gap: 2,
      gridTemplateColumns: shown.length === 1 ? "1fr" : "1fr 1fr",
      gridTemplateRows: shown.length <= 2 ? "1fr" : "1fr 1fr",
    }}>
      {shown.map((m, i) => {
        const src = m.preview || resolveMediaUrl(m.url);
        const last = i === shown.length - 1;
        return (
          <div key={m.id} style={{
            position: "relative", minHeight: 0,
            background: src ? `center/cover no-repeat url(${src})` : m.g,
            gridRow: shown.length === 3 && i === 0 ? "1 / span 2" : undefined,
          }}>
            {last && hidden > 0 && (
              <span style={{ position: "absolute", inset: 0, display: "grid", placeItems: "center", background: "rgba(10,10,12,.55)", color: "#fff", fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 18 }}>+{hidden}</span>
            )}
          </div>
        );
      })}
    </div>
  );
}

// The thread as X renders it: stacked tweets on a shared avatar rail with a
// connector line, media on the first tweet, per-tweet position + over-limit
// flags so a bad segment is obvious right in the phone.
function XThreadPreview({ segments, media, compact }: { segments: string[]; media: MediaItem[]; compact?: boolean }) {
  const p = PMAP.x;
  const images = media.filter((m) => m.type === "image");
  const total = segments.length;
  return (
    <div style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 16, boxShadow: "0 1px 2px rgba(10,10,12,.05)", fontFamily: "var(--font-body)", padding: "10px 0 4px" }}>
      {segments.map((seg, i) => {
        const len = xWeightedLength(seg);
        const over = len > X_LIMIT;
        const last = i === total - 1;
        return (
          <div key={i} style={{ display: "flex", gap: 10, padding: "0 14px" }}>
            {/* avatar rail + connector */}
            <div style={{ display: "flex", flexDirection: "column", alignItems: "center", flexShrink: 0 }}>
              <PAvatar id="x" size={compact ? 26 : 32} />
              {!last && <span style={{ width: 2, flex: 1, minHeight: 14, background: "var(--line-2)", borderRadius: 1, margin: "4px 0" }} />}
            </div>
            {/* tweet */}
            <div style={{ minWidth: 0, flex: 1, paddingBottom: last ? 8 : 14 }}>
              <div style={{ display: "flex", alignItems: "center", gap: 5, minWidth: 0 }}>
                <span style={{ fontWeight: 700, fontSize: compact ? 12 : 13, color: "var(--ink-on-paper-1)", whiteSpace: "nowrap" }}>{p.handle.replace("@", "")}</span>
                <span style={{ width: 12, height: 12, borderRadius: "50%", background: p.accent, display: "inline-grid", placeItems: "center", color: "#fff", fontSize: 7, fontWeight: 900, flexShrink: 0 }}>✓</span>
                <span style={{ fontFamily: "var(--font-mono)", fontSize: 10.5, color: "var(--ink-on-paper-3)", overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>{p.handle}</span>
                <span style={{ marginLeft: "auto", display: "inline-flex", alignItems: "center", gap: 5, flexShrink: 0 }}>
                  {over && (
                    <span style={{ fontFamily: "var(--font-mono)", fontSize: 10, fontWeight: 700, color: "var(--vm-red)", background: "var(--vm-red-tint-l)", border: "1px solid rgba(255,31,61,.4)", borderRadius: 999, padding: "1px 6px" }}>−{len - X_LIMIT}</span>
                  )}
                  <span style={{ fontFamily: "var(--font-mono)", fontSize: 10, fontWeight: 700, color: "var(--ink-on-paper-3)" }}>{i + 1}/{total}</span>
                </span>
              </div>
              {seg && (
                <div style={{ marginTop: 3, fontSize: compact ? 12 : 13, lineHeight: 1.5, color: "var(--ink-on-paper-1)", whiteSpace: "pre-wrap", wordBreak: "break-word" }}>{seg}</div>
              )}
              {i === 0 && <XMediaGrid images={images} />}
              <div style={{ display: "flex", alignItems: "center", gap: 22, marginTop: 8, color: "var(--ink-on-paper-3)" }}>
                {["message-circle", "rotate-cw", "heart", "share-2"].map((ic) => (
                  <Icon key={ic} name={ic} size={compact ? 12 : 14} stroke="var(--ink-on-paper-3)" />
                ))}
              </div>
            </div>
          </div>
        );
      })}
    </div>
  );
}

/* ---------------- Live feed preview ---------------- */
export function PostPreview({ id, text, media, compact }: { id: string; text: string; media: MediaItem[]; compact?: boolean }) {
  const p = PMAP[id];
  // Carousel slide index for multi-image slideshows. Derived `active` is clamped
  // to the current length so removing/reordering media can't strand the view.
  const [slide, setSlide] = useState(0);
  // X: a `---` line splits the caption into a thread — render the real
  // stacked reply chain instead of a single flattened post.
  const thread = id === "x" ? splitXThread(text || "") : [];
  if (thread.length > 1) {
    return <XThreadPreview segments={thread} media={media} compact={compact} />;
  }
  const effective = text || "";
  const over = [...effective].length > p.limit;
  const shown = over ? [...effective].slice(0, p.limit).join("") : effective;
  const hasMedia = media && media.length > 0;
  const active = Math.min(slide, Math.max(0, media.length - 1));
  const first = hasMedia ? media[active] : null;
  const goPrev = () => setSlide((active - 1 + media.length) % media.length);
  const goNext = () => setSlide((active + 1) % media.length);
  // Vertical video (TikTok / Shorts / Reels) is 9:16 — give it a tall frame so
  // a portrait clip fills it instead of being cropped top & bottom.
  const verticalVideo = first?.type === "video" && (id === "tiktok" || id === "youtube" || id === "instagram");
  const aspect = verticalVideo ? "9 / 16"
    : id === "youtube" ? "16 / 9"
    : (id === "x" || id === "linkedin" || id === "facebook" || id === "threads") ? "16 / 10"
    : "4 / 5";
  const textFirst = id === "x" || id === "threads" || id === "linkedin" || id === "facebook";
  const captionAfter = id === "instagram" || id === "tiktok" || id === "youtube";
  return (
    <div style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 16, overflow: "hidden", boxShadow: "0 1px 2px rgba(10,10,12,.05)", fontFamily: "var(--font-body)" }}>
      <div style={{ display: "flex", alignItems: "center", gap: 10, padding: compact ? "10px 12px" : "13px 14px" }}>
        <PAvatar id={id} size={compact ? 30 : 36} />
        <div style={{ minWidth: 0, flex: 1 }}>
          <div style={{ fontWeight: 700, fontSize: compact ? 12.5 : 13.5, color: "var(--ink-on-paper-1)", display: "flex", alignItems: "center", gap: 5 }}>
            {p.handle.startsWith("@") ? p.handle.replace("@", "") : p.handle}
            <span style={{ width: 13, height: 13, borderRadius: "50%", background: p.accent, display: "inline-grid", placeItems: "center", color: "#fff", fontSize: 8, fontWeight: 900 }}>✓</span>
          </div>
          <div style={{ fontSize: 11, color: "var(--ink-on-paper-3)", fontFamily: "var(--font-mono)" }}>{p.handle} · {p.kind}</div>
        </div>
        <span style={{ color: "var(--ink-on-paper-3)", fontWeight: 800, letterSpacing: 1 }}>···</span>
      </div>
      {textFirst && shown && (
        <div style={{ padding: "0 14px 10px", fontSize: compact ? 12.5 : 13.5, lineHeight: 1.5, color: "var(--ink-on-paper-1)", whiteSpace: "pre-wrap", wordBreak: "break-word" }}>
          {shown}{over && <span style={{ color: "var(--ink-on-paper-3)" }}>…</span>}
        </div>
      )}
      {hasMedia && first && (() => {
        const src = first.preview || resolveMediaUrl(first.url);
        const isImg = first.type === "image" && src;
        const isVid = first.type === "video" && src;
        return (
        <div style={{ position: "relative", width: "100%", aspectRatio: aspect, background: isImg ? `center/cover no-repeat url(${src})` : first.g }}>
          {isVid && (
            <video src={src} muted playsInline loop autoPlay preload="metadata" style={{ position: "absolute", inset: 0, width: "100%", height: "100%", objectFit: "cover" }} />
          )}
          {first.type === "video" && (
            <span style={{ position: "absolute", inset: 0, display: "grid", placeItems: "center", pointerEvents: "none" }}>
              <span style={{ width: 52, height: 52, borderRadius: "50%", background: "rgba(10,10,12,.5)", display: "grid", placeItems: "center", backdropFilter: "blur(2px)" }}>
                <span style={{ width: 0, height: 0, borderLeft: "15px solid #fff", borderTop: "10px solid transparent", borderBottom: "10px solid transparent", marginLeft: 4 }} />
              </span>
            </span>
          )}
          {media.length > 1 && (
            <span style={{ position: "absolute", top: 9, right: 9, background: "rgba(10,10,12,.55)", color: "#fff", fontFamily: "var(--font-mono)", fontSize: 10, fontWeight: 700, padding: "2px 7px", borderRadius: 999, display: "flex", alignItems: "center", gap: 4 }}>
              <span style={{ fontSize: 11 }}>▦</span> {active + 1}/{media.length}
            </span>
          )}
          {media.length > 1 && (
            <>
              <button onClick={goPrev} title="Previous slide" style={{ position: "absolute", top: "50%", left: 8, transform: "translateY(-50%)", width: 30, height: 30, borderRadius: "50%", border: "none", background: "rgba(10,10,12,.5)", color: "#fff", cursor: "pointer", display: "grid", placeItems: "center", backdropFilter: "blur(2px)" }}>
                <Icon name="chevron-left" size={17} stroke="#fff" />
              </button>
              <button onClick={goNext} title="Next slide" style={{ position: "absolute", top: "50%", right: 8, transform: "translateY(-50%)", width: 30, height: 30, borderRadius: "50%", border: "none", background: "rgba(10,10,12,.5)", color: "#fff", cursor: "pointer", display: "grid", placeItems: "center", backdropFilter: "blur(2px)" }}>
                <Icon name="chevron-right" size={17} stroke="#fff" />
              </button>
              <span style={{ position: "absolute", bottom: 9, left: 0, right: 0, display: "flex", alignItems: "center", justifyContent: "center", gap: 5 }}>
                {media.slice(0, 8).map((m, i) => (
                  <button key={m.id} onClick={() => setSlide(i)} title={`Slide ${i + 1}`} style={{ width: i === active ? 7 : 6, height: i === active ? 7 : 6, padding: 0, borderRadius: "50%", border: "none", cursor: "pointer", background: i === active ? "#fff" : "rgba(255,255,255,.5)" }} />
                ))}
                {media.length > 8 && <span style={{ color: "rgba(255,255,255,.75)", fontFamily: "var(--font-mono)", fontSize: 9, fontWeight: 700, marginLeft: 2 }}>+{media.length - 8}</span>}
              </span>
            </>
          )}
        </div>
        );
      })()}
      <div style={{ display: "flex", alignItems: "center", gap: 16, padding: compact ? "8px 12px" : "10px 14px", color: "var(--ink-on-paper-2)" }}>
        {(id === "youtube" ? ["thumbs-up", "message-square", "share-2"] : ["heart", "message-circle", "send", "bookmark"]).map((ic) => (
          <Icon key={ic} name={ic} size={compact ? 15 : 17} stroke="var(--ink-on-paper-2)" />
        ))}
      </div>
      {captionAfter && shown && (
        <div style={{ padding: "0 14px 13px", fontSize: compact ? 12 : 13, lineHeight: 1.45, color: "var(--ink-on-paper-1)", whiteSpace: "pre-wrap", wordBreak: "break-word" }}>
          <span style={{ fontWeight: 700 }}>{p.handle.replace("@", "")}</span>{" "}
          {(compact ? [...shown].slice(0, 120).join("") : shown)}{(over || (compact && [...shown].length > 120)) && <span style={{ color: "var(--ink-on-paper-3)" }}> …more</span>}
        </div>
      )}
    </div>
  );
}

/* ---------------- Counter chip ---------------- */
export function CountChip({ id, status }: { id: string; status: Record<string, PlatformStatus> }) {
  const p = PMAP[id];
  const s = status[id];
  const near = !s.over && s.len > p.limit * 0.85;
  const color = s.over ? "var(--vm-red)" : near ? "var(--warn)" : "var(--ink-on-paper-3)";
  const bg = s.over ? "var(--vm-red-tint-l)" : near ? "rgba(255,176,32,.14)" : "var(--paper-2)";
  const wrap: CSSProperties = { display: "inline-flex", alignItems: "center", gap: 6, background: bg, padding: "4px 9px 4px 6px", borderRadius: 999, border: s.over ? "1px solid rgba(255,31,61,.4)" : "1px solid transparent" };
  return (
    <span style={wrap}>
      <PAvatar id={id} size={16} />
      <span style={{ fontFamily: "var(--font-mono)", fontSize: 11, fontWeight: 700, color }}>{s.over ? `−${s.len - p.limit}` : `${p.limit - s.len}`}</span>
    </span>
  );
}
