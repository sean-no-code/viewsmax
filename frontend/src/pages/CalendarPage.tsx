// Content Calendar — everything scheduled to go out, in one month grid,
// with filter chips at the top to narrow by platform.
import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { useTranslation } from "react-i18next";
import { SectionHead } from "@/components/analytics/primitives";
import { AnalyticsLoading } from "@/components/analytics/useAnalytics";
import { PostShell } from "@/components/post/PostList";
import PostCalendar from "@/components/post/PostCalendar";
import { PAvatar, PMAP } from "@/components/post/composer";
import { viewsMaxApi, type Post } from "@/lib/api-service";

const chipStyle = (active: boolean) => ({
  display: "inline-flex", alignItems: "center", gap: 6, padding: "6px 12px", borderRadius: 999, cursor: "pointer",
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

  return (
    <PostShell max={1280}>
      <SectionHead eyebrow={t("post.calendar.eyebrow", "CONTENT")} title={t("post.calendar.title", "Calendar.")} />

      {/* Filters: which platforms show on the grid. */}
      {platformsPresent.length > 0 && (
        <div style={{ display: "flex", alignItems: "center", gap: 8, flexWrap: "wrap" }}>
          {platformsPresent.map((id) => (
            <button key={id} style={{ ...chipStyle(platformSel.has(id)), paddingLeft: 6 }} onClick={() => togglePlatform(id)} title={t("post.calendar.filterOnlyPlatform", "Only show posts going to {{platform}}", { platform: PMAP[id].name })}>
              <PAvatar id={id} size={18} /> {PMAP[id].name}
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
        <PostCalendar posts={visiblePosts} onSelect={onSelect} />
      )}
    </PostShell>
  );
}
