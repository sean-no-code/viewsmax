import type { CSSProperties } from "react";
import type { XUserSuggestion } from "@/lib/api-service";

/**
 * The @mention suggestion panel, anchored under its textarea (the wrapper is
 * position:relative — no caret-mirroring). Rows use onMouseDown+preventDefault
 * so the textarea never loses focus when picking.
 */

const rowStyle = (active: boolean): CSSProperties => ({
  display: "flex", alignItems: "center", gap: 9, width: "100%", textAlign: "left",
  padding: "7px 10px", borderRadius: 8, border: "none", cursor: "pointer",
  background: active ? "var(--vm-red-tint-l)" : "transparent",
  fontFamily: "var(--font-body)",
});

const mutedStyle: CSSProperties = {
  padding: "9px 12px", fontFamily: "var(--font-body)", fontSize: 12.5, color: "var(--ink-on-paper-3)",
};

export default function MentionPopover({
  open, loading, degraded, query, suggestions, highlight, onPick, onHover,
}: {
  open: boolean;
  loading: boolean;
  degraded: boolean;
  query: string;
  suggestions: XUserSuggestion[];
  highlight: number;
  onPick: (u: XUserSuggestion) => void;
  onHover: (i: number) => void;
}) {
  if (!open || (!loading && suggestions.length === 0 && query.length < 2)) return null;

  return (
    <div style={{
      position: "absolute", zIndex: 40, top: "calc(100% + 4px)", left: 0, right: 0,
      maxHeight: 280, overflowY: "auto", background: "var(--paper-0)",
      border: "1px solid var(--line-1)", borderRadius: 12,
      boxShadow: "0 8px 24px rgba(10,10,12,.12)", padding: 4,
    }}>
      {suggestions.map((u, i) => (
        <button
          key={u.username}
          type="button"
          onMouseEnter={() => onHover(i)}
          onMouseDown={(e) => { e.preventDefault(); onPick(u); }}
          style={rowStyle(i === highlight)}
        >
          <span style={{ width: 26, height: 26, borderRadius: "50%", overflow: "hidden", flexShrink: 0, background: "var(--paper-2)", display: "grid", placeItems: "center", fontSize: 12, fontWeight: 800, color: "var(--ink-on-paper-3)" }}>
            {u.avatar_url
              ? <img src={u.avatar_url} alt="" style={{ width: "100%", height: "100%", objectFit: "cover", display: "block" }} />
              : (u.name || u.username).charAt(0).toUpperCase()}
          </span>
          <span style={{ minWidth: 0, display: "flex", flexDirection: "column" }}>
            <span style={{ fontSize: 13, fontWeight: 700, color: "var(--ink-on-paper-1)", overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>
              {u.name || u.username}
            </span>
            <span style={{ fontSize: 11.5, color: "var(--ink-on-paper-3)", overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>
              @{u.username}
            </span>
          </span>
        </button>
      ))}
      {loading && (
        <div style={mutedStyle}>{degraded ? `Looking up @${query}…` : "Searching X…"}</div>
      )}
      {!loading && suggestions.length === 0 && query.length >= 2 && (
        <div style={mutedStyle}>{degraded ? `No X account named @${query}.` : "No matches."}</div>
      )}
      {degraded && (
        <div style={{ ...mutedStyle, borderTop: "1px solid var(--line-1)", marginTop: 2, fontSize: 11 }}>
          Your X plan allows exact-handle lookup only — type the full handle.
        </div>
      )}
    </div>
  );
}
