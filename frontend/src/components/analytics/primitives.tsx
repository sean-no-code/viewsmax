// Analytics shared primitives — ported to TSX from the ViewsMax dashboard
// design kit. Inline styles + the --vm-* design tokens for pixel fidelity.
import { useEffect, useRef, useState, type CSSProperties, type ReactNode } from "react";
import {
  MousePointerClick, Target, Coins, Globe, ShoppingCart, BadgeCheck,
  ArrowUpRight, ArrowRight, ArrowLeft, Plus, Link2, ChevronRight, ChevronDown,
  ChevronLeft, Check, Copy, X, Info, Code2, RotateCw, Settings2, Radio, Bell, Search,
  LayoutDashboard, FileText, CheckCircle2, ImagePlus, Film, Layers, Signal,
  BatteryFull, AlertTriangle, AlertCircle, Send, CalendarClock, TrendingUp,
  Heart, MessageCircle, MessageSquare, Share2, Bookmark, ThumbsUp, Calendar,
  Trash2, Clock, Plug, Filter, ChevronUp, Zap, Image as ImageIcon, Type,
  SquarePen, Eye, BarChart3, Download,
  type LucideIcon,
} from "lucide-react";
import { fmtFull, platformMeta } from "@/lib/analytics-model";

export const CARD: CSSProperties = {
  background: "var(--paper-0)",
  border: "1px solid var(--line-1)",
  borderRadius: 18,
  boxShadow: "0 1px 2px rgba(10,10,12,.04)",
};

/* ---------- icon (name → lucide component) ---------- */
const ICONS: Record<string, LucideIcon> = {
  "mouse-pointer-click": MousePointerClick, target: Target, coins: Coins,
  globe: Globe, "shopping-cart": ShoppingCart, "badge-check": BadgeCheck,
  "arrow-up-right": ArrowUpRight, "arrow-right": ArrowRight, "arrow-left": ArrowLeft,
  plus: Plus, link: Link2, "chevron-right": ChevronRight, "chevron-down": ChevronDown,
  check: Check, copy: Copy, x: X, info: Info, "code-xml": Code2, "rotate-cw": RotateCw,
  "settings-2": Settings2, radio: Radio, bell: Bell, search: Search,
  "layout-dashboard": LayoutDashboard, "chevron-left": ChevronLeft,
  "file-text": FileText, "check-circle-2": CheckCircle2, "image-plus": ImagePlus,
  film: Film, layers: Layers, signal: Signal, "battery-full": BatteryFull,
  "alert-triangle": AlertTriangle, "alert-circle": AlertCircle, send: Send,
  "calendar-clock": CalendarClock, "trending-up": TrendingUp, heart: Heart,
  "message-circle": MessageCircle, "message-square": MessageSquare, "share-2": Share2,
  bookmark: Bookmark, "thumbs-up": ThumbsUp, calendar: Calendar, "trash-2": Trash2,
  clock: Clock, plug: Plug, filter: Filter, "chevron-up": ChevronUp,
  zap: Zap, image: ImageIcon, type: Type, edit: SquarePen, eye: Eye,
  "bar-chart": BarChart3, download: Download,
};

export function Icon({ name, size = 18, stroke, style }: { name: string; size?: number; stroke?: string; style?: CSSProperties }) {
  const Cmp = ICONS[name] || Globe;
  return <Cmp size={size} color={stroke} strokeWidth={2} style={{ display: "inline-flex", flexShrink: 0, ...style }} />;
}

/* ---------- delta chip ---------- */
export function Delta({ value, suffix = "%", size = 12 }: { value: number; suffix?: string; size?: number }) {
  const up = value >= 0;
  return (
    <span style={{ fontFamily: "var(--font-mono)", fontSize: size, fontWeight: 600, color: up ? "var(--up)" : "var(--down)", fontVariantNumeric: "tabular-nums" }}>
      {up ? "▲" : "▼"} {Math.abs(value)}{suffix}
    </span>
  );
}

/* ---------- chip ---------- */
type ChipTone = "tag" | "up" | "hot" | "aqua" | "amber" | "ghost";
export function Chip({ children, tone = "tag", active, onClick }: { children: ReactNode; tone?: ChipTone; active?: boolean; onClick?: () => void }) {
  const tones: Record<ChipTone, CSSProperties> = {
    tag: { background: active ? "var(--vm-red)" : "var(--paper-2)", color: active ? "#fff" : "var(--ink-on-paper-2)", border: active ? "1px solid var(--vm-red)" : "1px solid var(--line-1)" },
    up: { background: "rgba(15,182,126,.12)", color: "var(--up)", border: "none" },
    hot: { background: "var(--vm-red)", color: "#fff", border: "none" },
    aqua: { background: "var(--vm-volt-tint-l)", color: "var(--vm-volt-deep)", border: "none" },
    amber: { background: "rgba(255,176,32,.16)", color: "#9A6700", border: "none" },
    ghost: { background: "transparent", color: "var(--ink-on-paper-3)", border: "1px solid var(--line-1)" },
  };
  return (
    <button onClick={onClick} style={{
      fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 12, cursor: onClick ? "pointer" : "default",
      padding: "5px 11px", borderRadius: 999, display: "inline-flex", alignItems: "center", gap: 6, whiteSpace: "nowrap",
      ...tones[tone],
    }}>{children}</button>
  );
}

/* ---------- platform glyph ---------- */
const BRAND_MARKS: Record<string, (c: string) => ReactNode> = {
  youtube: (c) => (<g><rect x="3" y="5.5" width="18" height="13" rx="3.6" fill="#fff" /><path d="M10.6 9.2 L15.6 12 L10.6 14.8 Z" fill={c} /></g>),
  instagram: () => (<g fill="none" stroke="#fff" strokeWidth="2"><rect x="4" y="4" width="16" height="16" rx="4.7" /><circle cx="12" cy="12" r="3.7" /><circle cx="16.7" cy="7.3" r="0.4" strokeWidth="2.4" /></g>),
  tiktok: () => (<g fill="none" stroke="#fff" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M13 4.5 V14.3 a3 3 0 1 1 -3 -3" /><path d="M13 4.6 c0.6 2.3 2.5 3.6 4.6 3.7" /></g>),
  x: () => (<path d="M6.2 6.2 L17.8 17.8 M17.8 6.2 L6.2 17.8" stroke="#fff" strokeWidth="2.3" strokeLinecap="round" />),
  facebook: () => (<path d="M14.7 8.1 H16.6 V5.2 H14.2 C12.3 5.2 11.3 6.5 11.3 8.3 V9.7 H9.3 v2.8 h2 V19.5 h2.9 v-7 h2.1 l0.4 -2.8 h-2.5 V8.6 C14.2 8.3 14.3 8.1 14.7 8.1 Z" fill="#fff" />),
  email: () => (<g fill="none" stroke="#fff" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect x="3.5" y="5.5" width="17" height="13" rx="2.4" /><path d="M4.4 7.6 L12 12.6 L19.6 7.6" /></g>),
};

export function PlatformGlyph({ id, size = 36, radius }: { id: string; size?: number; radius?: number }) {
  const meta = platformMeta(id);
  const mark = BRAND_MARKS[meta.glyph];
  return (
    <span style={{ width: size, height: size, borderRadius: radius != null ? radius : size * 0.28, background: meta.color, display: "grid", placeItems: "center", flexShrink: 0 }}>
      {mark
        ? <svg width={size * 0.62} height={size * 0.62} viewBox="0 0 24 24" fill="none" style={{ display: "block" }}>{mark(meta.color)}</svg>
        : <span style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: size * 0.5, color: "#fff", lineHeight: 1 }}>{(meta.name || "?")[0]}</span>}
    </span>
  );
}

/* ---------- sparkline ---------- */
export function Sparkline({ data, w = 72, h = 26, color = "var(--vm-red)" }: { data: number[]; w?: number; h?: number; color?: string }) {
  if (!data || data.length < 2) return <svg width={w} height={h} />;
  const max = Math.max(...data), min = Math.min(...data);
  const pts = data.map((d, i) => {
    const x = (i / (data.length - 1)) * w;
    const y = h - ((d - min) / (max - min || 1)) * (h - 4) - 2;
    return [x, y] as const;
  });
  const path = pts.map((p, i) => (i ? "L" : "M") + p[0].toFixed(1) + " " + p[1].toFixed(1)).join(" ");
  const last = pts[pts.length - 1];
  return (
    <svg width={w} height={h} style={{ overflow: "visible", flexShrink: 0 }}>
      <path d={path} fill="none" stroke={color} strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
      <circle cx={last[0]} cy={last[1]} r="2.5" fill={color} />
    </svg>
  );
}

/* ---------- metric card ---------- */
export function MetricCard({
  label, value, accent, accentColor, delta, deltaSuffix, spark, sparkColor, icon, dense, onClick, foot,
}: {
  label: string; value: ReactNode; accent?: string; accentColor?: string; delta?: number; deltaSuffix?: string;
  spark?: number[]; sparkColor?: string; icon?: string; dense?: boolean; onClick?: () => void; foot?: string;
}) {
  const [hover, setHover] = useState(false);
  return (
    <div onClick={onClick} onMouseEnter={() => setHover(true)} onMouseLeave={() => setHover(false)} style={{
      ...CARD, padding: dense ? "14px 16px" : "18px 20px", display: "flex", flexDirection: "column", gap: dense ? 6 : 9, minWidth: 0,
      cursor: onClick ? "pointer" : "default", transition: "box-shadow var(--dur), transform var(--dur)",
      boxShadow: hover && onClick ? "var(--hard)" : "0 1px 2px rgba(10,10,12,.04)",
      transform: hover && onClick ? "translate(-1px,-1px)" : "none",
    }}>
      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between" }}>
        <div style={{ fontFamily: "var(--font-mono)", fontSize: 10.5, letterSpacing: ".06em", textTransform: "uppercase", color: "var(--ink-on-paper-3)", whiteSpace: "nowrap" }}>{label}</div>
        {icon && <Icon name={icon} size={15} stroke="var(--ink-on-paper-3)" />}
      </div>
      <div style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: dense ? 30 : 36, lineHeight: 1, letterSpacing: "-.02em", fontVariantNumeric: "tabular-nums", color: "var(--ink-on-paper-1)" }}>
        {value}{accent && <span style={{ color: accentColor || "var(--vm-volt-deep)" }}>{accent}</span>}
      </div>
      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 8 }}>
        {delta != null ? <Delta value={delta} suffix={deltaSuffix} /> : <span style={{ fontFamily: "var(--font-mono)", fontSize: 11.5, color: "var(--ink-on-paper-3)" }}>{foot}</span>}
        {spark && <Sparkline data={spark} color={sparkColor} />}
      </div>
    </div>
  );
}

/* ---------- area chart ---------- */
export function AreaChart({ data, height = 200, color = "var(--vm-red)", gradId = "fnlArea" }: { data: number[]; height?: number; color?: string; gradId?: string }) {
  const w = 760, h = height, pad = 8;
  const safe = data && data.length ? data : [0, 0];
  const max = Math.max(...safe, 1) * 1.12, min = 0;
  const X = (i: number) => (i / (safe.length - 1 || 1)) * (w - pad * 2) + pad;
  const Y = (v: number) => h - ((v - min) / (max - min || 1)) * (h - 24) - 12;
  const line = safe.map((d, i) => (i ? "L" : "M") + X(i).toFixed(1) + " " + Y(d).toFixed(1)).join(" ");
  const area = line + ` L ${X(safe.length - 1).toFixed(1)} ${h} L ${X(0).toFixed(1)} ${h} Z`;
  return (
    <svg viewBox={`0 0 ${w} ${h}`} width="100%" height={h} preserveAspectRatio="none" style={{ display: "block" }}>
      <defs>
        <linearGradient id={gradId} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor={color} stopOpacity="0.18" />
          <stop offset="100%" stopColor={color} stopOpacity="0" />
        </linearGradient>
      </defs>
      {[0.25, 0.5, 0.75].map((g) => (<line key={g} x1={pad} x2={w - pad} y1={h * g} y2={h * g} stroke="var(--line-1)" strokeWidth="1" />))}
      <path d={area} fill={`url(#${gradId})`} />
      <path d={line} fill="none" stroke={color} strokeWidth="2.5" strokeLinejoin="round" strokeLinecap="round" />
    </svg>
  );
}

/* ---------- rank bar ---------- */
export function RankBar({ pct, color, height = 8 }: { pct: number; color: string; height?: number }) {
  return (
    <div style={{ height, background: "var(--paper-2)", borderRadius: 999, overflow: "hidden" }}>
      <div style={{ width: Math.max(2, pct) + "%", height: "100%", background: color, borderRadius: 999, transition: "width var(--dur-slow) var(--ease-out)" }} />
    </div>
  );
}

/* ---------- conversion funnel ---------- */
export interface FunnelStage { label: string; count: number; icon: string; color: string }
export function Funnel({ stages, dense }: { stages: FunnelStage[]; dense?: boolean }) {
  const top = stages[0]?.count || 1;
  return (
    <div style={{ display: "flex", flexDirection: "column", gap: dense ? 10 : 14 }}>
      {stages.map((s, i) => {
        const pct = (s.count / top) * 100;
        const prev = i ? stages[i - 1].count : s.count;
        const keep = i ? (s.count / (prev || 1)) * 100 : 100;
        return (
          <div key={i}>
            <div style={{ display: "flex", alignItems: "baseline", justifyContent: "space-between", marginBottom: 5 }}>
              <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
                <Icon name={s.icon} size={14} stroke={s.color} />
                <span style={{ fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 13, color: "var(--ink-on-paper-1)" }}>{s.label}</span>
              </div>
              <div style={{ display: "flex", alignItems: "baseline", gap: 10 }}>
                <span style={{ fontFamily: "var(--font-mono)", fontWeight: 700, fontSize: 14, color: "var(--ink-on-paper-1)", fontVariantNumeric: "tabular-nums" }}>{fmtFull(s.count)}</span>
                {i > 0 && <span style={{ fontFamily: "var(--font-mono)", fontSize: 11, color: keep >= 50 ? "var(--up)" : "var(--ink-on-paper-3)" }}>{keep.toFixed(1)}%</span>}
              </div>
            </div>
            <div style={{ height: dense ? 12 : 16, background: "var(--paper-2)", borderRadius: 7, overflow: "hidden" }}>
              <div style={{ width: Math.max(3, pct) + "%", height: "100%", background: s.color, borderRadius: 7, transition: "width var(--dur-slow) var(--ease-out)" }} />
            </div>
          </div>
        );
      })}
    </div>
  );
}

/* ---------- install code block ---------- */
export function CodeBlock({ code, label }: { code: string; label?: string }) {
  const [copied, setCopied] = useState(false);
  const copy = () => {
    navigator.clipboard?.writeText(code).catch(() => {});
    setCopied(true); setTimeout(() => setCopied(false), 1600);
  };
  return (
    <div style={{ background: "var(--ink-900)", borderRadius: 12, overflow: "hidden", border: "1px solid var(--ink-700)" }}>
      {label && (
        <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", padding: "9px 14px", borderBottom: "1px solid var(--ink-700)" }}>
          <span style={{ fontFamily: "var(--font-mono)", fontSize: 11, letterSpacing: ".04em", color: "var(--fg-3)", textTransform: "uppercase" }}>{label}</span>
          <button onClick={copy} style={{ display: "flex", alignItems: "center", gap: 6, background: copied ? "var(--vm-volt)" : "var(--ink-700)", color: copied ? "var(--fg-on-volt)" : "var(--fg-1)", border: "none", borderRadius: 7, padding: "5px 10px", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 11.5, cursor: "pointer", transition: "background var(--dur)" }}>
            <Icon name={copied ? "check" : "copy"} size={13} stroke={copied ? "var(--fg-on-volt)" : "var(--fg-1)"} />{copied ? "Copied" : "Copy"}
          </button>
        </div>
      )}
      <pre style={{ margin: 0, padding: "14px 16px", fontFamily: "var(--font-mono)", fontSize: 12.5, lineHeight: 1.7, color: "var(--fg-2)", whiteSpace: "pre-wrap", overflowWrap: "anywhere" }}>{code}</pre>
    </div>
  );
}

/* ---------- status dot ---------- */
export function StatusDot({ live }: { live: boolean }) {
  return (
    <span style={{ display: "inline-flex", alignItems: "center", gap: 7, fontFamily: "var(--font-mono)", fontSize: 11.5, fontWeight: 500, color: live ? "var(--up)" : "var(--warn)" }}>
      <span style={{ position: "relative", width: 8, height: 8 }}>
        <span style={{ position: "absolute", inset: 0, borderRadius: "50%", background: live ? "var(--up)" : "var(--warn)" }} />
        {live && <span style={{ position: "absolute", inset: -3, borderRadius: "50%", background: "var(--up)", opacity: 0.25, animation: "fnlPulse 1.8s ease-out infinite" }} />}
      </span>
      {live ? "Receiving data" : "Awaiting first event"}
    </span>
  );
}

/* ---------- segmented control ---------- */
export type SegOption = { id: string; label: string };
export function Segmented({ options, value, onChange, mono = true }: { options: SegOption[]; value: string; onChange: (v: string) => void; mono?: boolean }) {
  return (
    <div style={{ display: "flex", gap: 4, background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 999, padding: 4 }}>
      {options.map((o) => {
        const active = value === o.id;
        return (
          <button key={o.id} onClick={() => onChange(o.id)} style={{
            border: "none", cursor: "pointer", padding: "6px 14px", borderRadius: 999,
            fontFamily: mono ? "var(--font-mono)" : "var(--font-body)", fontSize: 12.5, fontWeight: 600,
            background: active ? "var(--vm-red)" : "transparent", color: active ? "#fff" : "var(--ink-on-paper-3)",
            transition: "background var(--dur)", whiteSpace: "nowrap",
          }}>{o.label}</button>
        );
      })}
    </div>
  );
}

/* ---------- section header ---------- */
export function SectionHead({ eyebrow, title, right }: { eyebrow?: string; title: string; right?: ReactNode }) {
  return (
    <div style={{ display: "flex", alignItems: "flex-end", justifyContent: "space-between", gap: 16, flexWrap: "wrap" }}>
      <div>
        {eyebrow && <div className="vm-eyebrow" style={{ color: "var(--vm-volt-deep)" }}>{eyebrow}</div>}
        <h1 style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 30, letterSpacing: "-.03em", margin: eyebrow ? "8px 0 0" : 0, color: "var(--ink-on-paper-1)" }}>{title}</h1>
      </div>
      {right}
    </div>
  );
}

/* ---------- buttons ---------- */
type BtnKind = "primary" | "aqua" | "ghost" | "dark";
export function Btn({ children, kind = "primary", icon, onClick, size = "md", full, type = "button" }: {
  children: ReactNode; kind?: BtnKind; icon?: string; onClick?: () => void; size?: "sm" | "md"; full?: boolean; type?: "button" | "submit";
}) {
  const [press, setPress] = useState(false);
  const kinds: Record<BtnKind, CSSProperties> = {
    primary: { background: "var(--vm-red)", color: "#fff", border: "none" },
    aqua: { background: "var(--vm-volt)", color: "var(--fg-on-volt)", border: "none" },
    ghost: { background: "var(--paper-0)", color: "var(--ink-on-paper-1)", border: "1px solid var(--line-1)" },
    dark: { background: "var(--ink-900)", color: "var(--fg-1)", border: "none" },
  };
  const pad = size === "sm" ? "7px 13px" : "10px 18px";
  return (
    <button type={type} onClick={onClick} onMouseDown={() => setPress(true)} onMouseUp={() => setPress(false)} onMouseLeave={() => setPress(false)}
      style={{ ...kinds[kind], display: "inline-flex", alignItems: "center", justifyContent: "center", gap: 8, borderRadius: 999, padding: pad,
        fontFamily: "var(--font-body)", fontWeight: 700, fontSize: size === "sm" ? 12.5 : 13.5, cursor: "pointer", whiteSpace: "nowrap",
        width: full ? "100%" : "auto", transform: press ? "translateY(1px)" : "none", transition: "transform var(--dur-fast)" }}>
      {icon && <Icon name={icon} size={size === "sm" ? 14 : 16} stroke={kinds[kind].color as string} />}{children}
    </button>
  );
}

/* ---------- searchable select (type-ahead combobox) ---------- */
export type SearchOption = { id: string; label: string };

const ssMuted: CSSProperties = { padding: "10px 12px", fontFamily: "var(--font-body)", fontSize: 13, color: "var(--ink-on-paper-3)" };
const ssRow = (active: boolean): CSSProperties => ({
  display: "block", width: "100%", textAlign: "left", padding: "9px 12px", borderRadius: 8, border: "none",
  background: active ? "var(--vm-red-tint-l)" : "transparent", color: active ? "var(--vm-red-deep)" : "var(--ink-on-paper-1)",
  cursor: "pointer", fontFamily: "var(--font-body)", fontSize: 13.5, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis",
});

// A single-select dropdown you can type into to filter the options (client-side,
// case-insensitive substring on the label). Matches the analytics form styling.
// Pass clearLabel to expose a "none" row when the field is optional.
export function SearchSelect({
  options, value, onChange, placeholder, loading, emptyLabel, disabled, clearLabel,
}: {
  options: SearchOption[];
  value: string;
  onChange: (id: string) => void;
  placeholder?: string;
  loading?: boolean;
  emptyLabel?: string;
  disabled?: boolean;
  clearLabel?: string;
}) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [highlight, setHighlight] = useState(0);
  const wrapRef = useRef<HTMLDivElement>(null);
  const selected = options.find((o) => o.id === value) || null;

  useEffect(() => {
    if (!open) return;
    const onDoc = (e: MouseEvent) => {
      if (wrapRef.current && !wrapRef.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener("mousedown", onDoc);
    return () => document.removeEventListener("mousedown", onDoc);
  }, [open]);

  const q = query.trim().toLowerCase();
  const filtered = q ? options.filter((o) => o.label.toLowerCase().includes(q)) : options;
  const pick = (id: string) => { onChange(id); setOpen(false); setQuery(""); };

  const field: CSSProperties = {
    width: "100%", padding: "11px 36px 11px 13px", borderRadius: 12, border: "1px solid var(--line-1)",
    background: disabled ? "var(--paper-1)" : "var(--paper-0)", color: "var(--ink-on-paper-1)",
    fontFamily: "var(--font-body)", fontSize: 14, outline: "none",
  };

  return (
    <div ref={wrapRef} style={{ position: "relative" }}>
      <input
        type="text"
        disabled={disabled}
        value={open ? query : (selected?.label ?? "")}
        placeholder={loading ? "Loading…" : (placeholder ?? "Type to search…")}
        onFocus={() => { if (!disabled) { setOpen(true); setQuery(""); setHighlight(0); } }}
        onChange={(e) => { setQuery(e.target.value); setOpen(true); setHighlight(0); }}
        onKeyDown={(e) => {
          if (e.key === "ArrowDown") { e.preventDefault(); setOpen(true); setHighlight((h) => Math.min(h + 1, filtered.length - 1)); }
          else if (e.key === "ArrowUp") { e.preventDefault(); setHighlight((h) => Math.max(h - 1, 0)); }
          else if (e.key === "Enter") { const o = filtered[highlight]; if (o) { e.preventDefault(); pick(o.id); } }
          else if (e.key === "Escape") { setOpen(false); }
        }}
        style={field}
      />
      <span style={{ position: "absolute", right: 12, top: "50%", transform: "translateY(-50%)", pointerEvents: "none", display: "flex" }}>
        <Icon name={open ? "chevron-up" : "chevron-down"} size={16} stroke="var(--ink-on-paper-3)" />
      </span>
      {open && !disabled && (
        <div style={{ position: "absolute", zIndex: 40, top: "calc(100% + 4px)", left: 0, right: 0, maxHeight: 240, overflowY: "auto", background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 12, boxShadow: "0 8px 24px rgba(10,10,12,.12)", padding: 4 }}>
          {loading ? (
            <div style={ssMuted}>Loading…</div>
          ) : (
            <>
              {clearLabel && (
                <button type="button" onMouseDown={(e) => { e.preventDefault(); pick(""); }} style={ssRow(value === "")}>{clearLabel}</button>
              )}
              {filtered.length === 0 ? (
                <div style={ssMuted}>{q ? "No matches" : (emptyLabel ?? "Nothing to show")}</div>
              ) : (
                filtered.map((o, i) => (
                  <button
                    key={o.id}
                    type="button"
                    title={o.label}
                    onMouseEnter={() => setHighlight(i)}
                    onMouseDown={(e) => { e.preventDefault(); pick(o.id); }}
                    style={ssRow(o.id === value || i === highlight)}
                  >
                    {o.label}
                  </button>
                ))
              )}
            </>
          )}
        </div>
      )}
    </div>
  );
}
