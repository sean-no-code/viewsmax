// Content calendar — month grid of scheduled / posted social content.
import { useMemo, useState } from "react";
import { Icon } from "@/components/analytics/primitives";
import { PAvatar, PMAP } from "@/components/post/composer";
import type { Post } from "@/lib/api-service";

const MONTHS = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
const DOW = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];

const dayKey = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;

function postDayKey(p: Post): string | null {
  if (!p.scheduled_at) return null;
  const d = new Date(p.scheduled_at.replace(" ", "T"));
  if (Number.isNaN(d.getTime())) return null;
  return dayKey(d);
}

export default function PostCalendar({ posts, onSelect }: {
  posts: Post[];
  onSelect?: (p: Post) => void;
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
            </div>
          );
        })}
      </div>
    </div>
  );
}
