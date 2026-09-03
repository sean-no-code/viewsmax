// Generic client-sorted table for the admin offers / links monitors.
import { useMemo, useState, type CSSProperties, type ReactNode } from "react";

export interface AdminColumn<T> {
  key: string; // stable id; also the sort key
  head: ReactNode;
  align?: "left" | "right";
  sort?: (r: T) => number | string; // omit to make the column unsortable
  render: (r: T) => ReactNode;
}

const headCell = (align: "left" | "right" | undefined, active: boolean, sortable: boolean): CSSProperties => ({
  textAlign: align || "left",
  padding: align === "right" ? "11px 16px" : "11px 20px",
  fontFamily: "var(--font-mono)", fontSize: 10.5, letterSpacing: ".05em", textTransform: "uppercase",
  fontWeight: 600, color: active ? "#fff" : "rgba(255,255,255,.6)",
  cursor: sortable ? "pointer" : "default", whiteSpace: "nowrap", userSelect: "none",
});

export function AdminMetricTable<T extends { id: number }>({ rows, columns, minWidth = 1000, initialSort, initialDir = "desc" }: {
  rows: T[]; columns: AdminColumn<T>[]; minWidth?: number; initialSort?: string; initialDir?: "asc" | "desc";
}) {
  const [sort, setSort] = useState<string | undefined>(initialSort);
  const [dir, setDir] = useState<"asc" | "desc">(initialDir);

  const sorted = useMemo(() => {
    const col = columns.find((c) => c.key === sort);
    if (!col?.sort) return rows;
    const val = col.sort;
    return [...rows].sort((a, b) => {
      const av = val(a), bv = val(b);
      if (typeof av === "string" && typeof bv === "string") return dir === "asc" ? av.localeCompare(bv) : bv.localeCompare(av);
      return dir === "asc" ? (av as number) - (bv as number) : (bv as number) - (av as number);
    });
  }, [rows, columns, sort, dir]);

  const toggle = (k: string) => { if (sort === k) setDir((d) => (d === "asc" ? "desc" : "asc")); else { setSort(k); setDir("desc"); } };

  return (
    <div style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 18, overflow: "hidden", boxShadow: "0 1px 2px rgba(10,10,12,.04)" }}>
      <div style={{ overflowX: "auto" }}>
        <table style={{ width: "100%", borderCollapse: "collapse", minWidth }}>
          <thead>
            <tr style={{ background: "var(--ink-900)" }}>
              {columns.map((c) => (
                <th key={c.key} onClick={c.sort ? () => toggle(c.key) : undefined} style={headCell(c.align, sort === c.key, !!c.sort)}>
                  {c.head}{sort === c.key ? (dir === "asc" ? " ↑" : " ↓") : ""}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {sorted.map((r, i) => (
              <tr key={r.id} style={{ borderTop: i ? "1px solid var(--paper-2)" : "none" }}>
                {columns.map((c) => (
                  <td key={c.key} style={{ textAlign: c.align || "left", padding: c.align === "right" ? "13px 16px" : "13px 20px", fontFamily: "var(--font-body)", fontSize: 13, color: "var(--ink-on-paper-1)", verticalAlign: "top" }}>
                    {c.render(r)}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

// Shared bits used by both admin monitor pages.
export const adminSearchStyle: CSSProperties = {
  minWidth: 280, fontFamily: "var(--font-body)", fontSize: 13, color: "var(--ink-on-paper-1)",
  background: "var(--paper-0)", border: "1px solid var(--line-2)", borderRadius: 10, padding: "9px 12px", outline: "none",
};

export const subText: CSSProperties = { fontFamily: "var(--font-mono)", fontSize: 11, color: "var(--ink-on-paper-3)", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis", maxWidth: 260 };

// Reach conversion-rate cell (green when present, "—" when no view data).
export function pctCell(v: number | null): ReactNode {
  return v == null ? <span style={{ color: "var(--ink-on-paper-3)" }}>—</span> : <span style={{ color: "var(--vm-volt-deep)", fontWeight: 700 }}>{v}%</span>;
}
