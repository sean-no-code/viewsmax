// ViewsMax marketing landing page.
// Ported from the Claude Design project "ViewsMax landing page" (Landing Page.html).
// Production assembly = Nav → Hero A (light "connect your socials") → Dashboard
// showcase → Features → Monetize → Pricing → CTA → Footer. Uses the design-system
// tokens already in index.css (--vm-*, --ink-*, --paper-*, fonts) + lucide-react.
// The prototype's per-hero switcher is dropped; Hero A is the production hero.
// Pricing keeps the real 4-tier plan (not the design's placeholder tiers).
import { useState, type CSSProperties, type ReactNode, type ComponentType } from "react";
import { useNavigate } from "react-router-dom";
import {
  CalendarClock, LineChart, Repeat2, Sparkles, Play, Clapperboard, Check, Link2,
  Send, BadgeDollarSign, ChevronRight, ChevronLeft, ChevronDown, Filter, Target,
  Code2, RefreshCw, type LucideProps,
} from "lucide-react";
import logoLight from "@/assets/logo-lockup-light.svg";
import logoDark from "@/assets/logo-lockup-dark.svg";

const AUTH = "/auth";
const SIGNUP = "/auth?tab=signup";
const scrollToId = (id: string) => document.getElementById(id)?.scrollIntoView({ behavior: "smooth", block: "start" });

/* ---------- Icon (name → lucide component, mirrors the prototype's API) ---------- */
const ICONS: Record<string, ComponentType<LucideProps>> = {
  "link-2": Link2, "calendar-clock": CalendarClock, "line-chart": LineChart,
  "repeat-2": Repeat2, sparkles: Sparkles, play: Play, clapperboard: Clapperboard, check: Check,
  send: Send, "badge-dollar-sign": BadgeDollarSign, "chevron-right": ChevronRight,
  "chevron-left": ChevronLeft, "chevron-down": ChevronDown, filter: Filter, target: Target,
  "code-xml": Code2, "refresh-cw": RefreshCw,
};
function Ico({ name, size = 20, stroke }: { name: string; size?: number; stroke?: string }) {
  const C = ICONS[name];
  return C ? <C size={size} color={stroke} style={{ display: "block" }} /> : null;
}

/* ---------- Button ---------- */
type Variant = "primary" | "aqua" | "dark" | "outline" | "ghost";
function Btn({ children, variant = "primary", size = "md", onClick, style }: {
  children: ReactNode; variant?: Variant; size?: "sm" | "md" | "lg"; onClick?: () => void; style?: CSSProperties;
}) {
  const base: CSSProperties = { fontFamily: "var(--font-body)", fontWeight: 700, border: "none", borderRadius: 999, cursor: "pointer", display: "inline-flex", alignItems: "center", justifyContent: "center", gap: 8, transition: "transform var(--dur-fast), background var(--dur), box-shadow var(--dur)", whiteSpace: "nowrap" };
  const sizes: Record<string, CSSProperties> = { sm: { padding: "9px 16px", fontSize: 13.5 }, md: { padding: "13px 22px", fontSize: 15 }, lg: { padding: "16px 30px", fontSize: 17 } };
  const variants: Record<Variant, CSSProperties> = {
    primary: { background: "var(--vm-red)", color: "#fff" },
    aqua: { background: "var(--vm-volt)", color: "var(--fg-on-volt)" },
    dark: { background: "var(--ink-900)", color: "#fff" },
    outline: { background: "transparent", color: "var(--ink-on-paper-1)", boxShadow: "inset 0 0 0 1.5px var(--line-2)" },
    ghost: { background: "transparent", color: "var(--ink-on-paper-1)" },
  };
  const hover: Record<Variant, string> = { primary: "var(--vm-red-hot)", aqua: "var(--vm-volt)", dark: "var(--ink-850)", outline: "transparent", ghost: "transparent" };
  const solid = variant !== "outline" && variant !== "ghost";
  return (
    <button onClick={onClick} style={{ ...base, ...sizes[size], ...variants[variant], ...style }}
      onMouseEnter={(e) => { if (solid) e.currentTarget.style.background = hover[variant]; }}
      onMouseLeave={(e) => { e.currentTarget.style.transform = "none"; e.currentTarget.style.background = String(variants[variant].background); }}
      onMouseDown={(e) => { e.currentTarget.style.transform = "translateY(1px)"; }}
      onMouseUp={(e) => { e.currentTarget.style.transform = "none"; }}>{children}</button>
  );
}

function Eyebrow({ children, color = "var(--vm-red)" }: { children: ReactNode; color?: string }) {
  return <div className="vm-eyebrow" style={{ color }}>{children}</div>;
}

function Heading({ eyebrow, title, sub, align = "center", eyebrowColor }: {
  eyebrow: ReactNode; title: ReactNode; sub?: ReactNode; align?: "center" | "left"; eyebrowColor?: string;
}) {
  return (
    <div style={{ textAlign: align, maxWidth: align === "center" ? 680 : "none", margin: align === "center" ? "0 auto" : 0 }}>
      <Eyebrow color={eyebrowColor}>{eyebrow}</Eyebrow>
      <h2 style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: "clamp(28px,4vw,46px)", letterSpacing: "-.03em", lineHeight: 1.04, color: "var(--ink-on-paper-1)", margin: "14px 0 0" }}>{title}</h2>
      {sub && <p style={{ fontFamily: "var(--font-body)", fontSize: 17, color: "var(--ink-on-paper-2)", lineHeight: 1.5, margin: "14px auto 0", maxWidth: align === "center" ? 560 : "none" }}>{sub}</p>}
    </div>
  );
}

/* ---------- Nav ---------- */
function Nav() {
  const navigate = useNavigate();
  const links: [string, string][] = [["Analytics", "dashboard"], ["Channels", "channels"], ["Pricing", "pricing"]];
  return (
    <nav style={{ position: "sticky", top: 0, zIndex: 50, background: "rgba(250,250,248,.82)", backdropFilter: "blur(12px)", borderBottom: "1px solid var(--line-1)" }}>
      <div style={{ maxWidth: 1200, margin: "0 auto", padding: "14px 24px", display: "flex", alignItems: "center", gap: 24 }}>
        <img src={logoLight} alt="ViewsMax" style={{ height: 30 }} />
        <div className="nav-links" style={{ display: "flex", gap: 28, marginLeft: 16 }}>
          {links.map(([l, id]) => <a key={l} href={`#${id}`} onClick={(e) => { e.preventDefault(); scrollToId(id); }} style={{ color: "var(--ink-on-paper-2)", textDecoration: "none", fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 14.5 }}>{l}</a>)}
        </div>
        <div style={{ marginLeft: "auto", display: "flex", alignItems: "center", gap: 14 }}>
          <a href={AUTH} onClick={(e) => { e.preventDefault(); navigate(AUTH); }} style={{ color: "var(--ink-on-paper-1)", textDecoration: "none", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 14.5, whiteSpace: "nowrap" }}>Log in</a>
          <Btn size="sm" onClick={() => navigate(AUTH)}>Start for $0</Btn>
        </div>
      </div>
    </nav>
  );
}

/* ---------- Hero (direction A — light, centered + connect widget) ---------- */
function HeroBg() {
  return (
    <>
      <div style={{ position: "absolute", inset: 0, background: "radial-gradient(58% 60% at 50% -4%, rgba(255,31,61,.10), transparent 68%)" }} />
      <div style={{ position: "absolute", inset: 0, backgroundImage: "radial-gradient(var(--line-2) 1px, transparent 1px)", backgroundSize: "26px 26px", opacity: .6, maskImage: "radial-gradient(72% 62% at 50% 28%, #000, transparent 76%)", WebkitMaskImage: "radial-gradient(72% 62% at 50% 28%, #000, transparent 76%)" }} />
    </>
  );
}

function HeroChip({ children }: { children: ReactNode }) {
  return (
    <div style={{ display: "inline-flex", alignItems: "center", gap: 8, background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 999, padding: "6px 14px 6px 8px", marginBottom: 26, boxShadow: "var(--shadow-sm)" }}>
      <span style={{ background: "var(--vm-volt)", color: "var(--fg-on-volt)", fontFamily: "var(--font-mono)", fontWeight: 700, fontSize: 10.5, padding: "2px 7px", borderRadius: 999 }}>NEW</span>
      <span style={{ fontSize: 13, color: "var(--ink-on-paper-2)", fontFamily: "var(--font-body)" }}>{children}</span>
    </div>
  );
}

function Headline({ size = "clamp(44px,7.5vw,88px)" }: { size?: string }) {
  return (
    <h1 style={{ fontFamily: "var(--font-display)", fontWeight: 900, fontSize: size, lineHeight: .94, letterSpacing: "-.035em", margin: "0 auto", textAlign: "center", color: "var(--ink-on-paper-1)" }}>
      Make content<br /><span style={{ color: "var(--vm-red)" }}>that makes sales.</span>
    </h1>
  );
}

function Hero() {
  const navigate = useNavigate();
  const goSignup = () => navigate(SIGNUP);
  return (
    <section style={{ background: "var(--paper-1)", color: "var(--ink-on-paper-1)", position: "relative", overflow: "hidden" }}>
      <HeroBg />
      <div style={{ position: "relative", maxWidth: 920, margin: "0 auto", padding: "84px 24px 96px", textAlign: "center" }}>
        <HeroChip>Schedule everywhere · track every sale</HeroChip>
        <Headline />
        <p style={{ fontFamily: "var(--font-body)", fontWeight: 500, fontSize: "clamp(17px,2vw,21px)", color: "var(--ink-on-paper-2)", lineHeight: 1.5, maxWidth: 580, margin: "24px auto 0" }}>
          ViewsMax shows you exactly which posts and platforms drive real sales, so you can do more of what works.
        </p>
        <div style={{ display: "flex", gap: 10, justifyContent: "center", maxWidth: 540, margin: "34px auto 0", flexWrap: "wrap" }}>
          <div onClick={goSignup} style={{ flex: 1, minWidth: 260, display: "flex", alignItems: "center", gap: 10, background: "var(--paper-0)", border: "1px solid var(--line-2)", borderRadius: 999, padding: "6px 6px 6px 18px", boxShadow: "var(--shadow-sm)", cursor: "pointer" }}>
            <Ico name="link-2" size={18} stroke="var(--ink-on-paper-3)" />
            <input readOnly placeholder="Connect your socials" onClick={goSignup} onFocus={goSignup} style={{ flex: 1, background: "none", border: "none", outline: "none", color: "var(--ink-on-paper-1)", fontFamily: "var(--font-body)", fontSize: 15, minWidth: 0, cursor: "pointer" }} />
            <Btn onClick={goSignup} style={{ flexShrink: 0 }}>Connect</Btn>
          </div>
        </div>
        <div style={{ marginTop: 34 }}><Btn size="lg" onClick={() => navigate(AUTH)}>Start for $0</Btn></div>
      </div>
    </section>
  );
}

/* ====================================================================== */
/* Dashboard showcase — sales-attribution dashboard, floated over the hero */
/* ====================================================================== */

/* platform badge (brand glyphs hand-drawn to avoid missing lucide brand marks) */
function PlatformBadge({ type, x, y, size = 22 }: { type: "youtube" | "x" | "facebook"; x: number; y: number; size?: number }) {
  const r = 6, s = size;
  const bg = { youtube: "#FF0000", x: "#0A0A0C", facebook: "#1877F2" }[type];
  return (
    <g transform={`translate(${x - s / 2}, ${y})`}>
      <rect width={s} height={s} rx={r} fill={bg} />
      {type === "youtube" && <path d={`M${s * 0.4} ${s * 0.34} L${s * 0.4} ${s * 0.66} L${s * 0.66} ${s * 0.5} Z`} fill="#fff" />}
      {type === "x" && <path d={`M${s * 0.32} ${s * 0.3} L${s * 0.68} ${s * 0.7} M${s * 0.68} ${s * 0.3} L${s * 0.32} ${s * 0.7}`} stroke="#fff" strokeWidth={2.1} strokeLinecap="round" />}
      {type === "facebook" && <text x={s * 0.5} y={s * 0.74} textAnchor="middle" fontFamily="Georgia, serif" fontWeight="700" fontSize={s * 0.66} fill="#fff">f</text>}
    </g>
  );
}

function RevenueChart() {
  const clicks = [690, 545, 455, 440, 300, 330, 355, 400, 360, 355, 330, 330, 310, 300, 330, 375, 395, 290, 420, 410, 300, 355, 310, 340, 460, 420, 365, 370, 355, 300];
  const revenue = [445, 180, 120, 300, 330, 260, 300, 290, 1180, 360, 150, 300, 330, 300, 300, 360, 1230, 330, 300, 360, 1240, 360, 300, 360, 1250, 420, 330, 460, 360, 150];
  const markers: { i: number; types: ("youtube" | "x" | "facebook")[] }[] = [
    { i: 0, types: ["x"] },
    { i: 3, types: ["youtube"] },
    { i: 8, types: ["youtube", "x", "facebook"] },
    { i: 12, types: ["x"] },
    { i: 16, types: ["youtube", "x"] },
    { i: 20, types: ["facebook"] },
    { i: 24, types: ["facebook", "youtube"] },
    { i: 28, types: ["x"] },
  ];

  const W = 1120, H = 420, padL = 38, padR = 64, padT = 92, padB = 42;
  const plotW = W - padL - padR, plotH = H - padT - padB;
  const n = clicks.length;
  const slot = plotW / n;
  const barW = slot * 0.42;
  const clickMax = 700, revMax = 1300;
  const x = (i: number) => padL + slot * (i + 0.5);
  const yBar = (v: number) => padT + plotH * (1 - v / clickMax);
  const yLine = (v: number) => padT + plotH * (1 - v / revMax);

  const pts = revenue.map((v, i) => [x(i), yLine(v)] as [number, number]);
  let line = `M ${pts[0][0]} ${pts[0][1]}`;
  for (let i = 0; i < pts.length - 1; i++) {
    const p0 = pts[i - 1] || pts[i], p1 = pts[i], p2 = pts[i + 1], p3 = pts[i + 2] || p2;
    const c1x = p1[0] + (p2[0] - p0[0]) / 6, c1y = p1[1] + (p2[1] - p0[1]) / 6;
    const c2x = p2[0] - (p3[0] - p1[0]) / 6, c2y = p2[1] - (p3[1] - p1[1]) / 6;
    line += ` C ${c1x} ${c1y} ${c2x} ${c2y} ${p2[0]} ${p2[1]}`;
  }
  const area = `${line} L ${pts[pts.length - 1][0]} ${padT + plotH} L ${pts[0][0]} ${padT + plotH} Z`;

  const leftTicks = [0, 200, 400, 600];
  const rightTicks: [number, string][] = [[0, "$0"], [650, "$650"], [1300, "$1.3k"]];
  const xTicks = [0, 4, 8, 12, 16, 20, 24, 28];
  const months = ["01 Jun", "05 Jun", "09 Jun", "13 Jun", "17 Jun", "21 Jun", "25 Jun", "29 Jun"];

  return (
    <div style={{ marginTop: 22, background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 18, padding: "22px 24px 18px" }}>
      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between" }}>
        <h3 style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 19, letterSpacing: "-.02em", color: "var(--ink-on-paper-1)", margin: 0 }}>Revenue</h3>
        <span style={{ display: "inline-flex", alignItems: "center", gap: 5, background: "var(--vm-volt-tint-l)", color: "var(--vm-volt-deep)", fontFamily: "var(--font-mono)", fontWeight: 700, fontSize: 12.5, padding: "5px 11px", borderRadius: 999, whiteSpace: "nowrap" }}>▲ Up 19%</span>
      </div>
      <svg viewBox={`0 0 ${W} ${H}`} width="100%" style={{ display: "block", marginTop: 6 }} preserveAspectRatio="xMidYMid meet">
        {leftTicks.map((t) => (
          <g key={"g" + t}>
            <line x1={padL} y1={yBar(t)} x2={W - padR} y2={yBar(t)} stroke="var(--line-1)" strokeWidth="1" strokeDasharray={t === 0 ? "0" : "3 5"} />
            <text x={padL - 8} y={yBar(t) + 4} textAnchor="end" fontFamily="var(--font-mono)" fontSize="12" fill="var(--ink-on-paper-3)">{t}</text>
          </g>
        ))}
        {rightTicks.map(([v, lbl]) => (
          <text key={"r" + v} x={W - padR + 10} y={yLine(v) + 4} textAnchor="start" fontFamily="var(--font-mono)" fontSize="12" fill="var(--ink-on-paper-3)">{lbl}</text>
        ))}
        {clicks.map((v, i) => (
          <rect key={"b" + i} x={x(i) - barW / 2} y={yBar(v)} width={barW} height={padT + plotH - yBar(v)} rx={3} fill="var(--vm-volt)" fillOpacity="0.4" />
        ))}
        <path d={area} fill="var(--vm-red)" fillOpacity="0.10" />
        <path d={line} fill="none" stroke="var(--vm-red)" strokeWidth="3" strokeLinejoin="round" strokeLinecap="round" />
        {pts.map(([px, py], i) => (
          <circle key={"d" + i} cx={px} cy={py} r="3.4" fill="var(--paper-0)" stroke="var(--vm-red)" strokeWidth="2" />
        ))}
        {markers.map((m) => {
          const px = x(m.i), peakY = yLine(revenue[m.i]);
          const stackTop = padT - 8 - (m.types.length - 1) * 26;
          return (
            <g key={"m" + m.i}>
              <line x1={px} y1={peakY} x2={px} y2={padT - 6} stroke="var(--line-2)" strokeWidth="1.5" />
              {m.types.map((t, k) => <PlatformBadge key={k} type={t} x={px} y={stackTop + k * 26} />)}
            </g>
          );
        })}
        {xTicks.map((ti, k) => (
          <text key={"x" + ti} x={x(ti)} y={H - 12} textAnchor="middle" fontFamily="var(--font-mono)" fontSize="12" fill="var(--ink-on-paper-3)">{months[k]}</text>
        ))}
      </svg>
      <div style={{ display: "flex", gap: 22, justifyContent: "center", marginTop: 4 }}>
        <span style={{ display: "inline-flex", alignItems: "center", gap: 7, fontFamily: "var(--font-body)", fontSize: 13, color: "var(--ink-on-paper-2)" }}><span style={{ width: 13, height: 13, borderRadius: 3, background: "var(--vm-volt)", opacity: .55 }} />Clicks</span>
        <span style={{ display: "inline-flex", alignItems: "center", gap: 7, fontFamily: "var(--font-body)", fontSize: 13, color: "var(--ink-on-paper-2)" }}><span style={{ width: 16, height: 3, borderRadius: 2, background: "var(--vm-red)" }} />Revenue</span>
      </div>
    </div>
  );
}

function FilterPill({ icon, label, dark, caret = true, children }: { icon?: string; label: string; dark?: boolean; caret?: boolean; children?: ReactNode }) {
  return (
    <div style={{ display: "inline-flex", alignItems: "center", gap: 8, background: dark ? "var(--ink-900)" : "var(--paper-0)", color: dark ? "#fff" : "var(--ink-on-paper-1)", border: dark ? "none" : "1px solid var(--line-2)", borderRadius: 999, padding: dark ? "7px 12px 7px 7px" : "8px 13px", fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 13.5, whiteSpace: "nowrap" }}>
      {children}
      {icon && <Ico name={icon} size={15} stroke={dark ? "#fff" : "var(--ink-on-paper-3)"} />}
      <span>{label}</span>
      {caret && <Ico name="chevron-down" size={14} stroke={dark ? "rgba(255,255,255,.6)" : "var(--ink-on-paper-3)"} />}
    </div>
  );
}

function FilterBar() {
  const pills = [
    { icon: "filter", label: "All channels" },
    { icon: "target", label: "All events" },
    { icon: "link-2", label: "All links" },
  ];
  return (
    <div style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 18, padding: "14px 18px", display: "flex", alignItems: "center", gap: 12, flexWrap: "wrap" }}>
      <span style={{ fontFamily: "var(--font-mono)", fontSize: 11, letterSpacing: ".12em", color: "var(--ink-on-paper-3)", textTransform: "uppercase" }}>Filters</span>
      <FilterPill dark label="seannocode.com">
        <span style={{ width: 22, height: 22, borderRadius: 6, background: "var(--ink-700)", display: "grid", placeItems: "center", flexShrink: 0 }}><Ico name="code-xml" size={13} stroke="#fff" /></span>
      </FilterPill>
      {pills.map((p) => <FilterPill key={p.label} icon={p.icon} label={p.label} />)}
      <div style={{ marginLeft: "auto", display: "flex", alignItems: "center", gap: 10 }}>
        <div style={{ display: "inline-flex", alignItems: "center", gap: 4, background: "var(--paper-0)", border: "1px solid var(--line-2)", borderRadius: 999, padding: 4 }}>
          <span style={{ width: 26, height: 26, borderRadius: "50%", display: "grid", placeItems: "center", cursor: "pointer" }}><Ico name="chevron-left" size={15} stroke="var(--ink-on-paper-3)" /></span>
          <span style={{ display: "inline-flex", alignItems: "center", gap: 6, fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 13.5, color: "var(--ink-on-paper-1)", padding: "0 4px", whiteSpace: "nowrap" }}>Last 30 days <Ico name="chevron-down" size={14} stroke="var(--ink-on-paper-3)" /></span>
          <span style={{ width: 26, height: 26, borderRadius: "50%", display: "grid", placeItems: "center", cursor: "pointer" }}><Ico name="chevron-right" size={15} stroke="var(--ink-on-paper-3)" /></span>
        </div>
        <div style={{ display: "inline-flex", alignItems: "center", gap: 6, background: "var(--paper-0)", border: "1px solid var(--line-2)", borderRadius: 999, padding: "8px 13px", fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 13.5, color: "var(--ink-on-paper-1)" }}>Daily <Ico name="chevron-down" size={14} stroke="var(--ink-on-paper-3)" /></div>
        <span style={{ width: 38, height: 38, borderRadius: "50%", border: "1px solid var(--line-2)", display: "grid", placeItems: "center", cursor: "pointer" }}><Ico name="refresh-cw" size={15} stroke="var(--ink-on-paper-2)" /></span>
      </div>
    </div>
  );
}

function MetricRow() {
  const metrics: [string, string, string, boolean][] = [
    ["Views", "24.8K", "12%", true],
    ["Clicks", "8,392", "5%", false],
    ["Revenue", "$8,852", "19%", true],
    ["Conversion rate", "0.45%", "43%", true],
    ["Revenue/click", "$1.05", "25%", true],
  ];
  return (
    <div className="vm-metrics" style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 18, display: "grid", gridTemplateColumns: "repeat(5,1fr)", overflow: "hidden" }}>
      {metrics.map(([label, val, delta, up], i) => (
        <div key={label} style={{ padding: "22px 24px", borderLeft: i === 0 ? "none" : "1px solid var(--line-1)" }}>
          <div style={{ fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 13.5, color: "var(--ink-on-paper-2)" }}>{label}</div>
          <div style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: "clamp(26px,2.6vw,34px)", letterSpacing: "-.02em", color: "var(--ink-on-paper-1)", margin: "6px 0 8px", lineHeight: 1 }}>{val}</div>
          <div style={{ fontFamily: "var(--font-mono)", fontWeight: 700, fontSize: 13, color: up ? "var(--up)" : "var(--down)" }}>{up ? "▲" : "▼"} {delta}</div>
        </div>
      ))}
    </div>
  );
}

function Dashboard() {
  return (
    <section id="dashboard" style={{ maxWidth: 1200, margin: "0 auto", padding: "0 24px", position: "relative", zIndex: 5, scrollMarginTop: 80 }}>
      <div className="vm-dashboard" style={{ marginTop: -52, background: "var(--paper-1)", border: "1px solid var(--line-1)", borderRadius: 26, padding: 18, boxShadow: "0 30px 70px -28px rgba(10,10,12,.28), 0 8px 24px -16px rgba(10,10,12,.18)", display: "flex", flexDirection: "column", gap: 14 }}>
        <FilterBar />
        <MetricRow />
        <RevenueChart />
      </div>
    </section>
  );
}

/* ---------- Features ---------- */
function Features() {
  const items = [
    { n: "01", icon: "calendar-clock", tint: "var(--vm-volt-tint-l)", stroke: "var(--vm-volt-deep)", t: "Schedule across every channel", d: "Plan and auto-post to Instagram, TikTok, X, YouTube, LinkedIn and more from one calendar. Set it once and ViewsMax publishes at the perfect time — hands off." },
    { n: "02", icon: "line-chart", tint: "var(--vm-red-tint-l)", stroke: "var(--vm-red)", t: "Track what actually sells", d: "Every post gets tied to real revenue, not likes. See exactly which content and which channel put money in your account — no vanity metrics." },
    { n: "03", icon: "repeat-2", tint: "var(--vm-red-tint-l)", stroke: "var(--vm-red)", t: "Double down on what works", d: "ViewsMax spots your highest-earning posts and tells you what to schedule next, so every week sells more than the last — automatically." },
  ];
  return (
    <section style={{ maxWidth: 1200, margin: "0 auto", padding: "104px 24px 0" }}>
      <Heading eyebrow="THE AUTOPILOT LOOP" title="Post everywhere. Track every sale." sub="One calendar to schedule it all, and one dashboard that shows what actually makes you money." />
      <div className="vm-grid3" style={{ display: "grid", gridTemplateColumns: "repeat(3,1fr)", gap: 18, marginTop: 48 }}>
        {items.map((it) => (
          <div key={it.t} className="vm-feature" style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 22, padding: 26, boxShadow: "4px 4px 0 0 var(--paper-3)", transition: "transform var(--dur), box-shadow var(--dur)" }}>
            <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between" }}>
              <div style={{ width: 48, height: 48, borderRadius: 13, background: it.tint, display: "grid", placeItems: "center" }}><Ico name={it.icon} size={24} stroke={it.stroke} /></div>
              <span style={{ fontFamily: "var(--font-mono)", fontSize: 13, color: "var(--ink-on-paper-3)", fontWeight: 500 }}>{it.n}</span>
            </div>
            <h3 style={{ fontFamily: "var(--font-display)", fontWeight: 700, fontSize: 21, letterSpacing: "-.02em", lineHeight: 1.1, color: "var(--ink-on-paper-1)", margin: "18px 0 0" }}>{it.t}</h3>
            <p style={{ fontFamily: "var(--font-body)", fontSize: 14.5, color: "var(--ink-on-paper-2)", lineHeight: 1.5, margin: "12px 0 0" }}>{it.d}</p>
          </div>
        ))}
      </div>
    </section>
  );
}

/* ---------- Channels we currently support ---------- */
const CHANNELS: { name: string; bg: string; glyph: ReactNode }[] = [
  { name: "YouTube", bg: "#FF0000", glyph: <path d="M9.6 7.8 L16.2 12 L9.6 16.2 Z" fill="#fff" /> },
  { name: "TikTok", bg: "#0A0A0C", glyph: <g fill="none" stroke="#fff" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round"><path d="M13 4.5 V14.3 a3 3 0 1 1 -3 -3" /><path d="M13 4.6 c0.6 2.3 2.5 3.6 4.6 3.7" /></g> },
  { name: "Instagram", bg: "linear-gradient(135deg,#F58529,#DD2A7B 55%,#8134AF)", glyph: <g fill="none" stroke="#fff" strokeWidth={2}><rect x="4" y="4" width="16" height="16" rx="4.7" /><circle cx="12" cy="12" r="3.7" /><circle cx="16.7" cy="7.3" r="0.5" strokeWidth={2.4} /></g> },
  { name: "X", bg: "#0A0A0C", glyph: <path d="M6.6 6.6 L17.4 17.4 M17.4 6.6 L6.6 17.4" stroke="#fff" strokeWidth={2.2} strokeLinecap="round" /> },
  { name: "LinkedIn", bg: "#0A66C2", glyph: <text x="12" y="16.6" textAnchor="middle" fontFamily="Archivo, system-ui, sans-serif" fontWeight={900} fontSize={13} fill="#fff">in</text> },
  { name: "Threads", bg: "#0A0A0C", glyph: <text x="12" y="17" textAnchor="middle" fontFamily="Archivo, system-ui, sans-serif" fontWeight={900} fontSize={17} fill="#fff">@</text> },
  { name: "Beehiiv", bg: "#111827", glyph: <path d="M12 3.4 L18.6 7.2 L18.6 16.8 L12 20.6 L5.4 16.8 L5.4 7.2 Z" fill="none" stroke="#FFD34E" strokeWidth={2} strokeLinejoin="round" /> },
];

function Channels() {
  return (
    <section id="channels" style={{ maxWidth: 1200, margin: "0 auto", padding: "104px 24px 0", scrollMarginTop: 80 }}>
      <Heading eyebrow="EVERY CHANNEL YOU CARE ABOUT" title="Post to the channels we support." sub="Connect once, then schedule, auto-post, and track sales across all of them from a single place." />
      <div style={{ display: "flex", flexWrap: "wrap", justifyContent: "center", gap: 18, marginTop: 44 }}>
        {CHANNELS.map((c) => (
          <div key={c.name} style={{ display: "flex", flexDirection: "column", alignItems: "center", gap: 10, width: 104 }}>
            <div style={{ width: 64, height: 64, borderRadius: 18, background: c.bg, display: "grid", placeItems: "center", boxShadow: "var(--shadow-md)" }}>
              <svg width="34" height="34" viewBox="0 0 24 24" style={{ display: "block" }}>{c.glyph}</svg>
            </div>
            <span style={{ fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 13.5, color: "var(--ink-on-paper-2)" }}>{c.name}</span>
          </div>
        ))}
      </div>
    </section>
  );
}

/* ---------- Storefront mock (used by Monetize) ---------- */
function StorefrontMock() {
  const navigate = useNavigate();
  const [playing, setPlaying] = useState(false);
  return (
    <div style={{ position: "relative" }}>
      <div style={{ display: "inline-flex", alignItems: "center", gap: 8, background: "var(--ink-800)", border: "1px solid var(--ink-600)", borderRadius: 999, padding: "7px 14px 7px 9px", marginBottom: 14, boxShadow: "var(--shadow-md)", maxWidth: "100%" }}>
        <span style={{ background: "var(--vm-volt)", color: "var(--fg-on-volt)", borderRadius: 999, width: 20, height: 20, flexShrink: 0, display: "grid", placeItems: "center" }}><Ico name="sparkles" size={12} stroke="var(--fg-on-volt)" /></span>
        <span style={{ fontFamily: "var(--font-body)", fontSize: 13, color: "var(--fg-2)", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>Your <b style={{ color: "var(--fg-1)" }}>Tuesday Reel</b> drove <b style={{ color: "var(--fg-1)" }}>$4,280</b> in sales</span>
      </div>
      <div style={{ background: "var(--paper-0)", borderRadius: 18, overflow: "hidden", boxShadow: "var(--shadow-lg)", border: "1px solid var(--ink-700)" }}>
        <div style={{ width: "100%", aspectRatio: "16 / 9", background: "#000", position: "relative", display: "grid", placeItems: "center" }}>
          {playing ? (
            <iframe
              src="https://www.youtube.com/embed/npqTDic99sQ?autoplay=1&start=4"
              title="Top post"
              allow="autoplay; encrypted-media; picture-in-picture; fullscreen"
              allowFullScreen
              style={{ position: "absolute", inset: 0, width: "100%", height: "100%", border: "none" }}
            />
          ) : (
            <>
              <img src="https://img.youtube.com/vi/npqTDic99sQ/maxresdefault.jpg" onError={(e) => { e.currentTarget.src = "https://img.youtube.com/vi/npqTDic99sQ/mqdefault.jpg"; }} alt="Top post thumbnail" style={{ position: "absolute", inset: 0, width: "100%", height: "100%", objectFit: "cover" }} />
              <div style={{ position: "absolute", inset: 0, background: "rgba(0,0,0,.18)" }} />
              <button onClick={() => setPlaying(true)} aria-label="Play video" style={{ position: "relative", width: 54, height: 54, borderRadius: "50%", background: "rgba(0,0,0,.45)", backdropFilter: "blur(2px)", display: "grid", placeItems: "center", border: "1.5px solid rgba(255,255,255,.7)", cursor: "pointer" }}><Ico name="play" size={24} stroke="#fff" /></button>
              <span style={{ position: "absolute", top: 12, left: 12, fontFamily: "var(--font-mono)", fontSize: 10.5, fontWeight: 700, color: "#fff", background: "rgba(0,0,0,.32)", padding: "4px 9px", borderRadius: 999 }}>TOP POST</span>
            </>
          )}
        </div>
        <div style={{ padding: 18 }}>
          <div style={{ fontFamily: "var(--font-display)", fontWeight: 700, fontSize: 16.5, color: "var(--ink-on-paper-1)", letterSpacing: "-.01em", lineHeight: 1.2 }}>Behind-the-scenes Reel</div>
          <div style={{ fontFamily: "var(--font-body)", fontSize: 12.5, color: "var(--ink-on-paper-3)", marginTop: 4 }}>Auto-posted Tue 6:00pm · 128K reach</div>
          <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 10, marginTop: 16, paddingTop: 14, borderTop: "1px solid var(--line-1)" }}>
            <div style={{ flexShrink: 0 }}>
              <div style={{ fontFamily: "var(--font-mono)", fontSize: 9.5, color: "var(--ink-on-paper-3)", textTransform: "uppercase", letterSpacing: ".08em" }}>Sales from this post</div>
              <div style={{ display: "flex", alignItems: "baseline", gap: 7, marginTop: 3, whiteSpace: "nowrap" }}>
                <span style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 24, color: "var(--ink-on-paper-1)", letterSpacing: "-.02em" }}>$4,280</span>
                <span style={{ fontFamily: "var(--font-mono)", fontSize: 12, color: "var(--up)", fontWeight: 700 }}>▲ 34%</span>
              </div>
            </div>
            <div style={{ textAlign: "right", fontFamily: "var(--font-body)", fontSize: 11.5, color: "var(--ink-on-paper-3)", lineHeight: 1.4 }}>312 orders<br /><b style={{ color: "var(--vm-volt-deep)" }}>4.1% conv.</b></div>
          </div>
          <button onClick={() => navigate(AUTH)} style={{ width: "100%", marginTop: 14, background: "var(--vm-red)", color: "#fff", border: "none", borderRadius: 10, fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 13.5, padding: "11px 0", cursor: "pointer", display: "inline-flex", alignItems: "center", justifyContent: "center", gap: 8, whiteSpace: "nowrap" }}><Ico name="repeat-2" size={15} stroke="#fff" />Schedule more like this</button>
        </div>
      </div>
    </div>
  );
}

/* ---------- Monetize ---------- */
function Monetize() {
  const navigate = useNavigate();
  const rows: [string, string][] = [
    ["calendar-clock", "Schedule a month of content in minutes"],
    ["line-chart", "Tie every post to the sales it generated"],
    ["repeat-2", "Auto-repost your top earners, hands-off"],
  ];
  return (
    <section style={{ maxWidth: 1200, margin: "0 auto", padding: "104px 24px 0" }}>
      <div style={{ background: "var(--ink-900)", borderRadius: 30, overflow: "hidden", position: "relative" }}>
        <div style={{ position: "absolute", inset: 0, background: "radial-gradient(70% 80% at 88% 18%, rgba(255,31,61,.20), transparent 60%)" }} />
        <div className="vm-split" style={{ position: "relative", display: "grid", gridTemplateColumns: "1.05fr .95fr", gap: 40, alignItems: "center", padding: "clamp(36px,5vw,60px)" }}>
          <div>
            <Eyebrow color="var(--vm-volt)">ATTRIBUTION, NOT VANITY METRICS</Eyebrow>
            <h2 style={{ fontFamily: "var(--font-display)", fontWeight: 900, fontSize: "clamp(32px,4.4vw,52px)", letterSpacing: "-.035em", lineHeight: .98, color: "var(--fg-1)", margin: "16px 0 0" }}>
              Know exactly which post made the <span style={{ color: "var(--vm-red)" }}>sale.</span>
            </h2>
            <p style={{ fontFamily: "var(--font-body)", fontSize: 17, color: "var(--fg-2)", lineHeight: 1.55, margin: "18px 0 0", maxWidth: 440 }}>
              ViewsMax connects every channel to your revenue, so you stop guessing. See which platform, which post, and which time of day actually drives sales — then let the winners run on autopilot.
            </p>
            <div style={{ display: "flex", flexDirection: "column", gap: 12, margin: "26px 0 0" }}>
              {rows.map(([ic, tx]) => (
                <div key={tx} style={{ display: "flex", gap: 12, alignItems: "center" }}>
                  <span style={{ width: 30, height: 30, flexShrink: 0, borderRadius: 9, background: "var(--ink-750)", border: "1px solid var(--ink-600)", display: "grid", placeItems: "center" }}><Ico name={ic} size={16} stroke="var(--vm-volt)" /></span>
                  <span style={{ fontFamily: "var(--font-body)", fontSize: 15, color: "var(--fg-1)", lineHeight: 1.4 }}>{tx}</span>
                </div>
              ))}
            </div>
            <div style={{ marginTop: 30, display: "flex", gap: 12, flexWrap: "wrap" }}>
              <Btn onClick={() => navigate(AUTH)}>See my top sellers</Btn>
              <Btn variant="ghost" onClick={() => navigate(AUTH)} style={{ color: "var(--fg-1)", boxShadow: "inset 0 0 0 1.5px var(--ink-600)" }}>See an example</Btn>
            </div>
          </div>
          <StorefrontMock />
        </div>
      </div>
    </section>
  );
}

/* ---------- Pricing (real 4-tier plan, restyled to the new design) ---------- */
function Pricing() {
  const navigate = useNavigate();
  const tiers = [
    { name: "Starter", price: 29, blurb: "Launch your first offer.", feats: ["5 channels", "1 offer", "400 posts per month"], cta: "Start for $0", variant: "outline" as Variant, hl: false },
    { name: "Creator", price: 59, blurb: "For brands that sell.", feats: ["30 channels", "5 offers", "Unlimited posts per month"], cta: "Start for $0", variant: "primary" as Variant, hl: true },
    { name: "Pro", price: 99, blurb: "Scale every channel.", feats: ["Unlimited channels", "10 offers", "Unlimited posts per month"], cta: "Start for $0", variant: "dark" as Variant, hl: false },
    { name: "Agency", price: 149, blurb: "For teams & agencies.", feats: ["Unlimited channels", "Unlimited offers", "Unlimited posts per month"], cta: "Talk to us", variant: "dark" as Variant, hl: false },
  ];
  return (
    <section id="pricing" style={{ maxWidth: 1200, margin: "0 auto", padding: "104px 24px 0", scrollMarginTop: 80 }}>
      <Heading eyebrow="PRICING" title="Free to start. Cheap to scale." sub="Schedule on every channel, track every sale, and pick the plan that grows with you." />
      <div className="vm-pricing-grid" style={{ display: "grid", gridTemplateColumns: "repeat(4,1fr)", gap: 16, marginTop: 44, alignItems: "start" }}>
        {tiers.map((t) => (
          <div key={t.name} style={{ background: t.hl ? "var(--ink-900)" : "var(--paper-0)", border: t.hl ? "none" : "1px solid var(--line-1)", borderRadius: 22, padding: 26, position: "relative", boxShadow: t.hl ? "var(--shadow-lg)" : "none", transform: t.hl ? "translateY(-10px)" : "none" }}>
            {t.hl && <span style={{ position: "absolute", top: 18, right: 18, background: "var(--vm-red)", color: "#fff", fontFamily: "var(--font-mono)", fontSize: 10, fontWeight: 700, padding: "4px 9px", borderRadius: 999 }}>POPULAR</span>}
            <div style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 18, color: t.hl ? "var(--fg-1)" : "var(--ink-on-paper-1)" }}>{t.name}</div>
            <div style={{ fontFamily: "var(--font-body)", fontSize: 13, color: t.hl ? "var(--fg-3)" : "var(--ink-on-paper-3)", marginTop: 4 }}>{t.blurb}</div>
            <div style={{ display: "flex", alignItems: "baseline", gap: 4, margin: "20px 0" }}>
              <span style={{ fontFamily: "var(--font-display)", fontWeight: 900, fontSize: 44, letterSpacing: "-.03em", color: t.hl ? "var(--fg-1)" : "var(--ink-on-paper-1)" }}>${t.price}</span>
              <span style={{ fontFamily: "var(--font-body)", fontSize: 14, color: t.hl ? "var(--fg-3)" : "var(--ink-on-paper-3)" }}>/mo</span>
            </div>
            <Btn variant={t.variant} onClick={() => navigate(AUTH)} style={{ width: "100%" }}>{t.cta}</Btn>
            <div style={{ display: "flex", flexDirection: "column", gap: 11, marginTop: 22 }}>
              {t.feats.map((f) => (
                <div key={f} style={{ display: "flex", gap: 10, alignItems: "center", fontFamily: "var(--font-body)", fontSize: 14, color: t.hl ? "var(--fg-2)" : "var(--ink-on-paper-2)" }}>
                  <Ico name="check" size={16} stroke={t.hl ? "var(--vm-volt)" : "var(--vm-red)"} />{f}
                </div>
              ))}
            </div>
          </div>
        ))}
      </div>
    </section>
  );
}

/* ---------- CTA band ---------- */
function CTA() {
  const navigate = useNavigate();
  return (
    <section style={{ maxWidth: 1200, margin: "0 auto", padding: "110px 24px 96px" }}>
      <div style={{ background: "var(--vm-red)", borderRadius: 30, padding: "clamp(40px,6vw,72px)", textAlign: "center", position: "relative", overflow: "hidden" }}>
        <div style={{ position: "absolute", inset: 0, backgroundImage: "radial-gradient(rgba(255,255,255,.16) 1px, transparent 1px)", backgroundSize: "24px 24px", opacity: .5 }} />
        <div style={{ position: "relative" }}>
          <h2 style={{ fontFamily: "var(--font-display)", fontWeight: 900, fontSize: "clamp(34px,5vw,64px)", letterSpacing: "-.035em", lineHeight: .98, color: "#fff", margin: 0 }}>Make content<br />that makes sales.</h2>
          <p style={{ fontFamily: "var(--font-body)", fontSize: 18, color: "rgba(255,255,255,.92)", margin: "20px auto 0", maxWidth: 480 }}>Schedule everywhere, track what sells, and grow revenue without lifting a finger. Free to start — no card required.</p>
          <div style={{ marginTop: 30, display: "flex", gap: 12, justifyContent: "center", flexWrap: "wrap" }}><Btn variant="dark" size="lg" onClick={() => navigate(AUTH)}>Start for $0</Btn></div>
        </div>
      </div>
    </section>
  );
}

/* ---------- Footer ---------- */
function Footer() {
  const cols: [string, string[]][] = [
    ["Product", ["Scheduler", "Sales tracking", "Analytics", "Channels", "Pricing"]],
    ["Company", ["About", "Careers", "Blog", "Contact"]],
    ["Resources", ["Help center", "Guides", "API docs", "Status"]],
  ];
  return (
    <footer style={{ background: "var(--ink-900)", color: "var(--fg-2)" }}>
      <div className="vm-foot-grid" style={{ maxWidth: 1200, margin: "0 auto", padding: "64px 24px 40px", display: "grid", gridTemplateColumns: "1.4fr 1fr 1fr 1fr", gap: 32 }}>
        <div>
          <img src={logoDark} alt="ViewsMax" style={{ height: 30 }} />
          <p style={{ fontFamily: "var(--font-body)", fontSize: 14, color: "var(--fg-3)", lineHeight: 1.5, marginTop: 16, maxWidth: 240 }}>Make content that makes sales.</p>
        </div>
        {cols.map(([h, links]) => (
          <div key={h}>
            <div style={{ fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 13, color: "var(--fg-1)", marginBottom: 14 }}>{h}</div>
            <div style={{ display: "flex", flexDirection: "column", gap: 10 }}>
              {links.map((l) => <a key={l} href="#" style={{ fontFamily: "var(--font-body)", fontSize: 14, color: "var(--fg-3)", textDecoration: "none" }}>{l}</a>)}
            </div>
          </div>
        ))}
      </div>
      <div style={{ borderTop: "1px solid var(--ink-700)" }}>
        <div style={{ maxWidth: 1200, margin: "0 auto", padding: "20px 24px", display: "flex", justifyContent: "space-between", flexWrap: "wrap", gap: 12, fontFamily: "var(--font-body)", fontSize: 13, color: "var(--fg-3)" }}>
          <span>© 2026 ViewsMax. Not affiliated with the social platforms shown.</span>
          <span style={{ display: "flex", gap: 20 }}><a href="#" style={{ color: "var(--fg-3)", textDecoration: "none" }}>Privacy</a><a href="#" style={{ color: "var(--fg-3)", textDecoration: "none" }}>Terms</a></span>
        </div>
      </div>
    </footer>
  );
}

/* ---------- Landing-scoped CSS (keyframes, hover, responsive) ---------- */
const LANDING_CSS = `
.vm-landing a { transition: color var(--dur); }
.vm-landing a:hover { color: var(--vm-red); }
.vm-landing .nav-links a:hover { color: var(--ink-on-paper-1); }
.vm-landing input::placeholder { color: var(--fg-4); }
.vm-landing .vm-feature:hover { transform: translateY(-3px); box-shadow: 6px 6px 0 0 var(--vm-red) !important; }
@keyframes vmrise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
@media (max-width: 1080px) { .vm-landing .vm-pricing-grid { grid-template-columns: repeat(2,1fr) !important; } }
@media (max-width: 860px) {
  .vm-landing .vm-split { grid-template-columns: 1fr !important; }
  .vm-landing .vm-grid3 { grid-template-columns: 1fr !important; }
  .vm-landing .vm-foot-grid { grid-template-columns: 1fr 1fr !important; }
  .vm-landing .nav-links { display: none !important; }
}
@media (max-width: 720px) {
  .vm-landing .vm-metrics { grid-template-columns: repeat(2,1fr) !important; }
  .vm-landing .vm-metrics > div:nth-child(odd) { border-left: none !important; }
}
@media (max-width: 640px) {
  .vm-landing .vm-pricing-grid { grid-template-columns: 1fr !important; }
}
`;

export default function ViewsMaxLanding() {
  return (
    <div className="vm-landing" style={{ background: "var(--paper-1)" }}>
      <style>{LANDING_CSS}</style>
      <Nav />
      <Hero />
      <Dashboard />
      <Features />
      <Channels />
      <Monetize />
      <Pricing />
      <CTA />
      <Footer />
    </div>
  );
}
