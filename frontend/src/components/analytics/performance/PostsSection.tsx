// Post performance: sort + layout controls, then the posts as cards or a table.
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { POST_SORTS, dayLabelYear, fmt, rateLabel, type PostSort, type PostStat } from "@/lib/analytics-performance";
import { PillToggle } from "./chart";
import { AccountCell } from "./NetBadge";

export type PostLayout = "grid" | "list";
const LAYOUTS: { id: PostLayout; label: string }[] = [{ id: "grid", label: "Grid" }, { id: "list", label: "List" }];

const PRIMARY: Record<PostSort, [string, (p: PostStat) => string]> = {
  rate: ["Engagement rate", (p) => rateLabel(p.rate)],
  eng: ["Engagements", (p) => fmt(p.eng)],
  views: ["Views", (p) => fmt(p.views)],
  shares: ["Shares", (p) => fmt(p.shares)],
  date: ["Engagement rate", (p) => rateLabel(p.rate)],
};
const STATS: [string, keyof Pick<PostStat, "views" | "eng" | "likes" | "comments" | "shares">][] = [
  ["Views", "views"], ["Engagements", "eng"], ["Likes", "likes"], ["Comments", "comments"], ["Shares", "shares"],
];
const THUMB = "repeating-linear-gradient(135deg,var(--paper-2) 0 10px,var(--paper-3) 10px 11px)";

interface Props {
  posts: PostStat[];
  sort: PostSort;
  onSortChange: (s: PostSort) => void;
  layout: PostLayout;
  onLayoutChange: (l: PostLayout) => void;
}

function Caption({ p, style }: { p: PostStat; style?: React.CSSProperties }) {
  return p.url
    ? <a href={p.url} target="_blank" rel="noopener noreferrer" style={{ color: "inherit", textDecoration: "none", ...style }}>{p.caption}</a>
    : <span style={style}>{p.caption}</span>;
}

export function PostsSection({ posts, sort, onSortChange, layout, onLayoutChange }: Props) {
  const [primaryLabel, primaryValue] = PRIMARY[sort];
  const stats = STATS.filter(([l]) => l !== primaryLabel);

  return (
    <>
      <div style={{ display: "flex", flexWrap: "wrap", gap: 12, alignItems: "center", justifyContent: "space-between" }}>
        <div style={{ fontSize: 13, color: "var(--ink-on-paper-3)" }}>{posts.length} posts published in this period</div>
        <div style={{ display: "flex", alignItems: "center", gap: 10, flexWrap: "wrap" }}>
          <span style={{ fontSize: 13, color: "var(--ink-on-paper-3)" }}>Sort by</span>
          <Select value={sort} onValueChange={(v) => onSortChange(v as PostSort)}>
            <SelectTrigger className="w-auto bg-paper-0" aria-label="Sort posts by"><SelectValue /></SelectTrigger>
            <SelectContent>
              {POST_SORTS.map((s) => <SelectItem key={s.id} value={s.id}>{s.label}</SelectItem>)}
            </SelectContent>
          </Select>
          <PillToggle options={LAYOUTS} value={layout} onChange={onLayoutChange} label="Post layout" />
        </div>
      </div>

      {posts.length === 0 && (
        <div style={{ padding: "40px 0", textAlign: "center", color: "var(--ink-on-paper-3)", fontSize: 14 }}>
          No posts from the selected sources in this period. Posts published through ViewsMax appear here once their first daily metrics snapshot has run.
        </div>
      )}

      {layout === "grid" && posts.length > 0 && (
        <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill,minmax(250px,1fr))", gap: 16 }}>
          {posts.map((p) => (
            <article key={p.id} aria-label={p.caption} style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderTop: `3px solid ${p.net.color}`, borderRadius: "var(--r-md)", overflow: "hidden", display: "flex", flexDirection: "column" }}>
              <div style={{ padding: "14px 16px 10px", display: "flex", flexDirection: "column", gap: 8 }}>
                <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", gap: 8 }}>
                  <AccountCell account={p.account} handleSize={13} />
                  <span style={{ font: "500 11px var(--font-mono)", color: "var(--ink-on-paper-3)", flexShrink: 0 }}>{dayLabelYear(p.date)}</span>
                </div>
                <div style={{ fontSize: 14, lineHeight: 1.4, display: "-webkit-box", WebkitLineClamp: 2, WebkitBoxOrient: "vertical", overflow: "hidden", minHeight: 39, textWrap: "pretty" }}><Caption p={p} /></div>
              </div>
              <div style={{ margin: "0 16px", aspectRatio: "16/10", borderRadius: 10, background: THUMB, display: "flex", alignItems: "flex-end", padding: 8 }}>
                <span style={{ font: "500 11px var(--font-mono)", color: "var(--ink-on-paper-2)", background: "var(--paper-0)", padding: "3px 7px", borderRadius: 5 }}>{p.net.name}</span>
              </div>
              <div style={{ padding: "14px 16px 16px", display: "flex", flexDirection: "column", gap: 6, fontSize: 13, fontVariantNumeric: "tabular-nums" }}>
                <div style={{ display: "flex", justifyContent: "space-between", alignItems: "baseline", paddingBottom: 6, borderBottom: "1px solid var(--line-1)" }}>
                  <span style={{ fontWeight: 600 }}>{primaryLabel}</span>
                  <span style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 22 }}>{primaryValue(p)}</span>
                </div>
                {stats.map(([l, k]) => (
                  <div key={l} style={{ display: "flex", justifyContent: "space-between", color: "var(--ink-on-paper-2)" }}><span>{l}</span><span style={{ color: "var(--ink-on-paper-1)" }}>{fmt(p[k])}</span></div>
                ))}
              </div>
            </article>
          ))}
        </div>
      )}

      {layout === "list" && posts.length > 0 && (
        <div style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: "var(--r-md)", overflowX: "auto" }}>
          <div role="table" aria-label="Posts" style={{ minWidth: 760 }}>
            <div role="row" style={{ display: "grid", gridTemplateColumns: "minmax(0,3fr) repeat(5,minmax(0,1fr))", gap: 12, padding: "12px 20px", fontSize: 12, color: "var(--ink-on-paper-3)", borderBottom: "1px solid var(--line-1)" }}>
              {["Post", "Views", "Engagements", "Eng. rate", "Shares", "Published"].map((c, i) => <span role="columnheader" key={c} style={{ textAlign: i ? "right" : "left" }}>{c}</span>)}
            </div>
            {posts.map((p) => (
              <div role="row" key={p.id} style={{ display: "grid", gridTemplateColumns: "minmax(0,3fr) repeat(5,minmax(0,1fr))", gap: 12, padding: "12px 20px", fontSize: 14, borderBottom: "1px solid var(--line-1)", alignItems: "center", fontVariantNumeric: "tabular-nums" }}>
                <span role="cell" style={{ display: "flex", alignItems: "center", gap: 12, minWidth: 0 }}>
                  <span style={{ width: 56, height: 36, flexShrink: 0, borderRadius: 6, borderLeft: `3px solid ${p.net.color}`, background: THUMB }} />
                  <span style={{ display: "flex", flexDirection: "column", minWidth: 0, gap: 2 }}>
                    <Caption p={p} style={{ whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis", display: "block" }} />
                    <span style={{ fontSize: 12, color: "var(--ink-on-paper-3)" }}>{p.net.name} {p.account.handle}</span>
                  </span>
                </span>
                <span role="cell" style={{ textAlign: "right" }}>{fmt(p.views)}</span>
                <span role="cell" style={{ textAlign: "right" }}>{fmt(p.eng)}</span>
                <span role="cell" style={{ textAlign: "right", fontWeight: 600 }}>{rateLabel(p.rate)}</span>
                <span role="cell" style={{ textAlign: "right" }}>{fmt(p.shares)}</span>
                <span role="cell" style={{ textAlign: "right", font: "500 12px var(--font-mono)", color: "var(--ink-on-paper-3)" }}>{dayLabelYear(p.date)}</span>
              </div>
            ))}
          </div>
        </div>
      )}
    </>
  );
}
