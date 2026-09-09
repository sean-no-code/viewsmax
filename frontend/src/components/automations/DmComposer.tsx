// "They will get" — the DM. Plain text (1000 chars) or, once a button is
// added, a card (80-char title + subtitle + image + button). The link is
// sent as a tracked short link so the index can show CTR.
import { Field, TextInput } from "@/components/analytics/Modal";
import { BUTTON_LABEL_MAX, CARD_SUBTITLE_MAX, dmTextLimit, hasButton, type AutomationDraft } from "@/lib/automations";
import { Counter, HINT, TEXTAREA, Toggle } from "./ui";

export function DmComposer({ draft, onChange }: { draft: AutomationDraft; onChange: (patch: Partial<AutomationDraft>) => void }) {
  const button = hasButton(draft);
  const limit = dmTextLimit(button);
  return (
    <div>
      <Field label="Message" hint={`${draft.dm_text.length}/${limit}`}>
        <textarea style={TEXTAREA} value={draft.dm_text} maxLength={limit + 50} placeholder={button ? "Card title — keep it short" : "Hey! Here's the link you asked for 👇"} onChange={(e) => onChange({ dm_text: e.target.value })} />
      </Field>
      {!button && <div style={{ ...HINT, marginTop: -8, marginBottom: 12 }}>Links in the text are sent as tracked links, so clicks count toward CTR.</div>}

      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 10, padding: "10px 0", borderTop: "1px solid var(--line-1)" }}>
        <div>
          <div style={{ fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 13, color: "var(--ink-on-paper-1)" }}>Add a button</div>
          <div style={HINT}>Sends the DM as a card with a tappable link. Instagram caps the card text at {dmTextLimit(true)} characters.</div>
        </div>
        <Toggle checked={button} onChange={(v) => onChange(v ? { dm_button_label: draft.dm_button_label || "Open link", dm_button_url: draft.dm_button_url } : { dm_button_label: "", dm_button_url: "", dm_subtitle: "", dm_image_url: "" })} />
      </div>

      {button && (
        <div style={{ display: "grid", gap: 0, marginTop: 6 }}>
          <Field label="Button label" hint={`${draft.dm_button_label.length}/${BUTTON_LABEL_MAX}`}>
            <TextInput value={draft.dm_button_label} maxLength={BUTTON_LABEL_MAX} placeholder="Open link" onChange={(e) => onChange({ dm_button_label: e.target.value })} />
          </Field>
          <Field label="Button URL" hint="tracked">
            <TextInput value={draft.dm_button_url} placeholder="https://yoursite.com/offer" onChange={(e) => onChange({ dm_button_url: e.target.value })} />
          </Field>
          <Field label="Subtitle (optional)" hint={`${draft.dm_subtitle.length}/${CARD_SUBTITLE_MAX}`}>
            <TextInput value={draft.dm_subtitle} maxLength={CARD_SUBTITLE_MAX} placeholder="Tap below to grab it" onChange={(e) => onChange({ dm_subtitle: e.target.value })} />
          </Field>
          <Field label="Image URL (optional)" hint="https">
            <TextInput value={draft.dm_image_url} placeholder="https://…/cover.jpg" onChange={(e) => onChange({ dm_image_url: e.target.value })} />
          </Field>
          <div style={{ display: "flex", justifyContent: "flex-end" }}><Counter value={draft.dm_text.length} max={limit} /></div>
        </div>
      )}
    </div>
  );
}
