import { Alert, AlertDescription, AlertTitle, Button } from "@viewsmax/ui";
import { AlertCircle, Info, Sparkles } from "lucide-react";

export const Default = () => (
  <Alert className="max-w-md">
    <Info className="h-4 w-4" />
    <AlertTitle>Trial ends in 3 days</AlertTitle>
    <AlertDescription>
      Upgrade to Pro to keep outlier alerts and scheduled publishing running after your trial.
    </AlertDescription>
  </Alert>
);

export const Destructive = () => (
  <Alert variant="destructive" className="max-w-md">
    <AlertCircle className="h-4 w-4" />
    <AlertTitle>TikTok disconnected</AlertTitle>
    <AlertDescription>
      Your TikTok token expired, so 2 scheduled posts could not publish. Reconnect the account to resume.
    </AlertDescription>
  </Alert>
);

export const WithAction = () => (
  <Alert className="max-w-md">
    <Sparkles className="h-4 w-4" />
    <AlertTitle>New outlier on a channel you follow</AlertTitle>
    <AlertDescription className="space-y-3">
      <p>A 12K-subscriber channel just hit 4.1M views on a Short. The breakdown is ready.</p>
      <div className="flex gap-2">
        <Button size="sm">Read breakdown</Button>
        <Button size="sm" variant="ghost">
          Later
        </Button>
      </div>
    </AlertDescription>
  </Alert>
);
