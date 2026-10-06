import { Button } from "@viewsmax/ui";
import { ArrowRight, Plus, Sparkles } from "lucide-react";

export const Variants = () => (
  <div className="flex flex-wrap items-center gap-3">
    <Button>Analyze channel</Button>
    <Button variant="secondary">See the trend</Button>
    <Button variant="outline">Save outlier</Button>
    <Button variant="ghost">Dismiss</Button>
    <Button variant="link">View all posts</Button>
    <Button variant="destructive">Disconnect</Button>
  </div>
);

export const HeroAndCta = () => (
  <div className="flex flex-wrap items-center gap-3">
    <Button variant="hero" size="lg">
      Start free trial <ArrowRight className="ml-2 h-4 w-4" />
    </Button>
    <Button variant="cta">Upgrade to Pro</Button>
  </div>
);

export const Sizes = () => (
  <div className="flex flex-wrap items-center gap-3">
    <Button size="sm">Small</Button>
    <Button>Default</Button>
    <Button size="lg">Large</Button>
    <Button size="icon" aria-label="Add post">
      <Plus className="h-4 w-4" />
    </Button>
  </div>
);

export const WithIconAndDisabled = () => (
  <div className="flex flex-wrap items-center gap-3">
    <Button>
      <Sparkles className="mr-2 h-4 w-4" /> Generate breakdown
    </Button>
    <Button disabled>Publishing…</Button>
    <Button variant="outline" disabled>
      Disconnect
    </Button>
  </div>
);
