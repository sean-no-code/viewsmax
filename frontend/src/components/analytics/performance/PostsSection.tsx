// Post performance: sort + layout controls, then the posts as cards or a table.
import { useTranslation } from "react-i18next";
import type { TFunction } from "i18next";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { POST_SORTS, dayLabelYear, fmt, rateLabel, type PostSort, type PostStat } from "@/lib/analytics-performance";
import { PillToggle } from "./chart";
import { AccountCell } from "./NetBadge";
import { safeHref } from "@/lib/safe-href";

export type PostLayout = "grid" | "list";
const layouts = (t: TFunction): { id: PostLayout; label: string }[] => [
  { id: "grid", label: t("analytics.performance.posts.layoutGrid", "Grid") },
  { id: "list", label: t("analytics.performance.posts.layoutList", "List") },
];

// Sort option labels live in analytics-performance (English); translate by id here.
const sortLabels = (t: TFunction): Record<PostSort, string> => ({
  rate: t("analytics.performance.posts.sortRate", "Engagement rate"),
  eng: t("analytics.performance.posts.sortEng", "Engagements"),
  views: t("analytics.performance.posts.sortViews", "Views"),
  shares: t("analytics.performance.posts.sortShares", "Shares"),
  date: t("analytics.performance.posts.sortDate", "Published date"),
});

type StatKey = keyof Pick<PostStat, "views" | "eng" | "likes" | "comments" | "shares">;
// The headline stat on each card for a sort; "rate" isn't one of the STATS rows.
const PRIMARY: Record<PostSort, { id: StatKey | "rate"; value: (p: PostStat) => string }> = {
  rate: { id: "rate", value: (p) => rateLabel(p.rate) },
  eng: { id: "eng", value: (p) => fmt(p.eng) },
  views: { id: "views", value: (p) => fmt(p.views) },
  shares: { id: "shares", value: (p) => fmt(p.shares) },
  date: { id: "rate", value: (p) => rateLabel(p.rate) },
};
const statLabels = (t: TFunction): Record<StatKey | "rate", string> => ({
  rate: t("analytics.performance.posts.statRate", "Engagement rate"),
  views: t("analytics.performance.posts.statViews", "Views"),
  eng: t("analytics.performance.posts.statEngagements", "Engagements"),
  likes: t("analytics.performance.posts.statLikes", "Likes"),
  comments: t("analytics.performance.posts.statComments", "Comments"),
  shares: t("analytics.performance.posts.statShares", "Shares"),
});
const STATS: StatKey[] = ["views", "eng", "likes", "comments", "shares"];
const THUMB = "repeating-linear-gradient(135deg,var(--paper-2) 0 10px,var(--paper-3) 10px 11px)";

interface Props {
  posts: PostStat[];
  sort: PostSort;
  onSortChange: (s: PostSort) => void;
  layout: PostLayout;
  onLayoutChange: (l: PostLayout) => void;
}

function Caption({ p, style }: { p: PostStat; style?: React.CSSProperties }) {
  const href = safeHref(p.url);
  return href
    ? <a href={href} target="_blank" rel="noopener noreferrer" style={{ color: "inherit", textDecoration: "none", ...style }}>{p.caption}</a>
    : <span style={style}>{p.caption}</span>;
}

export function PostsSection({ posts, sort, onSortChange, layout, onLayoutChange }: Props) {
  const { t } = useTranslation();
  const labels = statLabels(t);
  const sorts = sortLabels(t);
  const { id: primaryId, value: primaryValue } = PRIMARY[sort];
  const primaryLabel = labels[primaryId];
  const stats = STATS.filter((k) => k !== primaryId);

  return (
    <>
      <div style={{ display: "flex", flexWrap: "wrap", gap: 12, alignItems: "center", justifyContent: "space-between" }}>
        <div style={{ fontSize: 13, color: "var(--ink-on-paper-3)" }}>{t("analytics.performance.posts.count", { count: posts.length, defaultValue_one: "{{count}} posts published in this period", defaultValue_other: "{{count}} posts published in this period" })}</div>
        <div style={{ display: "flex", alignItems: "center", gap: 10, flexWrap: "wrap" }}>
          <span style={{ fontSize: 13, color: "var(--ink-on-paper-3)" }}>{t("analytics.performance.posts.sortBy", "Sort by")}</span>
          <Select value={sort} onValueChange={(v) => onSortChange(v as PostSort)}>
            <SelectTrigger className="w-auto bg-paper-0" aria-label={t("analytics.performance.posts.sortAria", "Sort posts by")}><SelectValue /></SelectTrigger>
            <SelectContent>
              {POST_SORTS.map((s) => <SelectItem key={s.id} value={s.id}>{sorts[s.id] ?? s.label}</SelectItem>)}
            </SelectContent>
          </Select>
          <PillToggle options={layouts(t)} value={layout} onChange={onLayoutChange} label={t("analytics.performance.posts.layoutAria", "Post layout")} />
        </div>
      </div>

      {posts.length === 0 && (
        <div style={{ padding: "40px 0", textAlign: "center", color: "var(--ink-on-paper-3)", fontSize: 14 }}>
          {t("analytics.performance.posts.empty", "No posts from the selected sources in this period. Posts published through ViewsMax appear here once their first daily metrics snapshot has run.")}
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
                {stats.map((k) => (
                  <div key={k} style={{ display: "flex", justifyContent: "space-between", color: "var(--ink-on-paper-2)" }}><span>{labels[k]}</span><span style={{ color: "var(--ink-on-paper-1)" }}>{fmt(p[k])}</span></div>
                ))}
              </div>
            </article>
          ))}
        </div>
      )}

      {layout === "list" && posts.length > 0 && (
        <div style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: "var(--r-md)", overflowX: "auto" }}>
          <div role="table" aria-label={t("analytics.performance.posts.tableAria", "Posts")} style={{ minWidth: 760 }}>
            <div role="row" style={{ display: "grid", gridTemplateColumns: "minmax(0,3fr) repeat(5,minmax(0,1fr))", gap: 12, padding: "12px 20px", fontSize: 12, color: "var(--ink-on-paper-3)", borderBottom: "1px solid var(--line-1)" }}>
              {[t("analytics.performance.posts.colPost", "Post"), t("analytics.performance.posts.colViews", "Views"), t("analytics.performance.posts.colEngagements", "Engagements"), t("analytics.performance.posts.colEngRate", "Eng. rate"), t("analytics.performance.posts.colShares", "Shares"), t("analytics.performance.posts.colPublished", "Published")].map((c, i) => <span role="columnheader" key={c} style={{ textAlign: i ? "right" : "left" }}>{c}</span>)}
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
