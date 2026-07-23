// Collapsible, URL-persisted filter panel for index pages. Config-driven:
// text fields get a debounced typeahead (datalist of existing values),
// enums a multi-select chip row, dates a from/to range, numerics min/max.
// Filters combine with AND; filtering itself is applied by the page over its
// already-loaded rows (these endpoints return the full dataset), which
// deliberately relaxes the server-side rule from the index-table conventions.
import { useEffect, useMemo, useRef, useState, type CSSProperties, type ReactNode } from "react";
import { useSearchParams } from "react-router-dom";
import { Icon } from "@/components/analytics/primitives";

export type FilterField =
  | { kind: "text"; key: string; label: string; placeholder?: string; suggestions?: string[] }
  | { kind: "multi"; key: string; label: string; options: { value: string; label: string; icon?: ReactNode }[] }
  | { kind: "daterange"; key: string; label: string }
  | { kind: "minmax"; key: string; label: string };

/** Every URL param a field owns (daterange/minmax expand to two). */
const fieldKeys = (f: FilterField): string[] =>
  f.kind === "daterange" ? [`${f.key}_from`, `${f.key}_to`]
    : f.kind === "minmax" ? [`${f.key}_min`, `${f.key}_max`]
    : [f.key];

export function useUrlFilters(fields: FilterField[]) {
  const [params, setParams] = useSearchParams();
  const keys = useMemo(() => fields.flatMap(fieldKeys), [fields]);
  const values = useMemo(() => {
    const v: Record<string, string> = {};
    for (const k of keys) { const p = params.get(k); if (p) v[k] = p; }
    return v;
  }, [params, keys]);
  const set = (key: string, value: string) =>
    setParams((p) => {
      const n = new URLSearchParams(p);
      if (value) n.set(key, value); else n.delete(key);
      return n;
    }, { replace: true });
  const clear = () =>
    setParams((p) => {
      const n = new URLSearchParams(p);
      keys.forEach((k) => n.delete(k));
      return n;
    }, { replace: true });
  return { values, set, clear, activeCount: Object.keys(values).length };
}

/* ---------- matchers pages apply over their rows ---------- */
export const matchesText = (value: string, q?: string) =>
  !q || value.toLowerCase().includes(q.toLowerCase());
export const matchesMulti = (value: string | string[], selected?: string) => {
  if (!selected) return true;
  const set = new Set(selected.split(","));
  return Array.isArray(value) ? value.some((v) => set.has(v)) : set.has(value);
};
export const matchesRange = (value: number, min?: string, max?: string) =>
  (!min || value >= Number(min)) && (!max || value <= Number(max));
export const matchesDateRange = (iso: string | null, from?: string, to?: string) => {
  if (!from && !to) return true;
  if (!iso) return false;
  const d = iso.slice(0, 10);
  return (!from || d >= from) && (!to || d <= to);
};

/* ---------- UI ---------- */
const label: CSSProperties = { fontFamily: "var(--font-mono)", fontSize: 10, letterSpacing: ".06em", textTransform: "uppercase", fontWeight: 700, color: "var(--ink-on-paper-3)", marginBottom: 6, display: "block" };
const input: CSSProperties = { fontFamily: "var(--font-body)", fontSize: 13, color: "var(--ink-on-paper-1)", background: "var(--paper-0)", border: "1px solid var(--line-2)", borderRadius: 10, padding: "8px 11px", outline: "none" };

// Text input with a 300ms debounce before it hits the URL, plus a datalist of
// existing values for typeahead.
function DebouncedText({ id, value, placeholder, suggestions, onCommit }: { id: string; value: string; placeholder?: string; suggestions?: string[]; onCommit: (v: string) => void }) {
  const [local, setLocal] = useState(value);
  const timer = useRef<ReturnType<typeof setTimeout>>();
  useEffect(() => setLocal(value), [value]);
  const change = (v: string) => {
    setLocal(v);
    clearTimeout(timer.current);
    timer.current = setTimeout(() => onCommit(v), 300);
  };
  return (
    <>
      <input list={suggestions?.length ? `${id}-list` : undefined} value={local} placeholder={placeholder}
        onChange={(e) => change(e.target.value)} style={{ ...input, width: 200 }} />
      {suggestions && suggestions.length > 0 && (
        <datalist id={`${id}-list`}>
          {[...new Set(suggestions)].slice(0, 50).map((s) => <option key={s} value={s} />)}
        </datalist>
      )}
    </>
  );
}

export function FiltersPanel({ fields, filters }: {
  fields: FilterField[];
  filters: ReturnType<typeof useUrlFilters>;
}) {
  const [open, setOpen] = useState(filters.activeCount > 0);
  const { values, set, clear, activeCount } = filters;
  return (
    <div>
      <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
        <button onClick={() => setOpen((o) => !o)}
          style={{ display: "inline-flex", alignItems: "center", gap: 8, background: open ? "var(--ink-on-paper-1)" : "var(--paper-0)", color: open ? "#fff" : "var(--ink-on-paper-1)", border: "1px solid " + (open ? "var(--ink-on-paper-1)" : "var(--line-1)"), borderRadius: 999, padding: "8px 15px", cursor: "pointer", fontWeight: 700, fontSize: 13, fontFamily: "var(--font-body)" }}>
          <Icon name="filter" size={14} stroke={open ? "#fff" : "var(--ink-on-paper-1)"} />
          Filters
          {activeCount > 0 && (
            <span style={{ fontFamily: "var(--font-mono)", fontWeight: 700, fontSize: 11, background: "var(--vm-red)", color: "#fff", borderRadius: 999, minWidth: 18, height: 18, display: "inline-flex", alignItems: "center", justifyContent: "center", padding: "0 5px" }}>{activeCount}</span>
          )}
          <Icon name={open ? "chevron-up" : "chevron-down"} size={13} stroke={open ? "#fff" : "var(--ink-on-paper-3)"} />
        </button>
        {activeCount > 0 && (
          <button onClick={clear} style={{ background: "none", border: "none", color: "var(--vm-red)", fontWeight: 700, fontSize: 12.5, cursor: "pointer", fontFamily: "var(--font-body)" }}>Clear all</button>
        )}
      </div>
      {open && (
        <div style={{ marginTop: 10, background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 14, padding: 16, display: "flex", gap: 20, flexWrap: "wrap", alignItems: "flex-start" }}>
          {fields.map((f) => (
            <div key={f.key}>
              <span style={label}>{f.label}</span>
              {f.kind === "text" && (
                <DebouncedText id={`flt-${f.key}`} value={values[f.key] ?? ""} placeholder={f.placeholder} suggestions={f.suggestions} onCommit={(v) => set(f.key, v)} />
              )}
              {f.kind === "multi" && (
                <div style={{ display: "flex", gap: 6, flexWrap: "wrap", maxWidth: 420 }}>
                  {f.options.map((o) => {
                    const selected = new Set((values[f.key] ?? "").split(",").filter(Boolean));
                    const on = selected.has(o.value);
                    return (
                      <button key={o.value}
                        onClick={() => { on ? selected.delete(o.value) : selected.add(o.value); set(f.key, [...selected].join(",")); }}
                        style={{ display: "inline-flex", alignItems: "center", gap: 6, background: on ? "var(--ink-on-paper-1)" : "var(--paper-2)", color: on ? "#fff" : "var(--ink-on-paper-2)", border: "1px solid " + (on ? "var(--ink-on-paper-1)" : "transparent"), borderRadius: 999, padding: o.icon ? "4px 11px 4px 5px" : "5px 11px", cursor: "pointer", fontWeight: 600, fontSize: 12, fontFamily: "var(--font-body)" }}>
                        {o.icon}{o.label}{on && <Icon name="check" size={11} stroke="#fff" />}
                      </button>
                    );
                  })}
                </div>
              )}
              {f.kind === "daterange" && (
                <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
                  <input type="date" value={values[`${f.key}_from`] ?? ""} onChange={(e) => set(`${f.key}_from`, e.target.value)} style={{ ...input, fontFamily: "var(--font-mono)", fontSize: 12 }} />
                  <span style={{ color: "var(--ink-on-paper-3)", fontSize: 12 }}>to</span>
                  <input type="date" value={values[`${f.key}_to`] ?? ""} onChange={(e) => set(`${f.key}_to`, e.target.value)} style={{ ...input, fontFamily: "var(--font-mono)", fontSize: 12 }} />
                </div>
              )}
              {f.kind === "minmax" && (
                <div style={{ display: "flex", gap: 8, alignItems: "center" }}>
                  <input type="number" placeholder="min" value={values[`${f.key}_min`] ?? ""} onChange={(e) => set(`${f.key}_min`, e.target.value)} style={{ ...input, width: 90, fontFamily: "var(--font-mono)", fontSize: 12 }} />
                  <span style={{ color: "var(--ink-on-paper-3)", fontSize: 12 }}>to</span>
                  <input type="number" placeholder="max" value={values[`${f.key}_max`] ?? ""} onChange={(e) => set(`${f.key}_max`, e.target.value)} style={{ ...input, width: 90, fontFamily: "var(--font-mono)", fontSize: 12 }} />
                </div>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
