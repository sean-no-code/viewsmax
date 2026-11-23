// Shared marketing chrome: the landing page's Nav + Footer, extracted so public
// pages outside ViewsMaxLanding (e.g. /ai) get the same logged-out navigation.
// Styled with the design-system tokens in index.css (--vm-*, --ink-*, --paper-*).
import { useState, type CSSProperties, type ReactNode } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import { ArrowRight, BookOpen, Braces, Calculator, ChevronDown, Image as ImageIcon, LifeBuoy, Plug, Terminal, type LucideIcon } from "lucide-react";
import logoLight from "@/assets/logo-lockup-light.svg";
import logoDark from "@/assets/logo-lockup-dark.svg";
import { API_BASE_URL } from "@/lib/api-service";
import { AGENT_GROUPS, AGENT_LIST } from "@/lib/agent-pages";
import { CHANNEL_LIST, CHANNELS_PATH } from "@/lib/channel-pages";
import LocaleFlag from "@/components/LocaleFlag";
import { AgentIcon, LogoTile } from "@/components/agents/agent-icons";
import { BrandIcon } from "@/components/post/brand-icons";
import { HOME_LOCALES, localizedPath, useHomeLocale, useLandingCopy, usePagePath, type HomeLocale, type LandingCopy } from "./locales";

const AUTH = "/auth";
const API_DOCS = `${API_BASE_URL}/docs`;
const BLOG = "https://blog.viewsmax.com";
type NavItem = { label: string; desc: string; href: string; external?: boolean; icon?: ReactNode };
// Menu text comes from the homepage locale; the pages they link to are English.
// A neutral tile for menu items that have no brand of their own.
const plainTile = (Icon: LucideIcon) => (
  <span aria-hidden style={{ display: "grid", placeItems: "center", flexShrink: 0, width: 32, height: 32, borderRadius: 9, background: "var(--paper-2)", border: "1px solid var(--line-1)", color: "var(--ink-on-paper-1)" }}>
    <Icon size={17} strokeWidth={2} style={{ display: "block" }} />
  </span>
);
const channelTile = (key: string, color: string) => (
  <span aria-hidden style={{ display: "grid", placeItems: "center", flexShrink: 0, width: 32, height: 32, borderRadius: 9, background: color, color: "#fff" }}>
    <BrandIcon platform={key} size={18} />
  </span>
);
const resources = ({ resources: r }: LandingCopy): NavItem[] => [
  { ...r.api, href: API_DOCS, external: true, icon: plainTile(Braces) },
  { ...r.mcp, href: "/mcp", icon: plainTile(Plug) },
  { ...r.cli, href: "/ai#cli", icon: plainTile(Terminal) },
  { ...r.blog, href: BLOG, external: true, icon: plainTile(BookOpen) },
  { ...r.support, href: "https://discord.gg/Wwe57w3Dv5", external: true, icon: plainTile(LifeBuoy) },
  { ...r.github, href: "https://github.com/sean-no-code/viewsmax", external: true, icon: <LogoTile logo="github" /> },
];
const freeTools = ({ freeTools: f }: LandingCopy): NavItem[] => [
  { ...f.youtube, href: "/free-tools/youtube-transcript", icon: channelTile("youtube", "#FF0000") },
  { ...f.tiktok, href: "/free-tools/tiktok-transcript", icon: channelTile("tiktok", "#0A0A0C") },
  { ...f.instagram, href: "/free-tools/instagram-transcript", icon: channelTile("instagram", "#E1306C") },
  { ...f.thumbnail, href: "/thumbnail-preview", icon: plainTile(ImageIcon) },
  { ...f.revenue, href: "/youtube-monetization-calculator", icon: plainTile(Calculator) },
];
const aiAgents = (c: LandingCopy): NavItem[] =>
  AGENT_LIST.map((a) => ({ label: a.name, desc: c.agentCards[a.key], href: a.slug, icon: <AgentIcon agent={a.key} size={32} /> }));
/** A menu column. With a heading it is a labelled group; `columns` lays its items out in a grid. */
type NavGroup = { heading?: string; columns?: number; items: NavItem[] };
// One column per AGENT_GROUPS entry; an agent's column comes from its `group` in agent-pages.ts.
const aiAgentGroups = (c: LandingCopy): NavGroup[] =>
  AGENT_GROUPS.map((g) => ({
    heading: c.agentGroups[g],
    items: aiAgents(c).filter((_, i) => AGENT_LIST[i].group === g),
  }));
const channels = (c: LandingCopy): NavItem[] =>
  CHANNEL_LIST.map((ch) => ({ label: ch.name, desc: c.channelCards[ch.key], href: ch.slug, icon: channelTile(ch.key, ch.color) }));
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

/* ---------- Closing a menu after a click ---------- */
// The menus open on :hover, and after a click the pointer is still over the
// panel, so on a client-side navigation the menu would stay open over the new
// page. Clicking shuts it (the `shut` class beats :hover and :focus-within)
// until the pointer leaves the menu or the trigger is focused again.
function useMenuShut() {
  const [shut, setShut] = useState(false);
  return {
    shut,
    close: () => { setShut(true); (document.activeElement as HTMLElement | null)?.blur(); },
    reopen: () => setShut(false),
  };
}

/* ---------- Nav dropdown (Free Tools, Resources) ---------- */
function NavDropdown({ label, href, items }: { label: string; href: string; items: NavItem[] }) {
  const navigate = useNavigate();
  const menu = useMenuShut();
  return (
    <div className={`nav-dd${menu.shut ? " shut" : ""}`} style={{ position: "relative" }} onMouseLeave={menu.reopen}>
      <a href={href} onFocus={menu.reopen} onClick={(e) => { e.preventDefault(); menu.close(); navigate(href); }} style={{ color: "var(--ink-on-paper-2)", textDecoration: "none", fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 14.5, display: "inline-flex", alignItems: "center", gap: 4, whiteSpace: "nowrap" }}>{label} <ChevronDown size={14} style={{ display: "block" }} /></a>
      <div className="nav-dd-panel" style={{ position: "absolute", top: "100%", left: -12, paddingTop: 10 }}>
        <div style={{ minWidth: 300, background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 14, padding: 8, boxShadow: "0 16px 40px rgba(16,14,12,.12)" }}>
          {items.map((r) => (
            <a key={r.label} href={r.href}
              {...(r.external ? { target: "_blank", rel: "noreferrer", onClick: menu.close } : { onClick: (e: React.MouseEvent) => { e.preventDefault(); menu.close(); navigate(r.href); } })}
              style={{ display: "flex", alignItems: "flex-start", gap: 11, padding: "9px 12px", borderRadius: 8, textDecoration: "none" }}>
              {r.icon}
              <span style={{ minWidth: 0 }}>
                <span style={{ display: "block", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 14, color: "var(--ink-on-paper-1)" }}>{r.label}</span>
                <span style={{ display: "block", fontFamily: "var(--font-body)", fontSize: 12.5, color: "var(--ink-on-paper-2)", marginTop: 2 }}>{r.desc}</span>
              </span>
            </a>
          ))}
        </div>
      </div>
    </div>
  );
}

/* ---------- Mega menu (Channels, AI Agents): a wide panel of icon links, in columns ---------- */
// The panel hangs off the nav container, not the trigger, so it can span the
// page width. The trigger is stretched to the nav's full height and the panel's
// transparent top padding covers the nav's bottom padding, so there is no gap
// to lose :hover in on the way down.
function NavMega({ label, href, groups, allLabel, maxWidth }: { label: string; href: string; groups: NavGroup[]; allLabel: string; maxWidth?: number }) {
  const navigate = useNavigate();
  const menu = useMenuShut();
  const go = (to: string) => (e: React.MouseEvent) => { e.preventDefault(); menu.close(); navigate(to); };
  return (
    <div className={`nav-dd nav-mega${menu.shut ? " shut" : ""}`} onMouseLeave={menu.reopen}>
      <a href={href} onFocus={menu.reopen} onClick={go(href)} style={{ color: "var(--ink-on-paper-2)", textDecoration: "none", fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 14.5, display: "inline-flex", alignItems: "center", gap: 4, whiteSpace: "nowrap" }}>{label} <ChevronDown size={14} style={{ display: "block" }} /></a>
      <div className="nav-dd-panel nav-mega-panel">
        <div style={{ maxWidth, background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 16, boxShadow: "0 24px 60px rgba(16,14,12,.14)", overflow: "hidden" }}>
          <div className="nav-mega-grid" style={{ display: "grid", gridTemplateColumns: `repeat(${groups.length}, minmax(0, 1fr))`, gap: 8, padding: "18px 14px 12px" }}>
            {groups.map((g, i) => (
              <div key={g.heading ?? i} {...(g.heading ? { role: "group", "aria-label": g.heading } : {})}>
                {g.heading && (
                  <div style={{ padding: "0 12px 6px", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 11.5, letterSpacing: ".08em", textTransform: "uppercase", color: "var(--ink-on-paper-3)" }}>{g.heading}</div>
                )}
                <div style={{ display: "grid", gridTemplateColumns: `repeat(${g.columns ?? 1}, minmax(0, 1fr))`, gap: g.columns ? "2px 8px" : 0 }}>
                  {g.items.map((r) => (
                    <a key={r.href} href={r.href} onClick={go(r.href)} style={{ display: "flex", alignItems: "flex-start", gap: 11, padding: "8px 12px", borderRadius: 8, textDecoration: "none" }}>
                      {r.icon}
                      <span style={{ minWidth: 0 }}>
                        <span style={{ display: "block", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 14, color: "var(--ink-on-paper-1)" }}>{r.label}</span>
                        <span style={{ display: "block", fontFamily: "var(--font-body)", fontSize: 12.5, lineHeight: 1.35, color: "var(--ink-on-paper-2)", marginTop: 2 }}>{r.desc}</span>
                      </span>
                    </a>
                  ))}
                </div>
              </div>
            ))}
          </div>
          <div style={{ borderTop: "1px solid var(--line-1)", background: "var(--paper-1)", padding: "10px 14px" }}>
            <a href={href} onClick={go(href)} className="nav-mega-all" style={{ display: "inline-flex", alignItems: "center", gap: 6, padding: "6px 12px", borderRadius: 8, textDecoration: "none", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 13.5, color: "var(--ink-on-paper-1)" }}>{allLabel} <ArrowRight size={14} style={{ display: "block" }} /></a>
          </div>
        </div>
      </div>
    </div>
  );
}

/* ---------- Language picker (translated pages only) ---------- */
// Plain links, not client-side navigation: each language version is its own
// prerendered file with its own <html lang> and head tags.
function LanguagePicker({ current, label }: { current: HomeLocale; label: string }) {
  const page = usePagePath();
  const [open, setOpen] = useState(false); // click/tap; hover also opens it via CSS
  return (
    <div className={`nav-dd nav-lang${open ? " open" : ""}`} style={{ position: "relative" }}
      onBlur={(e) => { if (!e.currentTarget.contains(e.relatedTarget)) setOpen(false); }}>
      <button type="button" aria-label={label} aria-expanded={open} onClick={(e) => { if (open) e.currentTarget.blur(); setOpen(!open); }}
        style={{ display: "inline-flex", alignItems: "center", gap: 6, background: "none", border: "none", cursor: "pointer", padding: 0, color: "var(--ink-on-paper-2)", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 13, textTransform: "uppercase" }}>
        <LocaleFlag code={current.flag} size={20} /><ChevronDown size={14} style={{ display: "block" }} />
      </button>
      <div className="nav-dd-panel" style={{ position: "absolute", top: "100%", right: -12, paddingTop: 10 }}>
        <div style={{ minWidth: 200, background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 14, padding: 6, boxShadow: "0 16px 40px rgba(16,14,12,.12)" }}>
          {HOME_LOCALES.map((l) => (
            <a key={l.code} href={localizedPath(l, page)} hrefLang={l.htmlLang} lang={l.htmlLang}
              style={{ display: "flex", alignItems: "center", gap: 10, padding: "8px 10px", borderRadius: 8, textDecoration: "none", fontFamily: "var(--font-body)", fontSize: 14, fontWeight: l.code === current.code ? 700 : 500, color: "var(--ink-on-paper-1)" }}>
              <LocaleFlag code={l.flag} />{l.label}
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
  const home = useHomeLocale();
  const c = useLandingCopy();
  const homePath = home?.path ?? "/";
  // Section links scroll in place on the landing page; elsewhere they route home first.
  const goToSection = (id: string) => { if (pathname === homePath) scrollToId(id); else navigate(`${homePath}#${id}`); };
  const sectionLink = (label: string, id: string) => <a href={`${homePath}#${id}`} onClick={(e) => { e.preventDefault(); goToSection(id); }} style={{ color: "var(--ink-on-paper-2)", textDecoration: "none", fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 14.5, display: "inline-flex", alignItems: "center", whiteSpace: "nowrap" }}>{label}</a>;
  return (
    <nav className="vm-nav" style={{ position: "sticky", top: 0, zIndex: 50, background: "rgba(250,250,248,.82)", backdropFilter: "blur(12px)", borderBottom: "1px solid var(--line-1)" }}>
      <style>{CHROME_CSS}</style>
      <div className="nav-inner" style={{ position: "relative", maxWidth: 1200, margin: "0 auto", padding: "14px 24px", display: "flex", alignItems: "center", gap: 24 }}>
        <a href={homePath} onClick={(e) => { e.preventDefault(); navigate(homePath); }} style={{ display: "block", flexShrink: 0 }}><img className="nav-logo" src={logoLight} alt="ViewsMax" style={{ height: 30, display: "block" }} /></a>
        <div className="nav-links" style={{ display: "flex", alignItems: "center", gap: 22, marginLeft: 16 }}>
          {sectionLink(c.nav.features, "demos")}
          <NavMega label={c.nav.channels} href={CHANNELS_PATH} groups={[{ columns: 3, items: channels(c) }]} allLabel={c.nav.allChannels} maxWidth={860} />
          {sectionLink(c.nav.pricing, "pricing")}
          <NavDropdown label={c.nav.freeTools} href="/free-tools" items={freeTools(c)} />
          <NavMega label={c.nav.aiAgents} href="/ai" groups={aiAgentGroups(c)} allLabel={c.agentGroups.all} />
          <NavDropdown label={c.nav.resources} href="/ai" items={resources(c)} />
        </div>
        <div style={{ marginLeft: "auto", display: "flex", alignItems: "center", gap: 14 }}>
          {home && <LanguagePicker current={home} label={c.nav.language} />}
          <a href={AUTH} onClick={(e) => { e.preventDefault(); navigate(AUTH); }} style={{ color: "var(--ink-on-paper-1)", textDecoration: "none", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 14.5, whiteSpace: "nowrap" }}>{c.nav.logIn}</a>
          <span className="nav-start"><Btn size="sm" onClick={() => navigate(AUTH)}>{c.nav.start}</Btn></span>
        </div>
      </div>
    </nav>
  );
}

/* ---------- Footer ---------- */
export function LandingFooter() {
  const home = useHomeLocale();
  const page = usePagePath();
  const c = useLandingCopy();
  const f = c.footer;
  const links = (items: NavItem[]) => items.map(({ label, href }) => ({ label, href }));
  const cols: [string, { label: string; href: string }[]][] = [
    [f.product, [{ label: f.scheduler, href: "#" }, { label: f.salesTracking, href: "#" }, { label: f.analytics, href: "#" }, { label: c.nav.channels, href: CHANNELS_PATH }, { label: c.nav.pricing, href: "#" }]],
    [f.company, [{ label: f.about, href: "#" }, { label: f.careers, href: "#" }, { label: c.resources.blog.label, href: BLOG }, { label: f.contact, href: "#" }, { label: f.affiliates, href: "https://viewsmax.getrewardful.com/signup" }]],
    [c.nav.resources, links(resources(c).slice(0, 3))],
    [c.nav.aiAgents, links(aiAgents(c))],
    [c.nav.freeTools, links(freeTools(c))],
  ];
  return (
    <footer className="vm-foot" style={{ background: "var(--ink-900)", color: "var(--fg-2)" }}>
      <div className="vm-foot-grid" style={{ maxWidth: 1200, margin: "0 auto", padding: "64px 24px 40px", display: "grid", gridTemplateColumns: "1.4fr repeat(5, 1fr)", gap: 32 }}>
        <div>
          <img src={logoDark} alt="ViewsMax" style={{ height: 30 }} />
          <p style={{ fontFamily: "var(--font-body)", fontSize: 14, color: "var(--fg-3)", lineHeight: 1.5, marginTop: 16, maxWidth: 240 }}>{f.tagline}</p>
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
          <span>{f.copyright}</span>
          {home && <span aria-label={c.nav.language} style={{ display: "flex", flexWrap: "wrap", gap: 16 }}>
            {HOME_LOCALES.map((l) => (
              <a key={l.code} href={localizedPath(l, page)} hrefLang={l.htmlLang} lang={l.htmlLang} style={{ display: "inline-flex", alignItems: "center", gap: 6, color: l.code === home.code ? "var(--fg-1)" : "var(--fg-3)", textDecoration: "none" }}>
                <LocaleFlag code={l.flag} size={15} />{l.label}
              </a>
            ))}
          </span>}
          <span style={{ display: "flex", gap: 20 }}><a href="/privacy" style={{ color: "var(--fg-3)", textDecoration: "none" }}>{f.privacy}</a><a href="/terms" style={{ color: "var(--fg-3)", textDecoration: "none" }}>{f.terms}</a></span>
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
.vm-nav .nav-dd:hover .nav-dd-panel, .vm-nav .nav-dd:focus-within .nav-dd-panel, .vm-nav .nav-dd.open .nav-dd-panel { opacity: 1; pointer-events: auto; transform: none; }
.vm-nav .nav-dd-panel a:hover { background: var(--paper-2); }
.vm-nav .nav-dd.shut .nav-dd-panel { opacity: 0 !important; pointer-events: none !important; transform: translateY(6px) !important; }
/* Mega menu: anchored to the nav container (the trigger is not positioned). */
.vm-nav .nav-links { align-self: stretch; }
.vm-nav .nav-mega { align-self: stretch; display: flex; align-items: center; }
.vm-nav .nav-mega-panel { position: absolute; left: 24px; right: 24px; top: calc(100% - 15px); padding-top: 23px; }
/* Widths measured with the homepage language picker; translated labels run longer. */
@media (max-width: 980px) { .vm-nav .nav-links { display: none !important; } }
@media (max-width: 1060px) { html:not([lang="en"]) .vm-nav .nav-links { display: none !important; } }
@media (max-width: 860px) { .vm-foot .vm-foot-grid { grid-template-columns: 1fr 1fr !important; } }
/* No room next to the logo and CTAs on phones; the footer lists the languages. */
@media (max-width: 480px) {
  .vm-nav .nav-lang { display: none !important; }
  .vm-nav .nav-inner { padding: 12px 16px !important; gap: 16px !important; }
  .vm-nav .nav-logo { height: 24px !important; }
}
/* The hero's own CTA sits right below. */
@media (max-width: 380px) { .vm-nav .nav-start { display: none; } }
`;
