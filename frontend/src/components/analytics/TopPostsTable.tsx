// Engagement-ranked posts for the Audience Growth page. Posts are ordered by
// total engagement (likes+comments+shares+views), each showing its day-over-day
// delta and a breakdown. Metrics are refreshed daily by posts:refresh-metrics.
import { type CSSProperties } from "react";
import { CARD } from "@/components/analytics/primitives";
import { PlacementIcon } from "@/components/analytics/PlacementIcon";
import { fmtFull } from "@/lib/analytics-model";
import type { TopPost } from "@/lib/api-service";

const PLATFORM_LABEL: Record<string, string> = {
  youtube: "YouTube", tiktok: "TikTok", instagram: "Instagram",
  x: "X", linkedin: "LinkedIn", threads: "Threads", facebook: "Facebook",
};

const muted: CSSProperties = { fontFamily: "var(--font-body)", fontSize: 12.5, color: "var(--ink-on-paper-3)" };

function DeltaPill({ value }: { value: number }) {
  const up = value > 0, flat = value === 0;
  const color = flat ? "var(--ink-on-paper-3)" : up ? "var(--up)" : "var(--vm-red)";
  return (
    <span style={{ fontFamily: "var(--font-mono)", fontSize: 11, fontWeight: 700, color, whiteSpace: "nowrap" }}>
      {up ? "▲ +" : flat ? "– " : "▼ "}{fmtFull(Math.abs(value))}
    </span>
  );
}

export function TopPostsTable({ posts, loading }: { posts: TopPost[]; loading: boolean }) {
  return (
    <div style={CARD}>
      <div style={{ padding: "14px 18px 10px", borderBottom: "1px solid var(--line-1)" }}>
        <div style={{ fontFamily: "var(--font-display)", fontWeight: 700, fontSize: 15.5, color: "var(--ink-on-paper-1)" }}>Top posts by engagement</div>
        <div style={{ ...muted, marginTop: 2 }}>Your published posts, ranked by total engagement. Δ is the change since yesterday.</div>
      </div>

      {loading ? (
        <div style={{ padding: 24, textAlign: "center", ...muted }}>Loading posts…</div>
      ) : posts.length === 0 ? (
        <div style={{ padding: 24, textAlign: "center", ...muted }}>
          No post metrics yet. Once you've published through ViewsMax, engagement is captured daily.
        </div>
      ) : (
        posts.map((p, idx) => {
          const label = PLATFORM_LABEL[p.platform] ?? p.platform;
          const when = p.published_at ? new Date(p.published_at).toLocaleDateString() : null;
          const breakdown = [
            `${fmtFull(p.likes)} likes`,
            `${fmtFull(p.comments)} comments`,
            p.shares ? `${fmtFull(p.shares)} shares` : null,
            p.views ? `${fmtFull(p.views)} views` : null,
          ].filter(Boolean).join(" · ");
          return (
            <div key={`${p.platform}-${p.remote_post_id}`} style={{ display: "flex", alignItems: "center", gap: 14, padding: "12px 18px", background: idx % 2 === 1 ? "var(--paper-1)" : "transparent" }}>
              <PlacementIcon placement={p.platform} size={24} />
              <div style={{ minWidth: 0, flex: 1 }}>
                <a
                  href={p.url ?? undefined}
                  target="_blank"
                  rel="noopener noreferrer"
                  title={p.caption ?? undefined}
                  style={{ display: "block", fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 13.5, color: "var(--ink-on-paper-1)", textDecoration: "none", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}
                >
                  {p.caption || `${label} post`}
                </a>
                <div style={{ ...muted, fontSize: 11.5, marginTop: 2, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>
                  {label}{when ? ` · ${when}` : ""} · {breakdown}
                </div>
              </div>
              <div style={{ textAlign: "right", flexShrink: 0 }}>
                <div style={{ fontFamily: "var(--font-mono)", fontWeight: 700, fontSize: 15, color: "var(--ink-on-paper-1)", fontVariantNumeric: "tabular-nums" }}>{fmtFull(p.engagement_total)}</div>
                <DeltaPill value={p.engagement_delta} />
              </div>
            </div>
          );
        })
      )}
    </div>
  );
}
