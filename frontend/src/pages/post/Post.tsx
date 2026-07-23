// Post composer (Split Studio) on the warm canvas. With an :id route param it
// edits an existing post; without one it's a fresh New Post.
import { useParams } from "react-router-dom";
import CreatePost from "@/components/post/CreatePost";
import { PostShell } from "@/components/post/PostList";

export default function Post() {
  const { id } = useParams();
  return (
    <PostShell max={1560}>
      <CreatePost editId={id ? Number(id) : undefined} />
    </PostShell>
  );
}
