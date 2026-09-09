// Comma-separated keyword entry → chips. Enter or comma commits a keyword.
import { useState } from "react";
import { Icon } from "@/components/analytics/primitives";
import { TextInput } from "@/components/analytics/Modal";
import { MAX_KEYWORDS, parseKeywords } from "@/lib/automations";
import { HINT } from "./ui";

export function KeywordsInput({ value, onChange }: { value: string[]; onChange: (next: string[]) => void }) {
  const [text, setText] = useState("");
  const commit = () => {
    if (!text.trim()) return;
    const merged = [...value];
    for (const k of parseKeywords(text)) if (!merged.includes(k)) merged.push(k);
    onChange(merged.slice(0, MAX_KEYWORDS));
    setText("");
  };
  return (
    <div>
      <TextInput
        placeholder="Enter a word or multiple"
        value={text}
        onChange={(e) => {
          if (e.target.value.includes(",")) { setText(e.target.value); setTimeout(commit, 0); } else setText(e.target.value);
        }}
        onBlur={commit}
        onKeyDown={(e) => { if (e.key === "Enter") { e.preventDefault(); commit(); } }}
      />
      <div style={{ ...HINT, marginTop: 6 }}>Use commas to separate words · matching ignores case</div>
      {value.length > 0 ? (
        <div style={{ display: "flex", flexWrap: "wrap", gap: 6, marginTop: 10 }}>
          {value.map((k) => (
            <span key={k} style={{ display: "inline-flex", alignItems: "center", gap: 5, padding: "4px 8px 4px 11px", borderRadius: 999, background: "var(--vm-volt-tint-l)", color: "var(--vm-volt-deep)", fontFamily: "var(--font-body)", fontSize: 12.5, fontWeight: 600 }}>
              {k}
              <button type="button" onClick={() => onChange(value.filter((v) => v !== k))} aria-label={`Remove ${k}`} style={{ border: "none", background: "transparent", cursor: "pointer", display: "grid", placeItems: "center", padding: 0 }}>
                <Icon name="x" size={12} stroke="var(--vm-volt-deep)" />
              </button>
            </span>
          ))}
        </div>
      ) : (
        <div style={{ ...HINT, marginTop: 10, display: "flex", alignItems: "center", gap: 6 }}>
          For example:
          {["Price", "Link", "Shop"].map((ex) => (
            <button key={ex} type="button" onClick={() => onChange([...value, ex.toLowerCase()])} style={{ border: "1px solid var(--vm-volt)", background: "transparent", color: "var(--vm-volt-deep)", borderRadius: 999, padding: "3px 10px", fontSize: 12, cursor: "pointer", fontFamily: "var(--font-body)" }}>{ex}</button>
          ))}
        </div>
      )}
    </div>
  );
}
