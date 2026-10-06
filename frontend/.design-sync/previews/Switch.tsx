import { Label, Switch } from "@viewsmax/ui";

export const WithLabel = () => (
  <div className="flex items-center gap-3">
    <Switch id="post-failures" defaultChecked />
    <Label htmlFor="post-failures">Email me about failed posts</Label>
  </div>
);

export const SettingsRow = () => (
  <div className="flex w-full max-w-md items-center justify-between gap-4 rounded-lg border border-border p-4">
    <div className="grid gap-1">
      <Label htmlFor="weekly-digest">Weekly outlier digest</Label>
      <p className="text-sm text-muted-foreground">One email every Monday with the top outliers in your niche.</p>
    </div>
    <Switch id="weekly-digest" />
  </div>
);

export const Disabled = () => (
  <div className="grid gap-3">
    <div className="flex items-center gap-3">
      <Switch id="auto-publish" disabled />
      <Label htmlFor="auto-publish">Auto-publish approved clips (Pro)</Label>
    </div>
    <div className="flex items-center gap-3">
      <Switch id="track-views" defaultChecked disabled />
      <Label htmlFor="track-views">Sync views every hour</Label>
    </div>
  </div>
);
