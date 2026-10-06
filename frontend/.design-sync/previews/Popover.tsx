import { Button, Input, Label, Popover, PopoverContent, PopoverTrigger } from "@viewsmax/ui";
import { Copy, Share2 } from "lucide-react";

export const Open = () => (
  <Popover open>
    <PopoverTrigger asChild>
      <Button variant="outline">
        <Share2 className="mr-2 h-4 w-4" /> Share breakdown
      </Button>
    </PopoverTrigger>
    <PopoverContent align="start" className="w-80">
      <div className="space-y-4">
        <div className="space-y-1">
          <h4 className="font-medium leading-none">Share this breakdown</h4>
          <p className="text-sm text-muted-foreground">Anyone with the link can read it. No sign-in needed.</p>
        </div>
        <div className="space-y-2">
          <Label htmlFor="share-link">Link</Label>
          <div className="flex gap-2">
            <Input id="share-link" readOnly defaultValue="viewsmax.com/o/cold-plunge-5am" />
            <Button size="icon" variant="secondary" aria-label="Copy link">
              <Copy className="h-4 w-4" />
            </Button>
          </div>
        </div>
      </div>
    </PopoverContent>
  </Popover>
);

export const Closed = () => (
  <Popover>
    <PopoverTrigger asChild>
      <Button variant="outline">Last 28 days</Button>
    </PopoverTrigger>
    <PopoverContent align="start" className="w-80">
      <p className="text-sm text-muted-foreground">Pick a date range for the tracking report.</p>
    </PopoverContent>
  </Popover>
);
