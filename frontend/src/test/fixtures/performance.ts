// A small GET /api/analytics/performance payload: 7-day range (14 days incl.
// the previous period) with a YouTube account that has data, an X account that
// needs reconnecting, a LinkedIn account the API can't serve, and a TikTok
// account whose last fetch failed.
import type { PerformanceData, PerformanceAccount } from "@/lib/api-service";

const days = (n: number, end: string) => {
  const e = new Date(end + "T00:00:00");
  return Array.from({ length: n }, (_, i) => { const d = new Date(e); d.setDate(d.getDate() - (n - 1 - i)); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`; });
};
const zeros = (n: number) => Array.from({ length: n }, () => 0);

const base = (over: Partial<PerformanceAccount>): PerformanceAccount => ({
  id: 0, platform: "youtube", name: null, username: null, avatar_url: null, profile_url: null, status: "connected",
  last_error: null, supported: true, follower_stats_error: null, post_stats_error: null,
  followers: Array.from({ length: 14 }, () => null), engagement: { likes: zeros(14), comments: zeros(14), shares: zeros(14), views: zeros(14) },
  ...over,
});

export const DAYS = days(14, "2026-10-08"); // 2026-09-25 … 2026-10-08
export const FIXTURE: PerformanceData = {
  from: "2026-10-02",
  to: "2026-10-08",
  days: DAYS,
  accounts: [
    base({
      id: 1, platform: "youtube", name: "Sean Creates", username: "@seancreates",
      // prev period 100→107, current 108→120
      followers: [100, 101, 102, 103, 104, 105, 106, 107, 108, 110, 112, 115, 118, 120],
      engagement: {
        likes: [1, 1, 1, 1, 1, 1, 1, 5, 5, 5, 5, 5, 5, 5],
        comments: [0, 0, 0, 0, 0, 0, 0, 1, 1, 1, 1, 1, 1, 1],
        shares: zeros(14),
        views: [10, 10, 10, 10, 10, 10, 10, 100, 100, 100, 100, 100, 100, 100],
      },
    }),
    base({ id: 2, platform: "x", name: "Sean", username: "@sean_x", status: "needs_reauth", last_error: "Token revoked" }),
    base({ id: 3, platform: "linkedin", name: "Sean Facer", supported: false }),
    base({ id: 4, platform: "tiktok", username: "@seantok", follower_stats_error: "HTTP 403 from TikTok" }),
    base({ id: 5, platform: "instagram", username: "@sean.ig" }),
  ],
  posts: [
    { id: "youtube|a", account_id: 1, platform: "youtube", remote_post_id: "a", url: "https://youtu.be/a", caption: "Thumbnail test results", published_at: "2026-10-03T10:00:00+00:00", likes: 30, comments: 5, shares: 1, views: 600 },
    { id: "youtube|b", account_id: 1, platform: "youtube", remote_post_id: "b", url: "https://youtu.be/b", caption: "Channel audit", published_at: "2026-10-06T10:00:00+00:00", likes: 8, comments: 2, shares: 0, views: 100 },
    { id: "x|c", account_id: 2, platform: "x", remote_post_id: "c", url: null, caption: "A thread on retention", published_at: "2026-10-05T10:00:00+00:00", likes: 12, comments: 3, shares: 4, views: 0 },
  ],
};
