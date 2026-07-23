// Analytics filter bar: site chip, channel / conversion-event / link filters,
// a date-range stepper + granularity, and a refresh control. Mirrors the
// ViewsMax dashboard design board; styled with the --vm-* / --paper-* tokens.
import { useState, type CSSProperties, type ReactNode } from "react";
import { Filter, Crosshair, Link2, RotateCw, ChevronDown, ChevronLeft, ChevronRight, Code2 } from "lucide-react";

export interface Opt { id: string; label: string }

const pill: CSSProperties = {
  display: "flex", alignItems: "center", gap: 8, background: "var(--paper-0)", border: "1px solid var(--line-1)",
  borderRadius: 999, padding: "8px 13px", cursor: "pointer", fontFamily: "var(--font-body)", fontWeight: 600,
  fontSize: 13, color: "var(--ink-on-paper-1)",
};
const menuWrap: CSSProperties = {
  position: "absolute", top: 46, zIndex: 30, background: "var(--paper-0)", border: "1px solid var(--line-1)",
  borderRadius: 12, padding: 6, boxShadow: "0 16px 40px -10px rgba(0,0,0,.28)", display: "flex", flexDirection: "column",
};
const itemStyle: CSSProperties = {
  textAlign: "left", border: "none", background: "none", cursor: "pointer", padding: "8px 12px", borderRadius: 8,
  fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 13, color: "var(--ink-on-paper-2)", whiteSpace: "nowrap",
};

function Dropdown({ open, onToggle, onClose, trigger, children, align = "left", minWidth = 170 }: {
  open: boolean; onToggle: () => void; onClose: () => void; trigger: ReactNode; children: ReactNode;
  align?: "left" | "right"; minWidth?: number;
}) {
  return (
    <div style={{ position: "relative" }}>
      <button onClick={onToggle} style={pill}>{trigger}</button>
      {open && (
        <>
          <div onClick={onClose} style={{ position: "fixed", inset: 0, zIndex: 25 }} />
          <div style={{ ...menuWrap, minWidth, ...(align === "right" ? { right: 0 } : { left: 0 }) }}>{children}</div>
        </>
      )}
    </div>
  );
}

function Item({ label, onClick, mono }: { label: string; onClick: () => void; mono?: boolean }) {
  return (
    <button onClick={onClick} style={{ ...itemStyle, ...(mono ? { fontFamily: "var(--font-mono)", fontWeight: 500, fontSize: 12.5 } : null) }}
      onMouseEnter={(e) => (e.currentTarget.style.background = "var(--paper-2)")}
      onMouseLeave={(e) => (e.currentTarget.style.background = "none")}>{label}</button>
  );
}

const caret = <ChevronDown size={12} color="var(--ink-on-paper-3)" />;

export function FilterBar({
  site, siteOptions, onSite, channel, channelOptions, onChannel, convEvent, eventOptions, onEvent,
  link, linkOptions, onLink, range, rangeOptions, onRange, onPrev, onNext,
  gran, granOptions, onGran, onRefresh, refreshing,
}: {
  site: string; siteOptions: Opt[]; onSite: (id: string) => void;
  channel: string; channelOptions: Opt[]; onChannel: (id: string) => void;
  convEvent: string; eventOptions: Opt[]; onEvent: (id: string) => void;
  link: string; linkOptions: Opt[]; onLink: (id: string) => void;
  range: string; rangeOptions: Opt[]; onRange: (id: string) => void; onPrev: () => void; onNext: () => void;
  gran: string; granOptions: Opt[]; onGran: (id: string) => void;
  onRefresh: () => void; refreshing?: boolean;
}) {
  const [menu, setMenu] = useState<string | null>(null);
  const toggle = (k: string) => setMenu((m) => (m === k ? null : k));
  const close = () => setMenu(null);
  const labelOf = (opts: Opt[], id: string) => opts.find((o) => o.id === id)?.label ?? id;

  return (
    <div style={{ background: "var(--paper-2)", border: "1px solid var(--line-1)", borderRadius: 16, padding: "12px 14px", display: "flex", alignItems: "center", gap: 10, flexWrap: "wrap" }}>
      <span style={{ fontFamily: "var(--font-mono)", fontSize: 10, letterSpacing: ".14em", textTransform: "uppercase", color: "var(--ink-on-paper-3)", fontWeight: 600, padding: "0 6px" }}>Filters</span>

      {/* site / offer selector */}
      <Dropdown open={menu === "site"} onToggle={() => toggle("site")} onClose={close} minWidth={260}
        trigger={<>
          <span style={{ width: 26, height: 26, borderRadius: 8, background: "var(--ink-900)", color: "#fff", display: "grid", placeItems: "center", marginLeft: -3 }}><Code2 size={14} /></span>
          <span style={{ fontWeight: 700, maxWidth: 200, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>{labelOf(siteOptions, site)}</span>{caret}
        </>}>
        {siteOptions.map((o) => <Item key={o.id} label={o.label} onClick={() => { onSite(o.id); close(); }} />)}
      </Dropdown>

      {/* channel */}
      <Dropdown open={menu === "channel"} onToggle={() => toggle("channel")} onClose={close}
        trigger={<><Filter size={14} color="var(--ink-on-paper-3)" />{labelOf(channelOptions, channel)}{caret}</>}>
        {channelOptions.map((o) => <Item key={o.id} label={o.label} onClick={() => { onChannel(o.id); close(); }} />)}
      </Dropdown>

      {/* conversion event */}
      <Dropdown open={menu === "event"} onToggle={() => toggle("event")} onClose={close} minWidth={160}
        trigger={<><Crosshair size={14} color="var(--ink-on-paper-3)" />{labelOf(eventOptions, convEvent)}{caret}</>}>
        {eventOptions.map((o) => <Item key={o.id} label={o.label} onClick={() => { onEvent(o.id); close(); }} />)}
      </Dropdown>

      {/* link */}
      <Dropdown open={menu === "link"} onToggle={() => toggle("link")} onClose={close} minWidth={200}
        trigger={<><Link2 size={14} color="var(--ink-on-paper-3)" /><span style={{ maxWidth: 180, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>{labelOf(linkOptions, link)}</span>{caret}</>}>
        {linkOptions.map((o) => <Item key={o.id} label={o.label} mono onClick={() => { onLink(o.id); close(); }} />)}
      </Dropdown>

      <div style={{ flex: 1 }} />

      {/* range stepper */}
      <div style={{ position: "relative", display: "flex", alignItems: "center", gap: 2, background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 999, padding: 4 }}>
        <button onClick={onPrev} style={{ width: 30, height: 30, border: "none", background: "none", borderRadius: 999, cursor: "pointer", color: "var(--ink-on-paper-2)", display: "grid", placeItems: "center" }}><ChevronLeft size={16} /></button>
        <button onClick={() => toggle("range")} style={{ display: "flex", alignItems: "center", gap: 8, border: "none", background: "var(--paper-2)", borderRadius: 999, padding: "7px 14px", cursor: "pointer", fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 13, color: "var(--ink-on-paper-1)" }}>{labelOf(rangeOptions, range)} {caret}</button>
        <button onClick={onNext} style={{ width: 30, height: 30, border: "none", background: "none", borderRadius: 999, cursor: "pointer", color: "var(--ink-on-paper-3)", display: "grid", placeItems: "center" }}><ChevronRight size={16} /></button>
        {menu === "range" && (
          <>
            <div onClick={close} style={{ position: "fixed", inset: 0, zIndex: 25 }} />
            <div style={{ ...menuWrap, left: 40, minWidth: 150 }}>
              {rangeOptions.map((o) => <Item key={o.id} label={o.label} onClick={() => { onRange(o.id); close(); }} />)}
            </div>
          </>
        )}
      </div>

      {/* granularity */}
      <Dropdown open={menu === "gran"} onToggle={() => toggle("gran")} onClose={close} align="right" minWidth={130}
        trigger={<>{labelOf(granOptions, gran)}{caret}</>}>
        {granOptions.map((o) => <Item key={o.id} label={o.label} onClick={() => { onGran(o.id); close(); }} />)}
      </Dropdown>

      {/* refresh */}
      <button onClick={onRefresh} style={{ width: 38, height: 38, border: "1px solid var(--line-1)", background: "var(--paper-0)", borderRadius: 999, cursor: "pointer", display: "grid", placeItems: "center" }}>
        <RotateCw size={17} color="var(--ink-on-paper-2)" style={{ animation: refreshing ? "spin 0.65s linear infinite" : undefined }} />
      </button>
    </div>
  );
}
