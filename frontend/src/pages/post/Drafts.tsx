// Post — Drafts list.
import { useTranslation } from "react-i18next";
import { PostListView } from "@/components/post/PostList";

export default function Drafts() {
  const { t } = useTranslation();
  return (
    <PostListView
      status="draft"
      eyebrow={t("post.drafts.eyebrow", "POST")}
      title={t("post.drafts.title", "Drafts.")}
      emptyLabel={t("post.drafts.empty", "No drafts yet — start one from New Post.")}
    />
  );
}
