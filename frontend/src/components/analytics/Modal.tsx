// Analytics modal shell + labelled form field, ported from the design kit.
import { useEffect, useState, type CSSProperties, type ReactNode, type InputHTMLAttributes } from "react";
import { Icon } from "@/components/analytics/primitives";

export function Modal({ title, sub, onClose, children, width = 560 }: {
  title: string; sub?: string; onClose: () => void; children: ReactNode; width?: number;
}) {
  useEffect(() => {
    const h = (e: KeyboardEvent) => e.key === "Escape" && onClose();
    window.addEventListener("keydown", h);
    return () => window.removeEventListener("keydown", h);
  }, [onClose]);
  return (
    <div onMouseDown={onClose} style={{ position: "fixed", inset: 0, background: "rgba(10,10,12,.45)", backdropFilter: "blur(3px)", display: "grid", placeItems: "center", zIndex: 50, padding: 24 }}>
      <div onMouseDown={(e) => e.stopPropagation()} style={{ background: "var(--paper-0)", borderRadius: 20, width, maxWidth: "100%", maxHeight: "90vh", overflowY: "auto", boxShadow: "0 30px 80px -20px rgba(10,10,12,.45)" }}>
        <div style={{ display: "flex", alignItems: "flex-start", justifyContent: "space-between", padding: "22px 24px 0" }}>
          <div>
            <div style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 21, letterSpacing: "-.02em", color: "var(--ink-on-paper-1)" }}>{title}</div>
            {sub && <div style={{ fontFamily: "var(--font-body)", fontSize: 13.5, color: "var(--ink-on-paper-2)", marginTop: 5 }}>{sub}</div>}
          </div>
          <button onClick={onClose} style={{ background: "var(--paper-2)", border: "none", borderRadius: 999, width: 32, height: 32, display: "grid", placeItems: "center", cursor: "pointer", flexShrink: 0 }}>
            <Icon name="x" size={17} stroke="var(--ink-on-paper-2)" />
          </button>
        </div>
        <div style={{ padding: "20px 24px 24px" }}>{children}</div>
      </div>
    </div>
  );
}

export function Field({ label, hint, children }: { label: string; hint?: string; children: ReactNode }) {
  return (
    <label style={{ display: "block", marginBottom: 16 }}>
      <div style={{ display: "flex", alignItems: "baseline", justifyContent: "space-between", gap: 12, marginBottom: 7 }}>
        <span style={{ fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 12.5, color: "var(--ink-on-paper-1)", whiteSpace: "nowrap" }}>{label}</span>
        {hint && <span style={{ fontFamily: "var(--font-mono)", fontSize: 11, color: "var(--ink-on-paper-3)", whiteSpace: "nowrap" }}>{hint}</span>}
      </div>
      {children}
    </label>
  );
}

const INPUT: CSSProperties = {
  width: "100%", boxSizing: "border-box", background: "var(--paper-1)", border: "1px solid var(--line-1)",
  borderRadius: 12, padding: "11px 14px", fontFamily: "var(--font-body)", fontSize: 14, color: "var(--ink-on-paper-1)", outline: "none",
};

export function TextInput(props: InputHTMLAttributes<HTMLInputElement>) {
  const [foc, setFoc] = useState(false);
  const { style, onFocus, onBlur, ...rest } = props;
  return (
    <input
      {...rest}
      onFocus={(e) => { setFoc(true); onFocus?.(e); }}
      onBlur={(e) => { setFoc(false); onBlur?.(e); }}
      style={{ ...INPUT, borderColor: foc ? "var(--vm-red)" : "var(--line-1)", boxShadow: foc ? "0 0 0 3px var(--vm-red-tint-l)" : "none", ...(style || {}) }}
    />
  );
}
