// Post — Calendar of scheduled / posted content.
import { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import { SectionHead } from "@/components/analytics/primitives";
import { AnalyticsLoading } from "@/components/analytics/useAnalytics";
import { PostShell } from "@/components/post/PostList";
import PostCalendar from "@/components/post/PostCalendar";
import { viewsMaxApi, type Post } from "@/lib/api-service";

export default function CalendarPage() {
  const navigate = useNavigate();
  const [posts, setPosts] = useState<Post[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    viewsMaxApi.getPosts().then((res) => {
      setPosts(res.success && res.data ? res.data : []);
      setLoading(false);
    });
  }, []);

  // Click a draft/scheduled post to edit it; posted ones are done, so leave them.
  const onSelect = (p: Post) => {
    if (p.status !== "posted") navigate(`/dashboard/post/${p.id}`);
  };

  return (
    <PostShell>
      <SectionHead eyebrow="POST" title="Calendar." />
      {loading ? <AnalyticsLoading /> : <PostCalendar posts={posts} onSelect={onSelect} />}
    </PostShell>
  );
}
