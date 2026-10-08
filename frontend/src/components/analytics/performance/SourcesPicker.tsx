// "Sources" popover: tick whole networks or individual accounts.
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { Checkbox } from "@/components/ui/checkbox";
import { Avatar, AvatarFallback } from "@/components/ui/avatar";
import { NetBadge } from "./NetBadge";
import { ACCOUNTS, ALL_ACCOUNT_IDS, NETWORKS, selectedNetworks, sourcesLabel } from "@/lib/analytics-performance-mock";

interface Props { value: string[]; onChange: (ids: string[]) => void }

export function SourcesPicker({ value, onChange }: Props) {
  const nets = selectedNetworks(value);
  // Never allow an empty selection — the charts would have nothing to show.
  const set = (ids: string[]) => { if (ids.length) onChange(ALL_ACCOUNT_IDS.filter((id) => ids.includes(id))); };

  return (
    <Popover>
      <PopoverTrigger className="rounded-md border border-line-2 bg-paper-0" aria-label="Sources">
        <span style={{ display: "flex", alignItems: "center", gap: 8, padding: "8px 12px", font: "600 13px var(--font-body)", color: "var(--ink-on-paper-1)" }}>
          <span style={{ display: "flex", paddingLeft: 5 }}>
            {nets.map((n) => (
              <span key={n.id} style={{ marginLeft: -5, borderRadius: 8, borderWidth: 2, borderStyle: "solid", borderColor: "var(--paper-0)", display: "flex" }}><NetBadge net={n} size={20} /></span>
            ))}
          </span>
          {sourcesLabel(value)}
          <span style={{ color: "var(--ink-on-paper-3)" }}>▾</span>
        </span>
      </PopoverTrigger>
      <PopoverContent align="end" className="w-auto p-0">
        <div style={{ width: 300, display: "flex", flexDirection: "column", fontSize: 14 }}>
          <div style={{ display: "flex", justifyContent: "space-between", alignItems: "center", padding: "12px 14px", borderBottom: "1px solid var(--line-1)" }}>
            <span style={{ fontWeight: 700 }}>Sources</span>
            <button type="button" onClick={() => set(ALL_ACCOUNT_IDS)} style={{ border: 0, background: "none", fontSize: 13, fontWeight: 600, color: "var(--vm-red-deep)", cursor: "pointer", padding: 0 }}>Select all</button>
          </div>
          <div style={{ maxHeight: 380, overflowY: "auto", padding: "6px 0" }}>
            {NETWORKS.map((n) => {
              const ids = ACCOUNTS.filter((a) => a.net === n.id).map((a) => a.id);
              const on = ids.filter((id) => value.includes(id)).length;
              const state: boolean | "indeterminate" = on === ids.length ? true : on ? "indeterminate" : false;
              return (
                <div key={n.id} style={{ display: "flex", flexDirection: "column", padding: "4px 0" }}>
                  <button
                    type="button"
                    className="hover:bg-paper-2"
                    onClick={() => set(on === ids.length ? value.filter((id) => !ids.includes(id)) : [...value, ...ids])}
                    style={{ display: "flex", alignItems: "center", gap: 10, padding: "7px 14px", cursor: "pointer", fontWeight: 600, border: 0, background: "none", textAlign: "left", font: "inherit" }}
                  >
                    <Checkbox checked={state} tabIndex={-1} aria-label={`All ${n.name} accounts`} style={{ pointerEvents: "none", display: "flex" }} />
                    <NetBadge net={n} size={20} />
                    All {n.name} accounts
                  </button>
                  {ids.map((id) => {
                    const a = ACCOUNTS.find((z) => z.id === id)!;
                    const c = value.includes(id);
                    return (
                      <button
                        key={id}
                        type="button"
                        className="hover:bg-paper-2"
                        onClick={() => set(c ? value.filter((s) => s !== id) : [...value, id])}
                        style={{ display: "flex", alignItems: "center", gap: 10, padding: "7px 14px 7px 40px", cursor: "pointer", color: "var(--ink-on-paper-2)", border: 0, background: "none", textAlign: "left", font: "inherit" }}
                      >
                        <Checkbox checked={c} tabIndex={-1} aria-label={`${n.name} ${a.handle}`} style={{ pointerEvents: "none", display: "flex" }} />
                        <Avatar className="h-6 w-6"><AvatarFallback className="text-xs font-semibold" style={{ background: a.tint }}>{a.initials}</AvatarFallback></Avatar>
                        {a.handle}
                      </button>
                    );
                  })}
                </div>
              );
            })}
          </div>
        </div>
      </PopoverContent>
    </Popover>
  );
}
