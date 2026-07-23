// Post — Drafts list.
import { PostListView } from "@/components/post/PostList";

export default function Drafts() {
  return <PostListView status="draft" eyebrow="POST" title="Drafts." emptyLabel="No drafts yet — start one from New Post." />;
}
