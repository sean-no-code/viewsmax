import { Button, Input, Label } from "@viewsmax/ui";
import { Search } from "lucide-react";

export const WithLabel = () => (
  <div className="grid w-full max-w-sm gap-2">
    <Label htmlFor="channel-url">Channel URL</Label>
    <Input id="channel-url" placeholder="https://youtube.com/@yourchannel" />
  </div>
);

export const SearchBar = () => (
  <div className="flex w-full max-w-md items-center gap-2">
    <Input placeholder="Search outliers, e.g. cold plunge" />
    <Button type="submit" size="icon" aria-label="Search">
      <Search className="h-4 w-4" />
    </Button>
  </div>
);

export const States = () => (
  <div className="grid w-full max-w-sm gap-3">
    <Input defaultValue="creator@viewsmax.com" readOnly />
    <Input placeholder="Disabled" disabled />
    <Input type="number" placeholder="Min views" />
  </div>
);
