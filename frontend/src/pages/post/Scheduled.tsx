// Post — Scheduled list.
import { useTranslation } from "react-i18next";
import { PostListView } from "@/components/post/PostList";

export default function Scheduled() {
  const { t } = useTranslation();
  return (
    <PostListView
      status="scheduled"
      eyebrow={t("post.scheduled.eyebrow", "POST")}
      title={t("post.scheduled.title", "Scheduled.")}
      emptyLabel={t("post.scheduled.empty", "Nothing scheduled — schedule a post from New Post.")}
    />
  );
}
