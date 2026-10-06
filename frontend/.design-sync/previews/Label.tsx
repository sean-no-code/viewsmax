import { Checkbox, Input, Label, Switch } from "@viewsmax/ui";

export const WithInput = () => (
  <div className="grid w-full max-w-sm gap-2">
    <Label htmlFor="channel-handle">Channel handle</Label>
    <Input id="channel-handle" placeholder="@yourchannel" />
  </div>
);

export const Required = () => (
  <div className="grid w-full max-w-sm gap-2">
    <Label htmlFor="post-title">
      Title <span className="text-destructive">*</span>
    </Label>
    <Input id="post-title" placeholder="Give your Short a title" />
    <p className="text-xs text-muted-foreground">Required for YouTube. Max 100 characters.</p>
  </div>
);

export const WithCheckbox = () => (
  <div className="flex items-center gap-2">
    <Checkbox id="save-outlier" defaultChecked />
    <Label htmlFor="save-outlier">Save this outlier to my library</Label>
  </div>
);

export const WithSwitch = () => (
  <div className="flex items-center gap-3">
    <Switch id="digest" defaultChecked />
    <Label htmlFor="digest">Send weekly digest</Label>
  </div>
);

export const Disabled = () => (
  <div className="grid w-full max-w-sm gap-2">
    <Label htmlFor="tracking-domain">Custom tracking domain</Label>
    <Input id="tracking-domain" placeholder="links.yourbrand.com" disabled />
    <p className="text-xs text-muted-foreground">Available on Pro.</p>
  </div>
);
