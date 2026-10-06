import { Button, Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "@viewsmax/ui";
import { Info } from "lucide-react";

export const Open = () => (
  <TooltipProvider>
    <div className="flex items-center gap-2 pt-16">
      <span className="text-sm font-medium">Outlier score</span>
      <Tooltip open>
        <TooltipTrigger asChild>
          <Button variant="ghost" size="icon" aria-label="What is outlier score?">
            <Info className="h-4 w-4" />
          </Button>
        </TooltipTrigger>
        <TooltipContent>
          <p>Views compared with the channel's median over the last 90 days.</p>
        </TooltipContent>
      </Tooltip>
    </div>
  </TooltipProvider>
);

export const Closed = () => (
  <TooltipProvider>
    <Tooltip>
      <TooltipTrigger asChild>
        <Button variant="outline" disabled>
          Generate script
        </Button>
      </TooltipTrigger>
      <TooltipContent>
        <p>Upgrade to Pro for longer scripts</p>
      </TooltipContent>
    </Tooltip>
  </TooltipProvider>
);
