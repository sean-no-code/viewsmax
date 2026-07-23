// Post — Scheduled list.
import { PostListView } from "@/components/post/PostList";

export default function Scheduled() {
  return <PostListView status="scheduled" eyebrow="POST" title="Scheduled." emptyLabel="Nothing scheduled — schedule a post from New Post." />;
}
