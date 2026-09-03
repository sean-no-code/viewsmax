// Content calendar — month grid of scheduled / posted social content plus
// SEO blog articles (published + projected).
import { useMemo, useState } from "react";
import { Icon } from "@/components/analytics/primitives";
import { PAvatar, PMAP } from "@/components/post/composer";
import type { Post } from "@/lib/api-service";

/** A blog article placed on the calendar (real or projected date). */
export interface CalendarBlogEntry {
  id: number;
  date: string;      // YYYY-MM-DD
  title: string;
  category: string | null;
  vol: number | null;
  diff: number | null;
  published: boolean;
  url: string | null;
}

// Category chip palettes (Guides cool / Lists warm, mirroring the design ref).
const CATEGORY_TONE: Record<string, { bg: string; fg: string }> = {
  "Guide: Explainer": { bg: "rgba(22,224,196,.14)", fg: "var(--vm-volt-deep)" },
  "Guide: How-to": { bg: "rgba(22,224,196,.14)", fg: "var(--vm-volt-deep)" },
  "List: Round-up": { bg: "rgba(255,176,32,.16)", fg: "var(--warn)" },
  "List: Resources": { bg: "rgba(255,176,32,.16)", fg: "var(--warn)" },
  "List: Examples": { bg: "rgba(255,176,32,.16)", fg: "var(--warn)" },
};

const MONTHS = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
const DOW = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];

const dayKey = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;

function postDayKey(p: Post): string | null {
  if (!p.scheduled_at) return null;
  const d = new Date(p.scheduled_at.replace(" ", "T"));
  if (Number.isNaN(d.getTime())) return null;
  return dayKey(d);
}

export default function PostCalendar({ posts, blog = [], onSelect, onSelectBlog }: {
  posts: Post[];
  blog?: CalendarBlogEntry[];
  onSelect?: (p: Post) => void;
  onSelectBlog?: (b: CalendarBlogEntry) => void;
}) {
  const today = new Date();
  const [cursor, setCursor] = useState(new Date(today.getFullYear(), today.getMonth(), 1));

  const byDay = useMemo(() => {
    const m = new Map<string, Post[]>();
    for (const p of posts) {
      const k = postDayKey(p);
      if (!k) continue;
      if (!m.has(k)) m.set(k, []);
      m.get(k)!.push(p);
    }
    return m;
  }, [posts]);

  const blogByDay = useMemo(() => {
    const m = new Map<string, CalendarBlogEntry[]>();
    for (const b of blog) {
      if (!m.has(b.date)) m.set(b.date, []);
      m.get(b.date)!.push(b);
    }
    return m;
  }, [blog]);

  // Build a 6-week grid starting Monday.
  const cells = useMemo(() => {
    const first = new Date(cursor.getFullYear(), cursor.getMonth(), 1);
    const offset = (first.getDay() + 6) % 7; // Mon = 0
    const start = new Date(first);
    start.setDate(first.getDate() - offset);
    return Array.from({ length: 42 }, (_, i) => {
      const d = new Date(start);
      d.setDate(start.getDate() + i);
      return d;
    });
  }, [cursor]);

  const move = (delta: number) => setCursor(new Date(cursor.getFullYear(), cursor.getMonth() + delta, 1));

  return (
    <div style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 18, overflow: "hidden", boxShadow: "0 1px 2px rgba(10,10,12,.04)" }}>
      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", padding: "16px 20px", borderBottom: "1px solid var(--line-1)" }}>
        <div style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 20, letterSpacing: "-.02em", color: "var(--ink-on-paper-1)" }}>
          {MONTHS[cursor.getMonth()]} {cursor.getFullYear()}
        </div>
        <div style={{ display: "flex", gap: 8 }}>
          {([["chevron-left", -1], ["chevron-right", 1]] as const).map(([ic, d]) => (
            <button key={ic} onClick={() => move(d)} style={{ width: 34, height: 34, borderRadius: 999, border: "1px solid var(--line-1)", background: "var(--paper-0)", cursor: "pointer", display: "grid", placeItems: "center" }}>
              <Icon name={ic} size={17} stroke="var(--ink-on-paper-2)" />
            </button>
          ))}
        </div>
      </div>
      <div style={{ display: "grid", gridTemplateColumns: "repeat(7,1fr)", borderBottom: "1px solid var(--line-1)" }}>
        {DOW.map((d) => (
          <div key={d} style={{ padding: "10px 12px", fontFamily: "var(--font-mono)", fontSize: 10.5, letterSpacing: ".06em", textTransform: "uppercase", color: "var(--ink-on-paper-3)", fontWeight: 600 }}>{d}</div>
        ))}
      </div>
      <div style={{ display: "grid", gridTemplateColumns: "repeat(7,1fr)" }}>
        {cells.map((d, i) => {
          const inMonth = d.getMonth() === cursor.getMonth();
          const isToday = dayKey(d) === dayKey(today);
          const dayPosts = byDay.get(dayKey(d)) || [];
          const dayBlog = blogByDay.get(dayKey(d)) || [];
          return (
            <div key={i} style={{ minHeight: 104, padding: 8, borderRight: (i % 7 !== 6) ? "1px solid var(--paper-2)" : "none", borderBottom: i < 35 ? "1px solid var(--paper-2)" : "none", background: inMonth ? "var(--paper-0)" : "var(--paper-1)", display: "flex", flexDirection: "column", gap: 5 }}>
              <span style={{ alignSelf: "flex-start", fontFamily: "var(--font-mono)", fontSize: 11.5, fontWeight: 700, width: 22, height: 22, borderRadius: "50%", display: "grid", placeItems: "center", color: isToday ? "#fff" : inMonth ? "var(--ink-on-paper-2)" : "var(--ink-on-paper-3)", background: isToday ? "var(--vm-red)" : "transparent" }}>{d.getDate()}</span>
              {dayPosts.map((p) => (
                <button key={p.id} onClick={() => onSelect?.(p)} title={p.caption || "Post"} style={{ textAlign: "left", border: "none", cursor: "pointer", background: p.status === "posted" ? "var(--vm-volt-tint-l)" : "var(--vm-red-tint-l)", borderRadius: 8, padding: "5px 7px", display: "flex", alignItems: "center", gap: 5, minWidth: 0 }}>
                  <span style={{ display: "flex", marginRight: 2 }}>
                    {(p.targets || []).slice(0, 3).map((t, ti) => (
                      <span key={t.id} style={{ marginLeft: ti ? -6 : 0, display: "inline-flex" }}>{PMAP[t.platform] ? <PAvatar id={t.platform} size={16} /> : null}</span>
                    ))}
                  </span>
                  <span style={{ fontFamily: "var(--font-mono)", fontSize: 10, fontWeight: 700, color: p.status === "posted" ? "var(--vm-volt-deep)" : "var(--vm-red-deep)", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>
                    {p.scheduled_at ? new Date(p.scheduled_at.replace(" ", "T")).toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" }) : ""}
                  </span>
                </button>
              ))}
              {dayBlog.map((b) => {
                const tone = CATEGORY_TONE[b.category ?? ""] ?? { bg: "var(--paper-2)", fg: "var(--ink-on-paper-2)" };
                return (
                  <button key={`b${b.id}`} onClick={() => onSelectBlog?.(b)}
                    title={b.published ? "Published blog article" : "Planned blog article (projected publish day)"}
                    style={{ textAlign: "left", border: "1px solid var(--line-1)", cursor: "pointer", background: "var(--paper-0)", borderRadius: 8, padding: "5px 7px", display: "flex", flexDirection: "column", gap: 3, minWidth: 0, opacity: b.published ? 1 : 0.85 }}>
                    {b.category && (
                      <span style={{ alignSelf: "flex-start", background: tone.bg, color: tone.fg, borderRadius: 999, padding: "1px 6px", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 9.5 }}>
                        {b.category}
                      </span>
                    )}
                    <span style={{ fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 11, color: "var(--ink-on-paper-1)", lineHeight: 1.3, overflow: "hidden", display: "-webkit-box", WebkitLineClamp: 2, WebkitBoxOrient: "vertical" }}>
                      {b.title}
                    </span>
                    {(b.vol != null || b.diff != null) && (
                      <span style={{ display: "flex", justifyContent: "space-between", fontFamily: "var(--font-mono)", fontSize: 9.5, color: "var(--ink-on-paper-3)" }}>
                        <span>Vol <b style={{ color: "var(--ink-on-paper-1)" }}>{b.vol ?? "—"}</b></span>
                        <span>Diff <b style={{ color: "var(--ink-on-paper-1)" }}>{b.diff ?? "—"}</b></span>
                      </span>
                    )}
                  </button>
                );
              })}
            </div>
          );
        })}
      </div>
    </div>
  );
}
