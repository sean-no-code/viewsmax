import {
  Button,
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@viewsmax/ui";
import { ChevronDown, CreditCard, LogOut, MessageCircle, Settings } from "lucide-react";

export const Open = () => (
  <DropdownMenu open>
    <DropdownMenuTrigger asChild>
      <Button variant="ghost" size="sm" className="gap-2">
        Maya Chen <ChevronDown className="h-4 w-4" />
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="start" className="w-56">
      <DropdownMenuLabel>Pro plan · 3 channels</DropdownMenuLabel>
      <DropdownMenuSeparator />
      <DropdownMenuItem>
        <Settings className="mr-2 h-4 w-4" /> Settings
      </DropdownMenuItem>
      <DropdownMenuItem>
        <CreditCard className="mr-2 h-4 w-4" /> Billing
      </DropdownMenuItem>
      <DropdownMenuItem>
        <MessageCircle className="mr-2 h-4 w-4" /> Discord community
      </DropdownMenuItem>
      <DropdownMenuSeparator />
      <DropdownMenuItem>
        <LogOut className="mr-2 h-4 w-4" /> Sign out
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>
);

export const Closed = () => (
  <DropdownMenu>
    <DropdownMenuTrigger asChild>
      <Button variant="outline" size="sm" className="gap-2">
        Free tools <ChevronDown className="h-4 w-4" />
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="start">
      <DropdownMenuItem>YouTube monetization calculator</DropdownMenuItem>
      <DropdownMenuItem>TikTok engagement calculator</DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>
);
