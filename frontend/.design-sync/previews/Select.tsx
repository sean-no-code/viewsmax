import { Label, Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@viewsmax/ui";

export const WithLabel = () => (
  <div className="grid w-full max-w-sm gap-2">
    <Label htmlFor="platform">Platform</Label>
    <Select defaultValue="youtube">
      <SelectTrigger id="platform">
        <SelectValue placeholder="Choose a platform" />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value="youtube">YouTube</SelectItem>
        <SelectItem value="tiktok">TikTok</SelectItem>
        <SelectItem value="instagram">Instagram</SelectItem>
      </SelectContent>
    </Select>
  </div>
);

export const Placeholder = () => (
  <div className="grid w-full max-w-sm gap-2">
    <Label htmlFor="date-range">Date range</Label>
    <Select>
      <SelectTrigger id="date-range">
        <SelectValue placeholder="Select a range" />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value="7d">Last 7 days</SelectItem>
        <SelectItem value="28d">Last 28 days</SelectItem>
        <SelectItem value="90d">Last 90 days</SelectItem>
        <SelectItem value="365d">Last 12 months</SelectItem>
      </SelectContent>
    </Select>
  </div>
);

export const Disabled = () => (
  <div className="grid w-full max-w-sm gap-2">
    <Label htmlFor="brand">Brand</Label>
    <Select defaultValue="main" disabled>
      <SelectTrigger id="brand">
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value="main">Main channel</SelectItem>
        <SelectItem value="clips">Clips channel</SelectItem>
      </SelectContent>
    </Select>
    <p className="text-xs text-muted-foreground">Add more brands on the Pro plan.</p>
  </div>
);
