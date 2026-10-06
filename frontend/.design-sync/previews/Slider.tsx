import { Label, Slider } from "@viewsmax/ui";

export const WithLabel = () => (
  <div className="grid w-full max-w-md gap-3">
    <div className="flex items-center justify-between">
      <Label htmlFor="script-length">Script length</Label>
      <span className="text-sm tabular-nums text-muted-foreground">45 seconds</span>
    </div>
    <Slider id="script-length" min={1} max={60} step={1} defaultValue={[45]} />
  </div>
);

export const Range = () => (
  <div className="grid w-full max-w-md gap-3">
    <div className="flex items-center justify-between">
      <Label htmlFor="outlier-range">Outlier score</Label>
      <span className="text-sm tabular-nums text-muted-foreground">60 – 95</span>
    </div>
    <Slider id="outlier-range" min={0} max={100} step={5} defaultValue={[60, 95]} />
  </div>
);

export const Disabled = () => (
  <div className="grid w-full max-w-md gap-3">
    <div className="flex items-center justify-between">
      <Label htmlFor="posts-per-day">Posts per day</Label>
      <span className="text-sm tabular-nums text-muted-foreground">1 (trial limit)</span>
    </div>
    <Slider id="posts-per-day" min={1} max={10} step={1} defaultValue={[1]} disabled />
  </div>
);
