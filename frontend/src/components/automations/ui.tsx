// Small shared pieces for the Automations pages (design-kit styled).
import type { CSSProperties, ReactNode } from "react";
import { Icon } from "@/components/analytics/primitives";
import type { AutomationStatus } from "@/lib/api-service";

export const PANEL: CSSProperties = { background: "var(--paper-1)", border: "1px solid var(--line-1)", borderRadius: 14, padding: 14 };
export const H2: CSSProperties = { fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 18, letterSpacing: "-.02em", color: "var(--ink-on-paper-1)", margin: "0 0 10px" };
export const HINT: CSSProperties = { fontFamily: "var(--font-body)", fontSize: 12, color: "var(--ink-on-paper-3)", lineHeight: 1.5 };
export const TEXTAREA: CSSProperties = {
  width: "100%", boxSizing: "border-box", background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 12,
  padding: "11px 14px", fontFamily: "var(--font-body)", fontSize: 14, color: "var(--ink-on-paper-1)", outline: "none", resize: "vertical", minHeight: 88,
};

export function StatusPill({ status }: { status: AutomationStatus }) {
  const live = status === "live";
  return (
    <span style={{ fontFamily: "var(--font-mono)", fontSize: 10.5, fontWeight: 700, letterSpacing: ".06em", padding: "3px 8px", borderRadius: 6,
      background: live ? "var(--vm-red)" : "var(--paper-2)", color: live ? "#fff" : "var(--ink-on-paper-3)" }}>
      {live ? "LIVE" : "STOPPED"}
    </span>
  );
}

export function Toggle({ checked, onChange, disabled }: { checked: boolean; onChange: (v: boolean) => void; disabled?: boolean }) {
  return (
    <span onClick={() => !disabled && onChange(!checked)} role="switch" aria-checked={checked}
      style={{ width: 36, height: 20, borderRadius: 999, background: checked ? "var(--vm-volt)" : "var(--line-2)", position: "relative", transition: "background var(--dur)", cursor: disabled ? "not-allowed" : "pointer", flexShrink: 0, display: "inline-block", opacity: disabled ? 0.5 : 1 }}>
      <span style={{ position: "absolute", top: 2, left: checked ? 18 : 2, width: 16, height: 16, borderRadius: "50%", background: "#fff", transition: "left var(--dur)", boxShadow: "0 1px 2px rgba(0,0,0,.3)" }} />
    </span>
  );
}

/** Radio-style option row used by the trigger / keyword sections. */
export function OptionRow({ selected, onSelect, label, badge, children }: { selected: boolean; onSelect: () => void; label: ReactNode; badge?: ReactNode; children?: ReactNode }) {
  return (
    <div style={{ ...PANEL, borderColor: selected ? "var(--vm-red)" : "var(--line-1)", marginBottom: 8 }}>
      <div onClick={onSelect} style={{ display: "flex", alignItems: "center", gap: 10, cursor: "pointer" }}>
        <span style={{ width: 18, height: 18, borderRadius: "50%", border: selected ? "5px solid var(--vm-red)" : "2px solid var(--line-2)", background: "#fff", boxSizing: "border-box", flexShrink: 0 }} />
        <span style={{ fontFamily: "var(--font-body)", fontSize: 14, color: "var(--ink-on-paper-1)", flex: 1 }}>{label}</span>
        {badge}
      </div>
      {selected && children && <div style={{ marginTop: 12 }}>{children}</div>}
    </div>
  );
}

export function UpgradeBadge() {
  return <span style={{ fontFamily: "var(--font-mono)", fontSize: 10, fontWeight: 700, letterSpacing: ".06em", padding: "2px 6px", borderRadius: 4, background: "var(--vm-volt-tint-l)", color: "var(--vm-volt-deep)" }}>UPGRADE</span>;
}

export function Counter({ value, max }: { value: number; max: number }) {
  const over = value > max;
  return <span style={{ fontFamily: "var(--font-mono)", fontSize: 11, color: over ? "var(--vm-red)" : "var(--ink-on-paper-3)" }}>{value}/{max}</span>;
}

export function Notice({ tone = "warn", children, action }: { tone?: "warn" | "info"; children: ReactNode; action?: ReactNode }) {
  return (
    <div style={{ display: "flex", alignItems: "center", gap: 12, padding: "12px 14px", borderRadius: 12, background: tone === "warn" ? "rgba(255,176,32,.14)" : "var(--vm-volt-tint-l)", border: `1px solid ${tone === "warn" ? "rgba(255,176,32,.5)" : "var(--vm-volt)"}`, fontFamily: "var(--font-body)", fontSize: 13, color: "var(--ink-on-paper-1)" }}>
      <Icon name={tone === "warn" ? "alert-triangle" : "info"} size={16} stroke={tone === "warn" ? "#9A6700" : "var(--vm-volt-deep)"} />
      <span style={{ flex: 1 }}>{children}</span>
      {action}
    </div>
  );
}
