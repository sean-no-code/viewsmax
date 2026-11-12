// Content Calendar — everything scheduled to go out, in one month grid,
// with filter chips at the top to narrow by platform.
import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { useTranslation } from "react-i18next";
import { toast } from "sonner";
import { SectionHead } from "@/components/analytics/primitives";
import { AnalyticsLoading } from "@/components/analytics/useAnalytics";
import { PostShell } from "@/components/post/PostList";
import PostCalendar, { PlatformTile } from "@/components/post/PostCalendar";
import { PMAP } from "@/components/post/composer";
import { viewsMaxApi, type Post } from "@/lib/api-service";

const chipStyle = (active: boolean) => ({
  display: "inline-flex", alignItems: "center", gap: 7, padding: "5px 12px 5px 5px", borderRadius: 999, cursor: "pointer",
  border: "1px solid " + (active ? "var(--ink-on-paper-1)" : "var(--line-1)"),
  background: active ? "var(--ink-on-paper-1)" : "var(--paper-0)",
  color: active ? "#fff" : "var(--ink-on-paper-2)",
  fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 12.5,
} as const);

export default function CalendarPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const [posts, setPosts] = useState<Post[]>([]);
  const [loading, setLoading] = useState(true);

  // Platform filter. Empty set = all platforms.
  const [platformSel, setPlatformSel] = useState<Set<string>>(new Set());

  useEffect(() => {
    (async () => {
      const postsRes = await viewsMaxApi.getPosts();
      setPosts(postsRes.success && postsRes.data ? postsRes.data : []);
      setLoading(false);
    })();
  }, []);

  const platformsPresent = useMemo(
    () => [...new Set(posts.flatMap((p) => (p.targets || []).map((tg) => tg.platform)))].filter((id) => PMAP[id]),
    [posts],
  );

  const togglePlatform = (id: string) => setPlatformSel((s) => {
    const n = new Set(s);
    if (n.has(id)) n.delete(id); else n.add(id);
    return n;
  });

  const visiblePosts = useMemo(() => {
    if (platformSel.size === 0) return posts;
    return posts.filter((p) => (p.targets || []).some((tg) => platformSel.has(tg.platform)));
  }, [posts, platformSel]);

  const onSelect = (p: Post) => {
    if (p.status !== "posted") navigate(`/dashboard/post/${p.id}`);
  };

  const onReschedule = async (p: Post, when: Date) => {
    const res = await viewsMaxApi.updatePost(p.id, { scheduled_at: when.toISOString() });
    if (!res.success || !res.data) {
      toast.error(res.error || t("post.calendar.rescheduleFailed", "Couldn't reschedule the post."));
      return false;
    }
    setPosts((list) => list.map((x) => (x.id === p.id ? res.data! : x)));
    toast.success(t("post.calendar.rescheduled", "Post rescheduled."));
    return true;
  };

  const onDelete = async (p: Post) => {
    const res = await viewsMaxApi.deletePost(p.id);
    if (res.success) { setPosts((list) => list.filter((x) => x.id !== p.id)); toast.success(t("post.list.deleted", "Post deleted.")); }
    else toast.error(res.error || t("post.list.deleteFailed", "Couldn't delete the post."));
  };

  return (
    <PostShell max={1280}>
      <SectionHead eyebrow={t("post.calendar.eyebrow", "CONTENT")} title={t("post.calendar.title", "Calendar.")} />

      {/* Filters: which platforms show on the grid. */}
      {platformsPresent.length > 0 && (
        <div style={{ display: "flex", alignItems: "center", gap: 8, flexWrap: "wrap" }}>
          {platformsPresent.map((id) => (
            <button key={id} style={chipStyle(platformSel.has(id))} onClick={() => togglePlatform(id)} title={t("post.calendar.filterOnlyPlatform", "Only show posts going to {{platform}}", { platform: PMAP[id].name })}>
              <PlatformTile id={id} size={22} ring={false} /> {PMAP[id].name}
            </button>
          ))}
          {platformSel.size > 0 && (
            <button style={{ background: "none", border: "none", cursor: "pointer", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 12, color: "var(--vm-red)" }} onClick={() => setPlatformSel(new Set())}>
              {t("post.calendar.clearPlatforms", "Clear platforms")}
            </button>
          )}
        </div>
      )}

      {loading ? (
        <AnalyticsLoading />
      ) : (
        <PostCalendar posts={visiblePosts} onSelect={onSelect} onReschedule={onReschedule} onDelete={onDelete} />
      )}
    </PostShell>
  );
}
