import {
  Button,
  Checkbox,
  Label,
  Sheet,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from "@viewsmax/ui";
import { SlidersHorizontal } from "lucide-react";

export const Open = () => (
  <Sheet open>
    <SheetTrigger asChild>
      <Button variant="outline">
        <SlidersHorizontal className="mr-2 h-4 w-4" /> Filters
      </Button>
    </SheetTrigger>
    <SheetContent side="right">
      <SheetHeader>
        <SheetTitle>Filter outliers</SheetTitle>
        <SheetDescription>Narrow the feed to the platforms and formats you publish on.</SheetDescription>
      </SheetHeader>
      <div className="space-y-6 py-6">
        <div className="space-y-3">
          <p className="text-sm font-medium">Platforms</p>
          <div className="flex items-center gap-2">
            <Checkbox id="filter-youtube" defaultChecked />
            <Label htmlFor="filter-youtube">YouTube</Label>
          </div>
          <div className="flex items-center gap-2">
            <Checkbox id="filter-tiktok" defaultChecked />
            <Label htmlFor="filter-tiktok">TikTok</Label>
          </div>
          <div className="flex items-center gap-2">
            <Checkbox id="filter-instagram" />
            <Label htmlFor="filter-instagram">Instagram</Label>
          </div>
        </div>
        <div className="space-y-3">
          <p className="text-sm font-medium">Format</p>
          <div className="flex items-center gap-2">
            <Checkbox id="filter-shorts" defaultChecked />
            <Label htmlFor="filter-shorts">Shorts and Reels only</Label>
          </div>
        </div>
      </div>
      <SheetFooter>
        <Button variant="ghost">Reset</Button>
        <Button>Apply filters</Button>
      </SheetFooter>
    </SheetContent>
  </Sheet>
);

export const Closed = () => (
  <Sheet>
    <SheetTrigger asChild>
      <Button variant="ghost">Menu</Button>
    </SheetTrigger>
    <SheetContent side="right">
      <SheetHeader>
        <SheetTitle>Menu</SheetTitle>
        <SheetDescription>Features, pricing and your dashboard.</SheetDescription>
      </SheetHeader>
    </SheetContent>
  </Sheet>
);
