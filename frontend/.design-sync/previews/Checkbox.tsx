import { Checkbox, Label } from "@viewsmax/ui";

export const WithLabel = () => (
  <div className="flex items-center gap-2">
    <Checkbox id="notify-failures" />
    <Label htmlFor="notify-failures">Email me when a post fails to publish</Label>
  </div>
);

export const CheckedGroup = () => (
  <div className="grid gap-3">
    <p className="text-sm font-medium">Publish to</p>
    <div className="flex items-center gap-2">
      <Checkbox id="pub-youtube" defaultChecked />
      <Label htmlFor="pub-youtube">YouTube Shorts</Label>
    </div>
    <div className="flex items-center gap-2">
      <Checkbox id="pub-tiktok" defaultChecked />
      <Label htmlFor="pub-tiktok">TikTok</Label>
    </div>
    <div className="flex items-center gap-2">
      <Checkbox id="pub-instagram" />
      <Label htmlFor="pub-instagram">Instagram Reels</Label>
    </div>
  </div>
);

export const WithDescription = () => (
  <div className="flex items-start gap-3">
    <Checkbox id="accept-terms" className="mt-1" />
    <div className="grid gap-1">
      <Label htmlFor="accept-terms">I have read the privacy policy</Label>
      <p className="text-sm text-muted-foreground">
        We only read analytics and never post without a scheduled or explicit publish.
      </p>
    </div>
  </div>
);

export const Disabled = () => (
  <div className="grid gap-3">
    <div className="flex items-center gap-2">
      <Checkbox id="locked-off" disabled />
      <Label htmlFor="locked-off">Auto-reply to comments (Pro)</Label>
    </div>
    <div className="flex items-center gap-2">
      <Checkbox id="locked-on" defaultChecked disabled />
      <Label htmlFor="locked-on">Track outlier score</Label>
    </div>
  </div>
);
