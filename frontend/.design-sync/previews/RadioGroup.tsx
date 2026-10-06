import { Label, RadioGroup, RadioGroupItem } from "@viewsmax/ui";

export const Privacy = () => (
  <div className="grid gap-3">
    <p className="text-sm font-medium">YouTube visibility</p>
    <RadioGroup defaultValue="public">
      <div className="flex items-center gap-2">
        <RadioGroupItem value="public" id="vis-public" />
        <Label htmlFor="vis-public">Public</Label>
      </div>
      <div className="flex items-center gap-2">
        <RadioGroupItem value="unlisted" id="vis-unlisted" />
        <Label htmlFor="vis-unlisted">Unlisted</Label>
      </div>
      <div className="flex items-center gap-2">
        <RadioGroupItem value="private" id="vis-private" />
        <Label htmlFor="vis-private">Private</Label>
      </div>
    </RadioGroup>
  </div>
);

export const WithDescriptions = () => (
  <RadioGroup defaultValue="schedule" className="w-full max-w-md gap-3">
    <div className="flex items-start gap-3 rounded-lg border border-border p-3">
      <RadioGroupItem value="now" id="when-now" className="mt-1" />
      <div className="grid gap-1">
        <Label htmlFor="when-now">Publish now</Label>
        <p className="text-sm text-muted-foreground">Goes out to every selected platform immediately.</p>
      </div>
    </div>
    <div className="flex items-start gap-3 rounded-lg border border-border p-3">
      <RadioGroupItem value="schedule" id="when-schedule" className="mt-1" />
      <div className="grid gap-1">
        <Label htmlFor="when-schedule">Schedule</Label>
        <p className="text-sm text-muted-foreground">Pick a date and time. We suggest your best-performing slot.</p>
      </div>
    </div>
  </RadioGroup>
);

export const Horizontal = () => (
  <div className="grid gap-3">
    <p className="text-sm font-medium">Sort outliers by</p>
    <RadioGroup defaultValue="score" orientation="horizontal" className="flex flex-wrap gap-4">
      <div className="flex items-center gap-2">
        <RadioGroupItem value="score" id="sort-score" />
        <Label htmlFor="sort-score">Outlier score</Label>
      </div>
      <div className="flex items-center gap-2">
        <RadioGroupItem value="views" id="sort-views" />
        <Label htmlFor="sort-views">Views</Label>
      </div>
      <div className="flex items-center gap-2">
        <RadioGroupItem value="recent" id="sort-recent" />
        <Label htmlFor="sort-recent">Most recent</Label>
      </div>
    </RadioGroup>
  </div>
);

export const Disabled = () => (
  <div className="grid gap-3">
    <p className="text-sm font-medium">Plan</p>
    <RadioGroup defaultValue="trial" disabled>
      <div className="flex items-center gap-2">
        <RadioGroupItem value="trial" id="plan-trial" />
        <Label htmlFor="plan-trial">Free trial (current)</Label>
      </div>
      <div className="flex items-center gap-2">
        <RadioGroupItem value="pro" id="plan-pro" />
        <Label htmlFor="plan-pro">Pro</Label>
      </div>
    </RadioGroup>
  </div>
);
