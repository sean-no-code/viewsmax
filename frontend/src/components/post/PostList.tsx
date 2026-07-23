// Post sub-page shell + a list view of posts filtered by status (Drafts /
// Scheduled), with delete.
import { useCallback, useEffect, useState, type CSSProperties, type ReactNode } from "react";
import { useNavigate } from "react-router-dom";
import { toast } from "sonner";
import { Icon, SectionHead, Chip } from "@/components/analytics/primitives";
import { AnalyticsLoading } from "@/components/analytics/useAnalytics";
import { PAvatar, PMAP } from "@/components/post/composer";
import { useAccountDirectory } from "@/components/post/useAccountDirectory";
import { viewsMaxApi, type Post, type PostComment, type PostTarget } from "@/lib/api-service";

export function PostShell({ children, max = 1100 }: { children: ReactNode; max?: number }) {
  return (
    <div style={{ margin: "-24px", padding: 24, background: "var(--paper-1)", minHeight: "calc(100vh - 4rem)" }}>
      <div style={{ maxWidth: max, margin: "0 auto", display: "flex", flexDirection: "column", gap: 18 }}>{children}</div>
    </div>
  );
}

// Render any API timestamp (ISO or "Y-m-d H:i:s") in the viewer's local time.
export const fmtDateTime = (raw?: string | null) => {
  if (!raw) return "—";
  const d = new Date(raw.includes("T") ? raw : raw.replace(" ", "T"));
  if (Number.isNaN(d.getTime())) return raw;
  return d.toLocaleString([], { month: "short", day: "numeric", hour: "2-digit", minute: "2-digit" });
};

const fmtWhen = (p: Post) => {
  if (!p.scheduled_at) return p.status === "posted" ? "Posted" : "Draft";
  return fmtDateTime(p.scheduled_at);
};

// The caption that actually publishes: a per-platform override when one is set,
// otherwise the post's main caption. Pass a target to resolve for that platform;
// omit it for per-post rows (main caption, else the first override).
export const effectiveCaption = (p: Post, target?: PostTarget): string => {
  const main = (p.caption || "").trim();
  if (target) return (target.caption_override || "").trim() || main;
  return main || ((p.targets || []).map((t) => (t.caption_override || "").trim()).find(Boolean) ?? "");
};

const snippet = (p: Post) => {
  const t = effectiveCaption(p);
  return t ? (t.length > 120 ? t.slice(0, 120) + "…" : t) : "No caption";
};

export function PostListView({ status, eyebrow, title, emptyLabel }: { status: "draft" | "scheduled"; eyebrow: string; title: string; emptyLabel: string }) {
  const navigate = useNavigate();
  const { directory: accountDirectory } = useAccountDirectory();
  const [posts, setPosts] = useState<Post[]>([]);
  const [loading, setLoading] = useState(true);

  const load = useCallback(async () => {
    setLoading(true);
    const res = await viewsMaxApi.getPosts({ status });
    setPosts(res.success && res.data ? res.data : []);
    setLoading(false);
  }, [status]);

  useEffect(() => { load(); }, [load]);

  const remove = async (id: number) => {
    const res = await viewsMaxApi.deletePost(id);
    if (res.success) { setPosts((p) => p.filter((x) => x.id !== id)); toast.success("Post deleted."); }
    else toast.error(res.error || "Couldn't delete the post.");
  };

  return (
    <PostShell>
      <SectionHead eyebrow={eyebrow} title={title} />
      {loading ? (
        <AnalyticsLoading />
      ) : posts.length === 0 ? (
        <div style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 18, padding: 48, textAlign: "center", color: "var(--ink-on-paper-3)", fontFamily: "var(--font-body)", fontSize: 14 }}>{emptyLabel}</div>
      ) : (
        <div style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 18, overflow: "hidden", boxShadow: "0 1px 2px rgba(10,10,12,.04)" }}>
          {posts.map((p, i) => (
            <div
              key={p.id}
              onClick={() => navigate(`/dashboard/post/${p.id}`)}
              role="button"
              tabIndex={0}
              onKeyDown={(e) => { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); navigate(`/dashboard/post/${p.id}`); } }}
              title="Edit post"
              style={{ display: "flex", alignItems: "center", gap: 14, padding: "16px 20px", borderTop: i ? "1px solid var(--paper-2)" : "none", cursor: "pointer" }}
            >
              <span style={{ display: "flex" }}>
                {(p.targets || []).slice(0, 4).map((t, ti) => (
                  <span key={t.id} style={{ marginLeft: ti ? -8 : 0, display: "inline-flex" }}>{PMAP[t.platform] ? <PAvatar id={t.platform} size={30} avatarUrl={t.social_account?.avatar_url ?? accountDirectory[t.platform]?.avatarUrl} /> : null}</span>
                ))}
              </span>
              <div style={{ flex: 1, minWidth: 0 }}>
                <div style={{ fontFamily: "var(--font-body)", fontSize: 13.5, fontWeight: 600, color: "var(--ink-on-paper-1)", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>{snippet(p)}</div>
                <div style={{ fontFamily: "var(--font-mono)", fontSize: 11, color: "var(--ink-on-paper-3)", marginTop: 3 }}>{(p.targets || []).length} platform{(p.targets || []).length === 1 ? "" : "s"} · {(p.media || []).length} media</div>
              </div>
              <Chip tone={status === "scheduled" ? "aqua" : "ghost"}>{fmtWhen(p)}</Chip>
              <button onClick={(e) => { e.stopPropagation(); remove(p.id); }} title="Delete" style={{ width: 34, height: 34, borderRadius: 999, border: "1px solid var(--line-1)", background: "var(--paper-0)", cursor: "pointer", display: "grid", placeItems: "center", flexShrink: 0 }}>
                <Icon name="trash-2" size={15} stroke="var(--ink-on-paper-3)" />
              </button>
            </div>
          ))}
        </div>
      )}
    </PostShell>
  );
}

/* ---------------- Per-platform publish status badge ---------------- */
const STATUS_TONE: Record<string, { bg: string; fg: string; label: string }> = {
  published: { bg: "rgba(15,182,126,.12)", fg: "var(--up)", label: "Published" },
  publishing: { bg: "rgba(255,176,32,.16)", fg: "var(--warn)", label: "Publishing" },
  pending: { bg: "var(--paper-2)", fg: "var(--ink-on-paper-3)", label: "Pending" },
  failed: { bg: "var(--vm-red-tint-l)", fg: "var(--vm-red)", label: "Failed" },
  inbox: { bg: "rgba(255,176,32,.16)", fg: "var(--warn)", label: "In TikTok inbox" },
};

// TikTok inbox uploads report "published" but the video is really sitting in
// the creator's TikTok app inbox (unaudited apps can't direct-post to public
// accounts, and the inbox API accepts no caption).
export const isInboxDelivery = (t: PostTarget): boolean =>
  t.delivery_mode === "inbox" || t.meta?.mode === "inbox";

export const INBOX_NOTE = "Sent to the TikTok inbox — open the TikTok app, find the video in your notifications, and finish posting there. Captions can't be attached to inbox deliveries, so paste yours in the app.";

// The live URL of a published post, when the platform gave us one. IG / X /
// LinkedIn / Threads store meta.url; YouTube stores meta.video_url; the generic
// social path uses remote_post_url. TikTok direct posts have no public URL.
export function publishedUrl(target: PostTarget): string | null {
  const m = (target.meta || {}) as Record<string, unknown>;
  const u = (m.url || m.video_url || m.remote_post_url) as string | undefined;
  return typeof u === "string" && /^https?:\/\//i.test(u) ? u : null;
}

export function TargetStatusBadge({ target }: { target: PostTarget }) {
  const inbox = target.status === "published" && isInboxDelivery(target);
  const s = inbox ? STATUS_TONE.inbox : (STATUS_TONE[target.status || "pending"] ?? STATUS_TONE.pending);
  const url = target.status === "published" ? publishedUrl(target) : null;
  const style = { display: "inline-flex", alignItems: "center", gap: 6, background: s.bg, color: s.fg, borderRadius: 999, padding: "4px 9px 4px 6px", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 11.5, textDecoration: "none" } as const;
  const inner = (
    <>
      {PMAP[target.platform] ? <PAvatar id={target.platform} size={16} /> : null}
      {s.label}
      {url && <Icon name="arrow-up-right" size={13} stroke={s.fg} />}
    </>
  );

  if (url) {
    return (
      <a href={url} target="_blank" rel="noopener noreferrer" onClick={(e) => e.stopPropagation()}
        title="View published post" style={{ ...style, cursor: "pointer" }}>
        {inner}
      </a>
    );
  }
  return (
    <span title={inbox ? INBOX_NOTE : target.error || (target.platform_post_id ? `Post ID: ${target.platform_post_id}` : undefined)}
      style={{ ...style, cursor: inbox || target.error ? "help" : "default" }}>
      {inner}
    </span>
  );
}

/* ---------------- History: one row per post, all platforms together --------- */

// A single piece of content is one row; every platform it went to shows its own
// status badge inside the Delivery column (a 3-platform post is ONE row, not 3).
const HISTORY_GRID = "150px minmax(0,2.2fr) 60px 140px minmax(0,2.4fr)";
const historyHead: CSSProperties = { fontFamily: "var(--font-mono)", fontSize: 10.5, letterSpacing: ".05em", textTransform: "uppercase", color: "rgba(255,255,255,.6)", fontWeight: 600 };
const historyCell: CSSProperties = { fontFamily: "var(--font-body)", fontSize: 12.5, color: "var(--ink-on-paper-2)", minWidth: 0 };

// What actually happened on the platform, in plain words.
function deliveryDetail(t: PostTarget): { text: string; tone: string } {
  if (t.status === "failed") return { text: t.error || "Publishing failed.", tone: "var(--vm-red)" };
  if (t.status === "published" && isInboxDelivery(t)) return { text: INBOX_NOTE, tone: "var(--warn)" };
  if (t.status === "published") {
    const when = t.published_at ? ` (${fmtDateTime(t.published_at)})` : "";
    return { text: `Live on the platform${when}.`, tone: "var(--up)" };
  }
  if (t.status === "publishing") return { text: "Uploading to the platform…", tone: "var(--warn)" };
  return { text: "Waiting to publish.", tone: "var(--ink-on-paper-3)" };
}

// One platform's outcome inside a post row: status badge, plain-words detail,
// and a Retry action when it failed (re-queues just this platform).
function DeliveryLine({ target, comments, retrying, onRetry }: { target: PostTarget; comments?: PostComment[]; retrying: boolean; onRetry: () => void }) {
  const detail = deliveryDetail(target);
  // Follow-up comment delivery on THIS target: "2/3 comments posted" etc.
  const rows = (comments ?? []).flatMap((cm) => (cm.target_comments ?? []).filter((tc) => tc.post_target_id === target.id));
  const posted = rows.filter((r) => r.status === "posted").length;
  const failed = rows.filter((r) => r.status === "failed").length;
  const skipped = rows.length > 0 && rows.every((r) => r.status === "skipped");
  return (
    <div style={{ display: "flex", flexDirection: "column", gap: 3, minWidth: 0 }}>
      <div style={{ display: "flex", alignItems: "center", gap: 8, flexWrap: "wrap" }}>
        <TargetStatusBadge target={target} />
        {target.status === "failed" && (
          <button
            onClick={(e) => { e.stopPropagation(); onRetry(); }}
            disabled={retrying}
            title={`Retry publishing to ${target.platform}`}
            style={{ display: "inline-flex", alignItems: "center", gap: 5, height: 24, padding: "0 10px", borderRadius: 999, border: "1px solid var(--line-1)", background: "var(--paper-0)", cursor: retrying ? "default" : "pointer", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 11, color: "var(--ink-on-paper-2)", opacity: retrying ? 0.55 : 1 }}
          >
            <Icon name="rotate-cw" size={12} stroke="var(--ink-on-paper-2)" />
            {retrying ? "Retrying…" : "Retry"}
          </button>
        )}
      </div>
      <div style={{ fontFamily: "var(--font-body)", fontSize: 11.5, lineHeight: 1.45, color: detail.tone }}>{detail.text}</div>
      {rows.length > 0 && !skipped && (
        <div style={{ fontFamily: "var(--font-mono)", fontSize: 10.5, color: failed ? "var(--vm-red)" : "var(--ink-on-paper-3)" }}>
          {posted}/{rows.length} comment{rows.length === 1 ? "" : "s"} posted{failed ? ` · ${failed} failed` : ""}
        </div>
      )}
    </div>
  );
}

export function PostHistoryView() {
  const { directory: accountDirectory } = useAccountDirectory();
  const [posts, setPosts] = useState<Post[]>([]);
  const [loading, setLoading] = useState(true);
  const [retrying, setRetrying] = useState<Set<number>>(new Set());

  const load = useCallback(async () => {
    setLoading(true);
    const res = await viewsMaxApi.getPosts();
    // Newest first; drafts excluded (nothing to report yet).
    const all = res.success && res.data ? res.data : [];
    setPosts(all.filter((p) => p.status !== "draft"));
    setLoading(false);
  }, []);

  useEffect(() => { load(); }, [load]);

  const retry = async (postId: number, targetId: number) => {
    setRetrying((s) => new Set(s).add(targetId));
    const res = await viewsMaxApi.retryPostTarget(postId, targetId);
    if (res.success && res.data) {
      const updated = res.data;
      setPosts((all) => all.map((p) => (p.id === updated.id ? updated : p)));
      toast.success("Retrying — re-publishing on that platform.");
    } else {
      toast.error(res.error || "Couldn't retry that platform.");
    }
    setRetrying((s) => { const n = new Set(s); n.delete(targetId); return n; });
  };

  return (
    <PostShell max={1280}>
      <SectionHead eyebrow="Publishing" title="History" />
      {loading ? (
        <AnalyticsLoading />
      ) : posts.length === 0 ? (
        <div style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 18, padding: 48, textAlign: "center", color: "var(--ink-on-paper-3)", fontFamily: "var(--font-body)", fontSize: 14 }}>No posts yet. Published and scheduled posts show up here with their per-platform status.</div>
      ) : (
        <div style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 18, overflow: "hidden", boxShadow: "0 1px 2px rgba(10,10,12,.04)" }}>
          <div style={{ display: "grid", gridTemplateColumns: HISTORY_GRID, gap: 14, alignItems: "center", padding: "11px 18px", background: "var(--ink-900)" }}>
            <div style={historyHead}>Platforms</div>
            <div style={historyHead}>Caption</div>
            <div style={{ ...historyHead, textAlign: "right" }}>Media</div>
            <div style={historyHead}>Scheduled</div>
            <div style={historyHead}>Delivery</div>
          </div>
          {posts.map((p, i) => {
            const targets = p.targets || [];
            const caption = effectiveCaption(p);
            return (
              <div key={p.id} style={{ display: "grid", gridTemplateColumns: HISTORY_GRID, gap: 14, alignItems: "start", padding: "14px 18px", borderTop: i ? "1px solid var(--paper-2)" : "none" }}>
                <div style={{ display: "flex", alignItems: "center", minWidth: 0, paddingTop: 2 }}>
                  {targets.length ? (
                    <span style={{ display: "flex" }}>
                      {targets.slice(0, 5).map((t, ti) => (
                        <span key={t.id} title={t.social_account?.name ? `${t.platform} — ${t.social_account.name}` : t.platform} style={{ marginLeft: ti ? -8 : 0, display: "inline-flex" }}>{PMAP[t.platform] ? <PAvatar id={t.platform} size={26} avatarUrl={t.social_account?.avatar_url ?? accountDirectory[t.platform]?.avatarUrl} /> : null}</span>
                      ))}
                    </span>
                  ) : (
                    <span style={{ fontFamily: "var(--font-mono)", fontSize: 11, color: "var(--ink-on-paper-3)" }}>—</span>
                  )}
                </div>
                <div style={{ ...historyCell, color: caption ? "var(--ink-on-paper-1)" : "var(--ink-on-paper-3)", fontStyle: caption ? "normal" : "italic", paddingTop: 3, display: "-webkit-box", WebkitLineClamp: 3, WebkitBoxOrient: "vertical", overflow: "hidden" }} title={caption || undefined}>
                  {caption || "No caption"}
                </div>
                <div style={{ ...historyCell, fontFamily: "var(--font-mono)", fontSize: 12, textAlign: "right", paddingTop: 3 }}>{(p.media || []).length}</div>
                <div style={{ ...historyCell, fontFamily: "var(--font-mono)", fontSize: 11.5, paddingTop: 3 }}>{p.scheduled_at ? fmtDateTime(p.scheduled_at) : "—"}</div>
                <div style={{ minWidth: 0, display: "flex", flexDirection: "column", gap: 10 }}>
                  {targets.length ? (
                    targets.map((t) => (
                      <DeliveryLine key={t.id} target={t} comments={p.comments} retrying={retrying.has(t.id)} onRetry={() => retry(p.id, t.id)} />
                    ))
                  ) : (
                    <span style={{ fontFamily: "var(--font-mono)", fontSize: 11, color: "var(--ink-on-paper-3)" }}>no platforms</span>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      )}
    </PostShell>
  );
}
