// Admin — pick how long a promotional customer gets free access. Shared by the
// create and edit user pages. `"keep"` (edit only) leaves the current window
// untouched; a number is days from today; `null` never expires.
import { PROMO_WINDOWS, promoWindowLabel } from "@/lib/access";

export type PromoWindowChoice = number | null | "keep";

const mono = { fontFamily: "var(--font-mono)", fontSize: 11.5 } as const;

interface Props {
  value: PromoWindowChoice;
  onChange: (v: PromoWindowChoice) => void;
  /** Current expiry when editing an existing promotional customer. */
  currentExpiresAt?: string | null;
  showKeep?: boolean;
}

export default function PromoWindowSelect({ value, onChange, currentExpiresAt, showKeep }: Props) {
  const pill = (on: boolean) => ({
    display: "inline-flex", alignItems: "center", gap: 8, padding: "9px 16px", borderRadius: 999, cursor: "pointer",
    border: "1px solid " + (on ? "var(--ink-900)" : "var(--line-2)"),
    background: on ? "var(--ink-900)" : "var(--paper-0)",
    color: on ? "#fff" : "var(--ink-on-paper-2)",
    fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 13.5,
  } as const);

  return (
    <div>
      <div style={{ ...mono, fontSize: 10.5, textTransform: "uppercase", letterSpacing: ".04em", color: "var(--ink-on-paper-3)", marginBottom: 8 }}>
        Free access
      </div>
      <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
        {showKeep && (
          <button type="button" onClick={() => onChange("keep")} style={pill(value === "keep")}>
            Keep current ({promoWindowLabel(currentExpiresAt)})
          </button>
        )}
        {PROMO_WINDOWS.map((w) => (
          <button key={String(w.days)} type="button" onClick={() => onChange(w.days)} style={pill(value === w.days)}>
            {w.label}
          </button>
        ))}
      </div>
      <div style={{ fontFamily: "var(--font-body)", fontSize: 12.5, color: "var(--ink-on-paper-3)", marginTop: 8 }}>
        No card needed. When the window closes they're sent to Billing to pick a plan.
      </div>
    </div>
  );
}
