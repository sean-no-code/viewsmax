import { Badge, Button, Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from "@viewsmax/ui";
import { TrendingUp } from "lucide-react";

export const MetricCard = () => (
  <Card className="max-w-sm">
    <CardHeader className="pb-2">
      <CardDescription>Views, last 28 days</CardDescription>
      <CardTitle className="font-display text-3xl tabular-nums">2.4M</CardTitle>
    </CardHeader>
    <CardContent>
      <p className="flex items-center gap-1 text-sm font-medium text-up">
        <TrendingUp className="h-4 w-4" /> ▲ 18% vs previous period
      </p>
    </CardContent>
  </Card>
);

export const ConnectAccount = () => (
  <Card className="max-w-md">
    <CardHeader>
      <div className="flex items-center justify-between gap-2">
        <CardTitle>Connect YouTube</CardTitle>
        <Badge variant="secondary">Recommended</Badge>
      </div>
      <CardDescription>Pull channel analytics and publish Shorts straight from ViewsMax.</CardDescription>
    </CardHeader>
    <CardContent className="text-sm text-muted-foreground">
      We request upload and read-only analytics scopes. You can disconnect at any time from Settings, and we
      never post without a scheduled or explicit publish.
    </CardContent>
    <CardFooter className="gap-2">
      <Button>Connect</Button>
      <Button variant="ghost">Not now</Button>
    </CardFooter>
  </Card>
);

export const OutlierSummary = () => (
  <Card className="max-w-md">
    <CardHeader>
      <CardDescription>Why it worked</CardDescription>
      <CardTitle>Cold plunge at 5am: 4.1M views on a 12K channel</CardTitle>
    </CardHeader>
    <CardContent className="space-y-2 text-sm">
      <p>
        The hook names the number in the first second, the thumbnail is a single face with high contrast, and the
        caption asks a question the video answers in under 20 seconds.
      </p>
      <p className="text-muted-foreground">Outlier score 94 · 340× channel average</p>
    </CardContent>
  </Card>
);
