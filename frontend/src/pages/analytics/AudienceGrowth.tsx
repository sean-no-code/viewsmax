// Analytics — Audience Growth. Per-platform follower growth (cards + trend) and
// the user's posts ranked by engagement, both driven by the daily snapshot
// tables (audience:refresh / posts:refresh-metrics).
import { useState } from "react";
import { AnalyticsShell, useAudienceGrowth, useTopPosts, RANGE_OPTS, type Range } from "@/components/analytics/useAnalytics";
import { SectionHead, Segmented } from "@/components/analytics/primitives";
import { AudiencePlatformCards } from "@/components/analytics/AudiencePlatformCards";
import { TopPostsTable } from "@/components/analytics/TopPostsTable";

const PLATFORM_LABEL: Record<string, string> = {
  youtube: "YouTube", tiktok: "TikTok", instagram: "Instagram",
  x: "X", linkedin: "LinkedIn", threads: "Threads", facebook: "Facebook",
};

export default function AudienceGrowth() {
  const [range, setRange] = useState<Range>("28d");
  const [platform, setPlatform] = useState<string>("all");
  const { platforms, loading } = useAudienceGrowth(range);
  const { posts, loading: postsLoading } = useTopPosts(range, platform === "all" ? undefined : platform);

  const uniquePlatforms = Array.from(new Set(platforms.map((p) => p.platform)));
  const platformOpts = [
    { id: "all", label: "All" },
    ...uniquePlatforms.map((pl) => ({ id: pl, label: PLATFORM_LABEL[pl] ?? pl })),
  ];

  return (
    <AnalyticsShell>
      <SectionHead
        eyebrow="AUDIENCE"
        title="Audience growth."
        right={<Segmented options={RANGE_OPTS} value={range} onChange={(v) => setRange(v as Range)} />}
      />

      <AudiencePlatformCards platforms={platforms} loading={loading} />

      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 12, flexWrap: "wrap", marginTop: 6 }}>
        <div style={{ fontFamily: "var(--font-display)", fontWeight: 700, fontSize: 18, letterSpacing: "-.02em", color: "var(--ink-on-paper-1)" }}>
          Posts driving engagement
        </div>
        {platformOpts.length > 1 && (
          <Segmented options={platformOpts} value={platform} onChange={setPlatform} mono={false} />
        )}
      </div>

      <TopPostsTable posts={posts} loading={postsLoading} />
    </AnalyticsShell>
  );
}
