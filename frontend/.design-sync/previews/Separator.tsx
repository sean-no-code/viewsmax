import { Separator } from "@viewsmax/ui";

export const BetweenSections = () => (
  <div className="w-full max-w-sm">
    <div className="space-y-1">
      <h4 className="text-sm font-medium leading-none">Channel overview</h4>
      <p className="text-sm text-muted-foreground">
        Views, watch time and subscriber velocity for the last 28 days.
      </p>
    </div>
    <Separator className="my-4" />
    <div className="space-y-1">
      <h4 className="text-sm font-medium leading-none">Outliers</h4>
      <p className="text-sm text-muted-foreground">Uploads that beat your channel average by 3× or more.</p>
    </div>
  </div>
);

export const InlineLinks = () => (
  <div className="flex h-5 items-center space-x-4 text-sm">
    <a href="#" className="font-medium">
      Overview
    </a>
    <Separator orientation="vertical" />
    <a href="#" className="text-muted-foreground">
      Videos
    </a>
    <Separator orientation="vertical" />
    <a href="#" className="text-muted-foreground">
      Audience
    </a>
    <Separator orientation="vertical" />
    <a href="#" className="text-muted-foreground">
      Revenue
    </a>
  </div>
);

export const MetricRow = () => (
  <div className="flex h-10 items-center space-x-6">
    <div>
      <p className="text-xs text-muted-foreground">Views</p>
      <p className="font-display text-lg tabular-nums">2.4M</p>
    </div>
    <Separator orientation="vertical" />
    <div>
      <p className="text-xs text-muted-foreground">Subscribers</p>
      <p className="font-display text-lg tabular-nums">18.2K</p>
    </div>
    <Separator orientation="vertical" />
    <div>
      <p className="text-xs text-muted-foreground">Outlier score</p>
      <p className="font-display text-lg tabular-nums">94</p>
    </div>
  </div>
);
