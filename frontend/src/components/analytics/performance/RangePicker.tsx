// Date-range popover: preset list on the left, two-month range calendar on the right.
import { useState } from "react";
import type { DateRange as DayRange } from "react-day-picker";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { Calendar } from "@/components/ui/calendar";
import { PRESETS, presetFor, presetRange, rangeLabel, type DateRange, type PresetId } from "@/lib/analytics-performance-mock";

interface Props {
  value: DateRange;
  /** Oldest day with data. */
  min: Date;
  /** Newest day with data (today). */
  max: Date;
  onChange: (range: DateRange, preset: PresetId | null) => void;
}

export function RangePicker({ value, min, max, onChange }: Props) {
  const [draft, setDraft] = useState<DayRange | undefined>(value);
  const active = presetFor(value, max);
  const label = active ? `${PRESETS.find((p) => p.id === active)!.label} · ${rangeLabel(value)}` : rangeLabel(value);

  const pick = (id: PresetId) => { const r = presetRange(id, max); setDraft(r); onChange(r, id); };
  const onSelect = (r: DayRange | undefined) => {
    if (r?.from && r?.to) { const next = { from: r.from, to: r.to }; setDraft(next); onChange(next, presetFor(next, max)); }
    else setDraft(r ?? { from: undefined });
  };

  return (
    <Popover onOpenChange={(open) => { if (open) setDraft(value); }}>
      <PopoverTrigger className="rounded-md border border-line-2 bg-paper-0" aria-label="Date range">
        <span style={{ display: "flex", alignItems: "center", gap: 8, padding: "8px 12px", font: "600 13px var(--font-body)", color: "var(--ink-on-paper-1)" }}>
          {label}
          <span style={{ color: "var(--ink-on-paper-3)" }}>▾</span>
        </span>
      </PopoverTrigger>
      <PopoverContent align="end" className="w-auto p-0">
        <div style={{ display: "flex" }}>
          <div style={{ display: "flex", flexDirection: "column", gap: 2, padding: 10, borderRight: "1px solid var(--line-1)", minWidth: 150 }}>
            {PRESETS.map((p) => {
              const on = p.id === active;
              return (
                <button key={p.id} type="button" aria-pressed={on} onClick={() => pick(p.id)} className="hover:bg-paper-2"
                  style={{ padding: "8px 10px", borderRadius: 7, fontSize: 13, cursor: "pointer", border: 0, textAlign: "left", background: on ? "var(--paper-2)" : "transparent", fontWeight: on ? 600 : 400 }}>
                  {p.label}
                </button>
              );
            })}
          </div>
          <Calendar
            mode="range"
            selected={draft}
            onSelect={onSelect}
            numberOfMonths={2}
            defaultMonth={new Date(value.to.getFullYear(), value.to.getMonth() - 1, 1)}
            disabled={[{ after: max }, { before: min }]}
            weekStartsOn={1}
          />
        </div>
      </PopoverContent>
    </Popover>
  );
}
