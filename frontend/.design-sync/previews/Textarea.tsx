import { Label, Textarea } from "@viewsmax/ui";

export const WithLabel = () => (
  <div className="grid w-full max-w-md gap-2">
    <Label htmlFor="caption">Caption</Label>
    <Textarea id="caption" placeholder="Write the caption that goes out with this Short…" />
    <p className="text-xs text-muted-foreground">Posts to TikTok, YouTube and Instagram. 2,200 characters max.</p>
  </div>
);

export const Filled = () => (
  <div className="grid w-full max-w-md gap-2">
    <Label htmlFor="caption-draft">Caption draft</Label>
    <Textarea
      id="caption-draft"
      rows={4}
      defaultValue="I filmed a cold plunge at 5am for 30 days. Day 1 vs day 30 is not what I expected. Full breakdown on the channel. #coldplunge #shorts"
    />
  </div>
);

export const Disabled = () => (
  <div className="grid w-full max-w-md gap-2">
    <Label htmlFor="notes-locked">Internal notes</Label>
    <Textarea id="notes-locked" placeholder="Upgrade to Pro to add notes to outliers" disabled />
  </div>
);
