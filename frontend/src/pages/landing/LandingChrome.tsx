// Shared marketing chrome: the landing page's Nav + Footer, extracted so public
// pages outside ViewsMaxLanding (e.g. /ai) get the same logged-out navigation.
// Styled with the design-system tokens in index.css (--vm-*, --ink-*, --paper-*).
import { type CSSProperties, type ReactNode } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import { ChevronDown } from "lucide-react";
import logoLight from "@/assets/logo-lockup-light.svg";
import logoDark from "@/assets/logo-lockup-dark.svg";
import { API_BASE_URL } from "@/lib/api-service";

const AUTH = "/auth";
const API_DOCS = `${API_BASE_URL}/docs`;
const BLOG = "https://blog.viewsmax.com";
const RESOURCES: { label: string; desc: string; href: string; external?: boolean }[] = [
  { label: "API docs", desc: "REST API reference & OpenAPI spec", href: API_DOCS, external: true },
  { label: "Install MCP", desc: "Connect Claude, ChatGPT, Cursor & more", href: "/ai" },
  { label: "CLI setup", desc: "Use ViewsMax from Claude Code", href: "/ai#cli" },
  { label: "Blog", desc: "Growth tactics & product updates", href: BLOG, external: true },
  { label: "Support", desc: "Join our Discord for help & updates", href: "https://discord.gg/Wwe57w3Dv5", external: true },
  { label: "GitHub", desc: "ViewsMax examples, SDKs & issues", href: "https://github.com/sean-no-code/viewsmax", external: true },
];
const FREE_TOOLS: typeof RESOURCES = [
  { label: "YouTube Transcript", desc: "Download & copy any YouTube transcript", href: "/free-tools/youtube-transcript" },
  { label: "TikTok Transcript", desc: "Convert any TikTok video to text", href: "/free-tools/tiktok-transcript" },
  { label: "Instagram Transcript", desc: "Turn Reels & videos into text", href: "/free-tools/instagram-transcript" },
  { label: "Thumbnail Preview", desc: "See your thumbnail on YouTube's home page", href: "/thumbnail-preview" },
  { label: "Revenue Calculator", desc: "Estimate your YouTube earnings", href: "/youtube-monetization-calculator" },
];
const scrollToId = (id: string) => document.getElementById(id)?.scrollIntoView({ behavior: "smooth", block: "start" });

/* ---------- Button (shared with the landing sections) ---------- */
export type Variant = "primary" | "aqua" | "dark" | "outline" | "ghost";
export function Btn({ children, variant = "primary", size = "md", onClick, style }: {
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

/* ---------- Nav dropdown (Free Tools, Resources) ---------- */
function NavDropdown({ label, href, items }: { label: string; href: string; items: typeof RESOURCES }) {
  const navigate = useNavigate();
  return (
    <div className="nav-dd" style={{ position: "relative" }}>
      <a href={href} onClick={(e) => { e.preventDefault(); navigate(href); }} style={{ color: "var(--ink-on-paper-2)", textDecoration: "none", fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 14.5, display: "inline-flex", alignItems: "center", gap: 4 }}>{label} <ChevronDown size={14} style={{ display: "block" }} /></a>
      <div className="nav-dd-panel" style={{ position: "absolute", top: "100%", left: -12, paddingTop: 10 }}>
        <div style={{ minWidth: 260, background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 14, padding: 8, boxShadow: "0 16px 40px rgba(16,14,12,.12)" }}>
          {items.map((r) => (
            <a key={r.label} href={r.href}
              {...(r.external ? { target: "_blank", rel: "noreferrer" } : { onClick: (e: React.MouseEvent) => { e.preventDefault(); navigate(r.href); } })}
              style={{ display: "block", padding: "9px 12px", borderRadius: 8, textDecoration: "none" }}>
              <span style={{ display: "block", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 14, color: "var(--ink-on-paper-1)" }}>{r.label}</span>
              <span style={{ display: "block", fontFamily: "var(--font-body)", fontSize: 12.5, color: "var(--ink-on-paper-2)", marginTop: 2 }}>{r.desc}</span>
            </a>
          ))}
        </div>
      </div>
    </div>
  );
}

/* ---------- Nav ---------- */
export function LandingNav() {
  const navigate = useNavigate();
  const { pathname } = useLocation();
  const links: [string, string][] = [["Features", "demos"], ["Channels", "channels"], ["Pricing", "pricing"]];
  // Section links scroll in place on the landing page; elsewhere they route home first.
  const goToSection = (id: string) => { if (pathname === "/") scrollToId(id); else navigate(`/#${id}`); };
  return (
    <nav className="vm-nav" style={{ position: "sticky", top: 0, zIndex: 50, background: "rgba(250,250,248,.82)", backdropFilter: "blur(12px)", borderBottom: "1px solid var(--line-1)" }}>
      <style>{CHROME_CSS}</style>
      <div style={{ maxWidth: 1200, margin: "0 auto", padding: "14px 24px", display: "flex", alignItems: "center", gap: 24 }}>
        <a href="/" onClick={(e) => { e.preventDefault(); navigate("/"); }} style={{ display: "block" }}><img src={logoLight} alt="ViewsMax" style={{ height: 30, display: "block" }} /></a>
        <div className="nav-links" style={{ display: "flex", gap: 28, marginLeft: 16 }}>
          {links.map(([l, id]) => <a key={l} href={`/#${id}`} onClick={(e) => { e.preventDefault(); goToSection(id); }} style={{ color: "var(--ink-on-paper-2)", textDecoration: "none", fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 14.5 }}>{l}</a>)}
          <NavDropdown label="Free Tools" href="/free-tools" items={FREE_TOOLS} />
          <NavDropdown label="Resources" href="/ai" items={RESOURCES} />
        </div>
        <div style={{ marginLeft: "auto", display: "flex", alignItems: "center", gap: 14 }}>
          <a href={AUTH} onClick={(e) => { e.preventDefault(); navigate(AUTH); }} style={{ color: "var(--ink-on-paper-1)", textDecoration: "none", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 14.5, whiteSpace: "nowrap" }}>Log in</a>
          <Btn size="sm" onClick={() => navigate(AUTH)}>Start for $0</Btn>
        </div>
      </div>
    </nav>
  );
}

/* ---------- Footer ---------- */
export function LandingFooter() {
  const cols: [string, { label: string; href: string }[]][] = [
    ["Product", [{ label: "Scheduler", href: "#" }, { label: "Sales tracking", href: "#" }, { label: "Analytics", href: "#" }, { label: "Channels", href: "#" }, { label: "Pricing", href: "#" }]],
    ["Company", [{ label: "About", href: "#" }, { label: "Careers", href: "#" }, { label: "Blog", href: BLOG }, { label: "Contact", href: "#" }, { label: "Affiliates", href: "https://viewsmax.getrewardful.com/signup" }]],
    ["Resources", [{ label: "API docs", href: API_DOCS }, { label: "Install MCP", href: "/ai" }, { label: "CLI setup", href: "/ai#cli" }]],
    ["Free tools", [{ label: "YouTube Transcript", href: "/free-tools/youtube-transcript" }, { label: "TikTok Transcript", href: "/free-tools/tiktok-transcript" }, { label: "Instagram Transcript", href: "/free-tools/instagram-transcript" }, { label: "Thumbnail Preview", href: "/thumbnail-preview" }, { label: "Revenue Calculator", href: "/youtube-monetization-calculator" }]],
  ];
  return (
    <footer className="vm-foot" style={{ background: "var(--ink-900)", color: "var(--fg-2)" }}>
      <div className="vm-foot-grid" style={{ maxWidth: 1200, margin: "0 auto", padding: "64px 24px 40px", display: "grid", gridTemplateColumns: "1.4fr 1fr 1fr 1fr 1fr", gap: 32 }}>
        <div>
          <img src={logoDark} alt="ViewsMax" style={{ height: 30 }} />
          <p style={{ fontFamily: "var(--font-body)", fontSize: 14, color: "var(--fg-3)", lineHeight: 1.5, marginTop: 16, maxWidth: 240 }}>Make content that makes sales.</p>
        </div>
        {cols.map(([h, links]) => (
          <div key={h}>
            <div style={{ fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 13, color: "var(--fg-1)", marginBottom: 14 }}>{h}</div>
            <div style={{ display: "flex", flexDirection: "column", gap: 10 }}>
              {links.map((l) => <a key={l.label} href={l.href} {...(l.href.startsWith("http") ? { target: "_blank", rel: "noreferrer" } : {})} style={{ fontFamily: "var(--font-body)", fontSize: 14, color: "var(--fg-3)", textDecoration: "none" }}>{l.label}</a>)}
            </div>
          </div>
        ))}
      </div>
      <div style={{ borderTop: "1px solid var(--ink-700)" }}>
        <div style={{ maxWidth: 1200, margin: "0 auto", padding: "20px 24px", display: "flex", justifyContent: "space-between", flexWrap: "wrap", gap: 12, fontFamily: "var(--font-body)", fontSize: 13, color: "var(--fg-3)" }}>
          <span>© 2026 ViewsMax. Not affiliated with the social platforms shown.</span>
          <span style={{ display: "flex", gap: 20 }}><a href="/privacy" style={{ color: "var(--fg-3)", textDecoration: "none" }}>Privacy</a><a href="/terms" style={{ color: "var(--fg-3)", textDecoration: "none" }}>Terms</a></span>
        </div>
      </div>
    </footer>
  );
}

/* ---------- Chrome-scoped CSS (works with or without the .vm-landing wrapper) ---------- */
const CHROME_CSS = `
.vm-nav a, .vm-foot a { transition: color var(--dur); }
.vm-nav a:hover, .vm-foot a:hover { color: var(--vm-red); }
.vm-nav .nav-links a:hover { color: var(--ink-on-paper-1); }
.vm-nav .nav-dd-panel { opacity: 0; pointer-events: none; transform: translateY(6px); transition: opacity var(--dur), transform var(--dur); }
.vm-nav .nav-dd:hover .nav-dd-panel, .vm-nav .nav-dd:focus-within .nav-dd-panel { opacity: 1; pointer-events: auto; transform: none; }
.vm-nav .nav-dd-panel a:hover { background: var(--paper-2); }
@media (max-width: 860px) {
  .vm-nav .nav-links { display: none !important; }
  .vm-foot .vm-foot-grid { grid-template-columns: 1fr 1fr !important; }
}
`;
