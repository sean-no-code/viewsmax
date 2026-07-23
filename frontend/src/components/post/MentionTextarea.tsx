import { useRef, type CSSProperties } from "react";
import { useMentionAutocomplete } from "@/components/post/useMentionAutocomplete";
import MentionPopover from "@/components/post/MentionPopover";

/**
 * Drop-in <textarea> with X @mention autocomplete. When `mentions` is false it
 * behaves exactly like a plain textarea (the popover never opens and no
 * network calls happen), so callers can toggle it on the X selection.
 */
export default function MentionTextarea({
  value,
  onChangeText,
  mentions,
  accountId,
  rows,
  style,
  placeholder,
}: {
  value: string;
  onChangeText: (v: string) => void;
  mentions: boolean;
  /** Search as this connected X account; omit to use the newest connected one. */
  accountId?: number | null;
  rows?: number;
  style?: CSSProperties;
  placeholder?: string;
}) {
  const ref = useRef<HTMLTextAreaElement>(null);
  const ac = useMentionAutocomplete({
    enabled: mentions,
    value,
    onChangeText,
    textareaRef: ref,
    accountId,
  });

  return (
    <div style={{ position: "relative" }}>
      <textarea
        ref={ref}
        value={value}
        onChange={(e) => onChangeText(e.target.value)}
        onKeyDown={(e) => { ac.onKeyDown(e); }}
        onClick={() => ac.detect()}
        onKeyUp={(e) => {
          // Arrow keys move the caret without an onChange — re-check the token.
          if (e.key.startsWith("Arrow") || e.key === "Home" || e.key === "End") ac.detect();
        }}
        onBlur={() => ac.close()}
        rows={rows}
        style={style}
        placeholder={placeholder}
      />
      <MentionPopover
        open={ac.open}
        loading={ac.loading}
        degraded={ac.degraded}
        query={ac.query}
        suggestions={ac.suggestions}
        highlight={ac.highlight}
        onPick={ac.insert}
        onHover={ac.setHighlight}
      />
    </div>
  );
}
