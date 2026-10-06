import { Progress } from "@viewsmax/ui";

export const TrialDays = () => (
  <div className="w-full max-w-sm space-y-2">
    <div className="flex items-center justify-between text-sm">
      <span className="font-medium">Trial: 9 of 14 days</span>
      <span className="tabular-nums text-muted-foreground">64%</span>
    </div>
    <Progress value={64} />
  </div>
);

export const Uploading = () => (
  <div className="w-full max-w-sm space-y-2">
    <div className="flex items-center justify-between text-sm">
      <span className="font-medium">Uploading Short to YouTube</span>
      <span className="tabular-nums text-muted-foreground">24%</span>
    </div>
    <Progress value={24} />
  </div>
);

export const Complete = () => (
  <div className="w-full max-w-sm space-y-2">
    <div className="flex items-center justify-between text-sm">
      <span className="font-medium">Instagram Reel published</span>
      <span className="tabular-nums text-up">100%</span>
    </div>
    <Progress value={100} />
  </div>
);

export const Goals = () => (
  <div className="w-full max-w-sm space-y-4">
    <div className="space-y-1">
      <div className="flex items-center justify-between text-sm">
        <span>Views goal</span>
        <span className="tabular-nums text-muted-foreground">1.6M of 2.4M</span>
      </div>
      <Progress value={67} className="h-2" />
    </div>
    <div className="space-y-1">
      <div className="flex items-center justify-between text-sm">
        <span>Posts this month</span>
        <span className="tabular-nums text-muted-foreground">18 of 30</span>
      </div>
      <Progress value={60} className="h-2" />
    </div>
    <div className="space-y-1">
      <div className="flex items-center justify-between text-sm">
        <span>Outliers reviewed</span>
        <span className="tabular-nums text-muted-foreground">3 of 12</span>
      </div>
      <Progress value={25} className="h-2" />
    </div>
  </div>
);
