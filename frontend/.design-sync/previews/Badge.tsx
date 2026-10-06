import { Badge } from "@viewsmax/ui";
import { CheckCircle2, Flame } from "lucide-react";

export const Variants = () => (
  <div className="flex flex-wrap items-center gap-2">
    <Badge>Pro</Badge>
    <Badge variant="secondary">Trial</Badge>
    <Badge variant="destructive">Failed</Badge>
    <Badge variant="outline">Draft</Badge>
  </div>
);

export const Platforms = () => (
  <div className="flex flex-wrap items-center gap-2">
    <Badge variant="outline">YouTube</Badge>
    <Badge variant="outline">TikTok</Badge>
    <Badge variant="outline">Instagram</Badge>
    <Badge variant="secondary">Shorts</Badge>
  </div>
);

export const PostStatus = () => (
  <div className="flex flex-wrap items-center gap-2">
    <Badge>Published</Badge>
    <Badge variant="secondary">Scheduled</Badge>
    <Badge variant="outline">Draft</Badge>
    <Badge variant="destructive">Failed</Badge>
  </div>
);

export const WithIcon = () => (
  <div className="flex flex-wrap items-center gap-2">
    <Badge className="gap-1">
      <Flame className="h-3 w-3" /> Outlier 94
    </Badge>
    <Badge variant="secondary" className="gap-1">
      <CheckCircle2 className="h-3 w-3" /> Connected
    </Badge>
  </div>
);
