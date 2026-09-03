// Content Calendar — everything scheduled to go out, in one month grid:
// social posts (per-platform) AND SEO blog articles (published + projected).
// Filter chips at the top narrow by platform or content type.
import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { SectionHead } from "@/components/analytics/primitives";
import { AnalyticsLoading } from "@/components/analytics/useAnalytics";
import { PostShell } from "@/components/post/PostList";
import PostCalendar, { type CalendarBlogEntry } from "@/components/post/PostCalendar";
import { PAvatar, PMAP } from "@/components/post/composer";
import { viewsMaxApi, type Post, type SeoArticleRow } from "@/lib/api-service";

const dayKey = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;

/**
 * Place articles on the grid: published ones on their real date; queued/review
 * ones projected forward one per day starting tomorrow (the pipeline publishes
 * up to the profile's weekly cap, so this is the earliest-case plan).
 */
function toBlogEntries(articles: SeoArticleRow[]): CalendarBlogEntry[] {
  const entries: CalendarBlogEntry[] = [];
  const planned = articles.filter((a) => a.status === "queued" || a.status === "review")
    .sort((a, b) => a.created_at.localeCompare(b.created_at));
  let offset = 1;
  for (const a of articles) {
    if (a.status === "failed") continue;
    let date: string;
    if (a.status === "published" && a.published_at) {
      date = dayKey(new Date(a.published_at));
    } else if (planned.includes(a)) {
      const d = new Date();
      d.setDate(d.getDate() + offset++);
      date = dayKey(d);
    } else {
      continue;
    }
    entries.push({
      id: a.id,
      date,
      title: a.title,
      category: a.category,
      vol: a.keyword?.search_volume ?? null,
      diff: a.keyword?.difficulty ?? null,
      published: a.status === "published",
      url: a.published_url,
    });
  }
  return entries;
}

const chipStyle = (active: boolean) => ({
  display: "inline-flex", alignItems: "center", gap: 6, padding: "6px 12px", borderRadius: 999, cursor: "pointer",
  border: "1px solid " + (active ? "var(--ink-on-paper-1)" : "var(--line-1)"),
  background: active ? "var(--ink-on-paper-1)" : "var(--paper-0)",
  color: active ? "#fff" : "var(--ink-on-paper-2)",
  fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 12.5,
} as const);

export default function CalendarPage() {
  const navigate = useNavigate();
  const [posts, setPosts] = useState<Post[]>([]);
  const [blog, setBlog] = useState<CalendarBlogEntry[]>([]);
  const [loading, setLoading] = useState(true);

  // Filters: content types + platforms. Empty platform set = all platforms.
  const [showPosts, setShowPosts] = useState(true);
  const [showBlog, setShowBlog] = useState(true);
  const [platformSel, setPlatformSel] = useState<Set<string>>(new Set());

  useEffect(() => {
    (async () => {
      const [postsRes, profilesRes] = await Promise.all([
        viewsMaxApi.getPosts(),
        viewsMaxApi.getSeoProfiles(),
      ]);
      setPosts(postsRes.success && postsRes.data ? postsRes.data : []);
      if (profilesRes.success && profilesRes.data) {
        const articleLists = await Promise.all(
          profilesRes.data.map((p) => viewsMaxApi.getSeoArticles(p.id)),
        );
        setBlog(toBlogEntries(articleLists.flatMap((r) => (r.success && r.data ? r.data : []))));
      }
      setLoading(false);
    })();
  }, []);

  const platformsPresent = useMemo(
    () => [...new Set(posts.flatMap((p) => (p.targets || []).map((t) => t.platform)))].filter((id) => PMAP[id]),
    [posts],
  );

  const togglePlatform = (id: string) => setPlatformSel((s) => {
    const n = new Set(s);
    if (n.has(id)) n.delete(id); else n.add(id);
    return n;
  });

  const visiblePosts = useMemo(() => {
    if (!showPosts) return [];
    if (platformSel.size === 0) return posts;
    return posts.filter((p) => (p.targets || []).some((t) => platformSel.has(t.platform)));
  }, [posts, showPosts, platformSel]);

  const onSelect = (p: Post) => {
    if (p.status !== "posted") navigate(`/dashboard/post/${p.id}`);
  };

  return (
    <PostShell max={1280}>
      <SectionHead eyebrow="CONTENT" title="Calendar." />

      {/* Filters: what shows on the grid. */}
      <div style={{ display: "flex", alignItems: "center", gap: 8, flexWrap: "wrap" }}>
        <button style={chipStyle(showPosts)} onClick={() => setShowPosts((v) => !v)}>Posts</button>
        <button style={chipStyle(showBlog)} onClick={() => setShowBlog((v) => !v)}>Blog articles</button>
        {platformsPresent.length > 0 && <span style={{ width: 1, height: 22, background: "var(--line-1)", margin: "0 4px" }} />}
        {platformsPresent.map((id) => (
          <button key={id} style={{ ...chipStyle(platformSel.has(id)), paddingLeft: 6 }} onClick={() => togglePlatform(id)} title={`Only show posts going to ${PMAP[id].name}`}>
            <PAvatar id={id} size={18} /> {PMAP[id].name}
          </button>
        ))}
        {platformSel.size > 0 && (
          <button style={{ background: "none", border: "none", cursor: "pointer", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 12, color: "var(--vm-red)" }} onClick={() => setPlatformSel(new Set())}>
            Clear platforms
          </button>
        )}
      </div>

      {loading ? (
        <AnalyticsLoading />
      ) : (
        <PostCalendar
          posts={visiblePosts}
          blog={showBlog ? blog : []}
          onSelect={onSelect}
          onSelectBlog={(b) => (b.published && b.url ? window.open(b.url, "_blank", "noopener") : navigate("/dashboard/seo"))}
        />
      )}
      <div style={{ fontFamily: "var(--font-body)", fontSize: 12, color: "var(--ink-on-paper-3)", lineHeight: 1.5 }}>
        Blog cards on future days are the SEO engine's projected publish plan (queued and in-review drafts) — click one to
        manage it on the SEO page. Published articles open on your blog.
      </div>
    </PostShell>
  );
}
