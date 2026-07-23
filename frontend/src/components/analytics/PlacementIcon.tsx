// One platform icon for the monetization screens, matching the New Post
// composer: brand platforms get the same PAvatar circle (BrandIcon glyphs),
// non-social placements (beehiiv, email, blog…) fall back to the analytics
// PlatformGlyph so every placement still renders something.
import { PAvatar, PMAP } from "@/components/post/composer";
import { PlatformGlyph } from "@/components/analytics/primitives";

// Monetization placement keys → composer platform ids ("video" is YouTube).
const PLACEMENT_TO_PLATFORM: Record<string, string> = {
  video: "youtube",
  youtube: "youtube",
  tiktok: "tiktok",
  instagram: "instagram",
  x: "x",
  linkedin: "linkedin",
  facebook: "facebook",
  threads: "threads",
};

export function PlacementIcon({ placement, size = 22 }: { placement: string; size?: number }) {
  const platformId = PLACEMENT_TO_PLATFORM[placement];
  if (platformId && PMAP[platformId]) {
    return <PAvatar id={platformId} size={size} />;
  }
  return <PlatformGlyph id={placement} size={size} radius={Math.round(size * 0.3)} />;
}
