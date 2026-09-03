// ViewsMax marketing landing page.
// Ported from the Claude Design project "ViewsMax landing page" (Landing Page.html).
// Production assembly = Nav → Hero A (light "connect your socials") → Feature
// demos (tabbed videos) → Features → Monetize → Pricing → CTA → Footer. Uses the design-system
// tokens already in index.css (--vm-*, --ink-*, --paper-*, fonts) + lucide-react.
// The prototype's per-hero switcher is dropped; Hero A is the production hero.
// Pricing keeps the real 4-tier plan (not the design's placeholder tiers).
import { useEffect, useState, type CSSProperties, type ReactNode, type ComponentType } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import {
  CalendarClock, LineChart, Repeat2, Sparkles, Play, Clapperboard, Check, Link2,
  Send, BadgeDollarSign, ChevronRight, ChevronLeft, ChevronDown, Filter, Target,
  Code2, RefreshCw, TrendingUp, type LucideProps,
} from "lucide-react";
import { Btn, LandingNav, LandingFooter, type Variant } from "./LandingChrome";

const AUTH = "/auth";
const SIGNUP = "/auth?tab=signup";
const scrollToId = (id: string) => document.getElementById(id)?.scrollIntoView({ behavior: "smooth", block: "start" });

/* ---------- Icon (name → lucide component, mirrors the prototype's API) ---------- */
const ICONS: Record<string, ComponentType<LucideProps>> = {
  "link-2": Link2, "calendar-clock": CalendarClock, "line-chart": LineChart,
  "repeat-2": Repeat2, sparkles: Sparkles, play: Play, clapperboard: Clapperboard, check: Check,
  send: Send, "badge-dollar-sign": BadgeDollarSign, "chevron-right": ChevronRight,
  "chevron-left": ChevronLeft, "chevron-down": ChevronDown, filter: Filter, target: Target,
  "code-xml": Code2, "refresh-cw": RefreshCw, "trending-up": TrendingUp,
};
function Ico({ name, size = 20, stroke }: { name: string; size?: number; stroke?: string }) {
  const C = ICONS[name];
  return C ? <C size={size} color={stroke} style={{ display: "block" }} /> : null;
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

/* ---------- Hero (direction A — light, centered + connect widget) ---------- */
function HeroBg() {
  return (
    <>
      <div style={{ position: "absolute", inset: 0, background: "radial-gradient(58% 60% at 50% -4%, rgba(255,31,61,.10), transparent 68%)" }} />
      <div style={{ position: "absolute", inset: 0, backgroundImage: "radial-gradient(var(--line-2) 1px, transparent 1px)", backgroundSize: "26px 26px", opacity: .6, maskImage: "radial-gradient(72% 62% at 50% 28%, #000, transparent 76%)", WebkitMaskImage: "radial-gradient(72% 62% at 50% 28%, #000, transparent 76%)" }} />
    </>
  );
}

function Headline({ size = "clamp(40px,6vw,68px)" }: { size?: string }) {
  return (
    <h1 style={{ fontFamily: "var(--font-display)", fontWeight: 900, fontSize: size, lineHeight: .94, letterSpacing: "-.035em", margin: "0 auto", textAlign: "center", color: "var(--ink-on-paper-1)" }}>
      Build a content engine<br /><span style={{ color: "var(--vm-red)" }}>that drives revenue.</span>
    </h1>
  );
}

function Hero() {
  const navigate = useNavigate();
  const goSignup = () => navigate(SIGNUP);
  return (
    <section style={{ background: "var(--paper-1)", color: "var(--ink-on-paper-1)", position: "relative", overflow: "hidden" }}>
      <HeroBg />
      <div style={{ position: "relative", maxWidth: 920, margin: "0 auto", padding: "84px 24px 40px", textAlign: "center" }}>
        {/* Supported-platform icons (reuses the Channels section's brand glyphs) */}
        <div style={{ display: "flex", justifyContent: "center", flexWrap: "wrap", gap: 10, marginBottom: 28 }}>
          {CHANNELS.map((c) => (
            <span key={c.name} title={c.name} style={{ width: 34, height: 34, borderRadius: 10, background: c.bg, display: "grid", placeItems: "center", boxShadow: "var(--shadow-sm)" }}>
              <svg width="19" height="19" viewBox="0 0 24 24" style={{ display: "block" }}>{c.glyph}</svg>
            </span>
          ))}
        </div>
        <Headline />
        <p style={{ fontFamily: "var(--font-body)", fontWeight: 500, fontSize: "clamp(17px,2vw,21px)", color: "var(--ink-on-paper-2)", lineHeight: 1.5, maxWidth: 580, margin: "24px auto 0" }}>
          Spot winning content, post to every platform and track what converts (with human support from Sean).
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

/* ---------- Feature demos (tabbed video walkthroughs) ---------- */
type Demo = {
  id: string; label: string; icon: string; title: string; desc: string;
  // Video source: drop `<id>.mp4` (+ optional `<id>.jpg` poster) into public/demos/,
  // or set youtubeId to embed from YouTube instead. Until a source exists the tab
  // shows a designed "demo coming soon" frame — nothing breaks.
  mp4?: string; youtubeId?: string; poster?: string;
};

const DEMOS: Demo[] = [
  {
    id: "outliers", label: "Outliers", icon: "trending-up",
    title: "Find outliers worth copying",
    desc: "Search millions of videos across YouTube, TikTok and Instagram to surface posts massively outperforming their channel — then break down why they worked and remake them for your niche.",
    youtubeId: "34HuCTCEw4k", poster: "https://i.ytimg.com/vi/34HuCTCEw4k/maxresdefault.jpg",
  },
  {
    id: "tracking", label: "Tracking", icon: "line-chart",
    title: "See which posts make sales",
    desc: "Every click and conversion is tied back to the exact post and platform it came from, so you know which content actually drives revenue — not just views.",
    youtubeId: "36mZkfMaFrM", poster: "https://i.ytimg.com/vi/36mZkfMaFrM/maxresdefault.jpg",
  },
  {
    id: "scheduling", label: "Scheduling", icon: "calendar-clock",
    title: "Post everywhere from one calendar",
    desc: "Compose once and publish to YouTube, TikTok, Instagram, X, LinkedIn and Threads on your schedule — ViewsMax posts for you at the time you pick.",
    youtubeId: "tUKTMHU9mY4", poster: "https://i.ytimg.com/vi/tUKTMHU9mY4/maxresdefault.jpg",
  },
];

function DemoFrame({ demo }: { demo: Demo }) {
  const [playing, setPlaying] = useState(false);
  const [posterOk, setPosterOk] = useState(true);
  const [videoFailed, setVideoFailed] = useState(false);
  const hasSource = Boolean(demo.mp4 || demo.youtubeId);

  // Preflight the mp4 so tabs without a real file show "coming soon" instead of
  // a play button that errors. SPA hosting serves index.html for missing paths,
  // so a 200 alone isn't enough — require a video/* content-type.
  useEffect(() => {
    if (!demo.mp4 || demo.youtubeId) return;
    let cancelled = false;
    fetch(demo.mp4, { method: "HEAD" })
      .then((r) => {
        if (!cancelled && (!r.ok || !(r.headers.get("content-type") || "").startsWith("video/"))) setVideoFailed(true);
      })
      .catch(() => { if (!cancelled) setVideoFailed(true); });
    return () => { cancelled = true; };
  }, [demo.mp4, demo.youtubeId]);

  return (
    <div style={{ position: "relative", width: "100%", aspectRatio: "16 / 9", background: "var(--ink-900)", display: "grid", placeItems: "center", overflow: "hidden" }}>
      {playing && !videoFailed && demo.youtubeId ? (
        <iframe
          src={`https://www.youtube.com/embed/${demo.youtubeId}?autoplay=1`}
          title={`${demo.label} demo`}
          allow="autoplay; encrypted-media; picture-in-picture; fullscreen"
          allowFullScreen
          style={{ position: "absolute", inset: 0, width: "100%", height: "100%", border: "none" }}
        />
      ) : playing && !videoFailed && demo.mp4 ? (
        <video
          src={demo.mp4} poster={posterOk ? demo.poster : undefined}
          autoPlay controls playsInline
          onError={() => setVideoFailed(true)}
          style={{ position: "absolute", inset: 0, width: "100%", height: "100%", objectFit: "cover" }}
        />
      ) : (
        <>
          <div style={{ position: "absolute", inset: 0, background: "radial-gradient(80% 100% at 50% 0%, rgba(255,31,61,.22), transparent 65%)" }} />
          <div style={{ position: "absolute", inset: 0, backgroundImage: "radial-gradient(rgba(255,255,255,.10) 1px, transparent 1px)", backgroundSize: "24px 24px", opacity: .5 }} />
          {demo.poster && posterOk && (
            <img src={demo.poster} alt={`${demo.label} demo`} onError={() => setPosterOk(false)} style={{ position: "absolute", inset: 0, width: "100%", height: "100%", objectFit: "cover" }} />
          )}
          {hasSource && !videoFailed ? (
            <button onClick={() => setPlaying(true)} aria-label={`Play ${demo.label} demo`} style={{ position: "relative", width: 64, height: 64, borderRadius: "50%", background: "var(--vm-red)", display: "grid", placeItems: "center", border: "none", cursor: "pointer", boxShadow: "0 10px 30px -8px rgba(255,31,61,.6)" }}>
              <Ico name="play" size={28} stroke="#fff" />
            </button>
          ) : (
            <div style={{ position: "relative", display: "flex", flexDirection: "column", alignItems: "center", gap: 12 }}>
              <span style={{ width: 56, height: 56, borderRadius: 16, background: "var(--ink-750)", border: "1px solid var(--ink-600)", display: "grid", placeItems: "center" }}><Ico name={demo.icon} size={26} stroke="var(--vm-volt)" /></span>
              <span style={{ fontFamily: "var(--font-mono)", fontSize: 12, fontWeight: 700, letterSpacing: ".1em", color: "var(--fg-3)", textTransform: "uppercase" }}>Demo video coming soon</span>
            </div>
          )}
        </>
      )}
    </div>
  );
}

function FeatureDemos() {
  const navigate = useNavigate();
  const [active, setActive] = useState(DEMOS[0].id);
  const demo = DEMOS.find((d) => d.id === active) ?? DEMOS[0];
  return (
    <section id="demos" style={{ maxWidth: 1200, margin: "0 auto", padding: "0 24px 0", scrollMarginTop: 80 }}>
      <div style={{ display: "flex", justifyContent: "center" }}>
        <div role="tablist" aria-label="Feature demos" className="vm-demo-tabs" style={{ display: "inline-flex", gap: 4, background: "var(--paper-0)", border: "1px solid var(--line-2)", borderRadius: 999, padding: 5, boxShadow: "var(--shadow-sm)", maxWidth: "100%" }}>
          {DEMOS.map((d) => {
            const on = d.id === active;
            return (
              <button key={d.id} role="tab" aria-selected={on} onClick={() => { setActive(d.id); document.getElementById("demos")?.scrollIntoView({ behavior: "smooth", block: "start" }); }}
                style={{ display: "inline-flex", alignItems: "center", gap: 8, background: on ? "var(--ink-900)" : "transparent", color: on ? "#fff" : "var(--ink-on-paper-2)", border: "none", borderRadius: 999, padding: "10px 18px", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 14.5, cursor: "pointer", transition: "background var(--dur), color var(--dur)", whiteSpace: "nowrap" }}>
                <Ico name={d.icon} size={16} stroke={on ? "var(--vm-volt)" : "var(--ink-on-paper-3)"} />{d.label}
              </button>
            );
          })}
        </div>
      </div>
      <div style={{ maxWidth: 960, margin: "34px auto 0", background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 24, overflow: "hidden", boxShadow: "0 30px 70px -28px rgba(10,10,12,.28), 0 8px 24px -16px rgba(10,10,12,.14)" }}>
        {/* key remounts the frame on tab switch so playback state resets */}
        <DemoFrame key={demo.id} demo={demo} />
        <div className="vm-demo-caption" style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 18, padding: "20px 24px" }}>
          <div>
            <h3 style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 20, letterSpacing: "-.02em", color: "var(--ink-on-paper-1)", margin: 0 }}>{demo.title}</h3>
            <p style={{ fontFamily: "var(--font-body)", fontSize: 14.5, color: "var(--ink-on-paper-2)", lineHeight: 1.5, margin: "8px 0 0", maxWidth: 620 }}>{demo.desc}</p>
          </div>
          <Btn onClick={() => navigate(SIGNUP)} style={{ flexShrink: 0 }}>Try it free</Btn>
        </div>
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

/* ---------- Landing-scoped CSS (keyframes, hover, responsive) ---------- */
const LANDING_CSS = `
.vm-landing a { transition: color var(--dur); }
.vm-landing a:hover { color: var(--vm-red); }
.vm-landing input::placeholder { color: var(--fg-4); }
.vm-landing .vm-feature:hover { transform: translateY(-3px); box-shadow: 6px 6px 0 0 var(--vm-red) !important; }
@keyframes vmrise { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
@media (max-width: 1080px) { .vm-landing .vm-pricing-grid { grid-template-columns: repeat(2,1fr) !important; } }
@media (max-width: 860px) {
  .vm-landing .vm-split { grid-template-columns: 1fr !important; }
  .vm-landing .vm-grid3 { grid-template-columns: 1fr !important; }
}
@media (max-width: 720px) {
  .vm-landing .vm-metrics { grid-template-columns: repeat(2,1fr) !important; }
  .vm-landing .vm-metrics > div:nth-child(odd) { border-left: none !important; }
}
@media (max-width: 640px) {
  .vm-landing .vm-pricing-grid { grid-template-columns: 1fr !important; }
  .vm-landing .vm-demo-tabs { width: 100%; }
  .vm-landing .vm-demo-tabs button { flex: 1; justify-content: center; padding: 10px 8px !important; }
  .vm-landing .vm-demo-caption { flex-direction: column; align-items: flex-start !important; }
}
`;

export default function ViewsMaxLanding() {
  const { hash } = useLocation();
  useEffect(() => {
    if (hash) scrollToId(hash.slice(1));
  }, [hash]);
  return (
    <div className="vm-landing" style={{ background: "var(--paper-1)" }}>
      <style>{LANDING_CSS}</style>
      <LandingNav />
      <Hero />
      <FeatureDemos />
      <Features />
      <Channels />
      <Monetize />
      <Pricing />
      <CTA />
      <LandingFooter />
    </div>
  );
}
