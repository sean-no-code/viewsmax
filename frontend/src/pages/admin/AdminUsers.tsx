// Admin — users monitor. Index of all users plus signup / added-card /
// subscribed widgets over a date range. Server-gated by role:admin; this page
// also hides itself from non-admins.
//
// The table follows the index-page contract: a collapsible Filters panel
// (typeahead name/email, date range, card + plan multi-selects), sortable
// columns, server-side pagination — all persisted in the URL query string.
import { Fragment, useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { ExternalLink } from "lucide-react";
import { Icon, SectionHead, Sparkline } from "@/components/analytics/primitives";
import { AnalyticsLoading } from "@/components/analytics/useAnalytics";
import { PostShell } from "@/components/post/PostList";
import { useAuth } from "@/hooks/useAuth";
import { viewsMaxApi, type AdminUser, type AdminUserAccount, type AdminUserSort, type AdminUserStats } from "@/lib/api-service";
import { durationBetween } from "@/lib/format-duration";
import { toast } from "sonner";

const mono = { fontFamily: "var(--font-mono)", fontSize: 11.5 } as const;

// Local YYYY-MM-DD for a Date offset by `daysAgo`.
const ymd = (daysAgo = 0) => {
  const d = new Date();
  d.setDate(d.getDate() - daysAgo);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
};

const PRESETS = [
  { key: "7d", label: "7 days", days: 7 },
  { key: "30d", label: "30 days", days: 30 },
  { key: "90d", label: "90 days", days: 90 },
] as const;

const CARD_OPTIONS = [
  { value: "yes", label: "Has card" },
  { value: "no", label: "No card" },
];

const CANCELLED_OPTIONS = [
  { value: "yes", label: "Cancelled" },
  { value: "no", label: "Not cancelled" },
];

const COLUMNS: { key: AdminUserSort; label: string; align?: "left" | "center" }[] = [
  { key: "name", label: "Name" },
  { key: "email", label: "Email" },
  { key: "created_at", label: "Signed up" },
  { key: "last_login_at", label: "Last login" },
  { key: "has_card", label: "Card", align: "center" },
  { key: "plan", label: "Plan" },
  { key: "posts_count", label: "Posts", align: "center" },
  { key: "accounts_count", label: "Accounts", align: "center" },
  { key: "offers_count", label: "Offers", align: "center" },
  { key: "first_action_at", label: "First action" },
  { key: "subscription_cancelled_at", label: "Cancelled" },
];

// New-column sorts default to desc on first click (most engaged / most recent
// first); text columns stay asc.
const DESC_FIRST: AdminUserSort[] = [
  "created_at", "posts_count", "accounts_count", "offers_count", "first_action_at", "subscription_cancelled_at",
];

const fmtDate = (iso: string | null) => {
  if (!iso) return "—";
  const d = new Date(iso);
  return Number.isNaN(d.getTime()) ? "—" : d.toLocaleDateString([], { month: "short", day: "numeric", year: "numeric" });
};

// Two-letter ISO country code → flag emoji (regional indicator symbols).
const flagEmoji = (code: string | null) => {
  const cc = (code || "").toUpperCase();
  if (!/^[A-Z]{2}$/.test(cc)) return "";
  return String.fromCodePoint(...[...cc].map((ch) => 0x1f1e6 + ch.charCodeAt(0) - 65));
};

const inputStyle = { fontFamily: "var(--font-body)", fontSize: 13, color: "var(--ink-on-paper-1)", background: "var(--paper-0)", border: "1px solid var(--line-2)", borderRadius: 10, padding: "8px 10px", outline: "none", width: "100%", boxSizing: "border-box" as const };
const menuWrap = { position: "absolute" as const, top: 40, left: 0, zIndex: 30, minWidth: 200, maxHeight: 260, overflowY: "auto" as const, background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 12, padding: 6, boxShadow: "0 16px 40px -10px rgba(0,0,0,.28)" };

// ---- Typeahead: type to filter, pick from matching existing values ----
function Typeahead({ label, field, value, onChange }: { label: string; field: "name" | "email"; value: string; onChange: (v: string) => void }) {
  const [input, setInput] = useState(value);
  const [opts, setOpts] = useState<string[]>([]);
  const [open, setOpen] = useState(false);
  const blurTimer = useRef<number>();

  useEffect(() => { setInput(value); }, [value]);

  // Debounce (300ms): apply the filter AND refresh the suggestion list.
  useEffect(() => {
    const t = window.setTimeout(async () => {
      if (input.trim() !== value) onChange(input.trim());
      const res = await viewsMaxApi.getAdminUserSuggest(field, input.trim() || undefined);
      if (res.success) setOpts(res.data || []);
    }, 300);
    return () => window.clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [input]);

  return (
    <div style={{ position: "relative" }}>
      <div style={{ ...mono, fontSize: 10.5, textTransform: "uppercase", letterSpacing: ".04em", color: "var(--ink-on-paper-3)", marginBottom: 5 }}>{label}</div>
      <input
        value={input}
        placeholder={`Search ${label.toLowerCase()}…`}
        onChange={(e) => setInput(e.target.value)}
        onFocus={() => setOpen(true)}
        onBlur={() => { blurTimer.current = window.setTimeout(() => setOpen(false), 150); }}
        style={inputStyle}
      />
      {open && opts.length > 0 && (
        <div style={menuWrap} onMouseDown={() => window.clearTimeout(blurTimer.current)}>
          {opts.map((o) => (
            <button key={o} onMouseDown={() => { setInput(o); onChange(o); setOpen(false); }}
              style={{ display: "block", width: "100%", textAlign: "left", border: "none", background: "none", cursor: "pointer", padding: "7px 10px", borderRadius: 8, fontFamily: "var(--font-body)", fontSize: 13, color: "var(--ink-on-paper-2)", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}
              onMouseEnter={(e) => (e.currentTarget.style.background = "var(--paper-2)")}
              onMouseLeave={(e) => (e.currentTarget.style.background = "none")}>{o}</button>
          ))}
        </div>
      )}
    </div>
  );
}

// ---- MultiSelect: checkbox dropdown for enum fields ----
function MultiSelect({ label, options, value, onChange }: { label: string; options: { value: string; label: string }[]; value: string[]; onChange: (v: string[]) => void }) {
  const [open, setOpen] = useState(false);
  const toggle = (v: string) => onChange(value.includes(v) ? value.filter((x) => x !== v) : [...value, v]);
  const summary = value.length === 0 ? "Any" : value.length === 1 ? (options.find((o) => o.value === value[0])?.label ?? value[0]) : `${value.length} selected`;

  return (
    <div style={{ position: "relative" }}>
      <div style={{ ...mono, fontSize: 10.5, textTransform: "uppercase", letterSpacing: ".04em", color: "var(--ink-on-paper-3)", marginBottom: 5 }}>{label}</div>
      <button onClick={() => setOpen((o) => !o)} style={{ ...inputStyle, textAlign: "left", cursor: "pointer", display: "flex", alignItems: "center", justifyContent: "space-between", gap: 8 }}>
        <span style={{ color: value.length ? "var(--ink-on-paper-1)" : "var(--ink-on-paper-3)", overflow: "hidden", textOverflow: "ellipsis", whiteSpace: "nowrap" }}>{summary}</span>
        <Icon name="chevron-down" size={14} stroke="var(--ink-on-paper-3)" />
      </button>
      {open && (
        <>
          <div onClick={() => setOpen(false)} style={{ position: "fixed", inset: 0, zIndex: 25 }} />
          <div style={{ ...menuWrap, zIndex: 30 }}>
            {options.length === 0 && <div style={{ ...mono, color: "var(--ink-on-paper-3)", padding: "6px 8px" }}>No options</div>}
            {options.map((o) => {
              const on = value.includes(o.value);
              return (
                <button key={o.value} onClick={() => toggle(o.value)}
                  style={{ display: "flex", alignItems: "center", gap: 8, width: "100%", textAlign: "left", border: "none", background: "none", cursor: "pointer", padding: "7px 10px", borderRadius: 8, fontFamily: "var(--font-body)", fontSize: 13, color: "var(--ink-on-paper-1)" }}
                  onMouseEnter={(e) => (e.currentTarget.style.background = "var(--paper-2)")}
                  onMouseLeave={(e) => (e.currentTarget.style.background = "none")}>
                  <span style={{ width: 16, height: 16, borderRadius: 5, border: "1px solid " + (on ? "var(--vm-volt-deep)" : "var(--line-2)"), background: on ? "var(--vm-volt-deep)" : "transparent", display: "grid", placeItems: "center", flexShrink: 0 }}>
                    {on && <Icon name="check" size={12} stroke="#fff" />}
                  </span>
                  {o.label}
                </button>
              );
            })}
          </div>
        </>
      )}
    </div>
  );
}

function Widget({ label, value, data, color, sub }: { label: string; value: number; data: number[]; color: string; sub?: string }) {
  return (
    <div style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 16, padding: "16px 18px", boxShadow: "0 1px 2px rgba(10,10,12,.04)", display: "flex", flexDirection: "column", gap: 4 }}>
      <div style={{ display: "flex", alignItems: "flex-start", justifyContent: "space-between", gap: 10 }}>
        <div style={{ fontFamily: "var(--font-mono)", fontSize: 11, color: "var(--ink-on-paper-3)", textTransform: "uppercase", letterSpacing: ".04em" }}>{label}</div>
        <Sparkline data={data} color={color} />
      </div>
      <div style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 30, lineHeight: 1.05, color: "var(--ink-on-paper-1)" }}>{value.toLocaleString()}</div>
      {sub && <div style={{ fontFamily: "var(--font-body)", fontSize: 11.5, color: "var(--ink-on-paper-3)" }}>{sub}</div>}
    </div>
  );
}

export default function AdminUsers() {
  const { user } = useAuth();
  const [sp, setSp] = useSearchParams();

  // ---- URL is the single source of truth for filters + sort + page ----
  const from = sp.get("from") || ymd(29);
  const to = sp.get("to") || ymd(0);
  const name = sp.get("name") || "";
  const email = sp.get("email") || "";
  const card = useMemo(() => (sp.get("card") ? sp.get("card")!.split(",") : []), [sp]);
  const plan = useMemo(() => (sp.get("plan") ? sp.get("plan")!.split(",") : []), [sp]);
  const cancelled = useMemo(() => (sp.get("cancelled") ? sp.get("cancelled")!.split(",") : []), [sp]);
  const sort = (sp.get("sort") as AdminUserSort) || "created_at";
  const dir = (sp.get("dir") === "asc" ? "asc" : "desc") as "asc" | "desc";
  const page = Math.max(1, Number(sp.get("page") || "1"));

  const setParam = useCallback((updates: Record<string, string | null>, resetPage = true) => {
    const next = new URLSearchParams(sp);
    Object.entries(updates).forEach(([k, v]) => { if (v == null || v === "") next.delete(k); else next.set(k, v); });
    if (resetPage) next.delete("page");
    setSp(next, { replace: true });
  }, [sp, setSp]);

  const [showFilters, setShowFilters] = useState(false);
  const [planOptions, setPlanOptions] = useState<{ value: string; label: string }[]>([]);

  const [stats, setStats] = useState<AdminUserStats | null>(null);
  const [users, setUsers] = useState<AdminUser[]>([]);
  const [total, setTotal] = useState(0);
  const [lastPage, setLastPage] = useState(1);
  const [loading, setLoading] = useState(true);

  // Plan filter options (all plan names).
  useEffect(() => {
    if (!user?.is_admin) return;
    viewsMaxApi.getAdminUserSuggest("plan").then((res) => {
      if (res.success) setPlanOptions((res.data || []).map((p) => ({ value: p, label: p })));
    });
  }, [user?.is_admin]);

  const load = useCallback(async () => {
    setLoading(true);
    const [s, u] = await Promise.all([
      viewsMaxApi.getAdminUserStats({ from, to }),
      viewsMaxApi.getAdminUsers({ from, to, name: name || undefined, email: email || undefined, card, plan, cancelled, sort, dir, page }),
    ]);
    if (s.success && s.data) setStats(s.data);
    if (u.success && u.data) {
      setUsers(u.data.users || []);
      setTotal(u.data.total || 0);
      setLastPage(u.data.last_page || 1);
    }
    setLoading(false);
  }, [from, to, name, email, card, plan, cancelled, sort, dir, page]);

  useEffect(() => { if (user?.is_admin) load(); }, [load, user?.is_admin]);

  const series = useMemo(() => stats?.series ?? [], [stats]);
  const totals = stats?.totals ?? { signups: 0, added_card: 0, subscribed: 0 };

  const dateActive = sp.has("from") || sp.has("to");
  const activeCount = (name ? 1 : 0) + (email ? 1 : 0) + (card.length ? 1 : 0) + (plan.length ? 1 : 0) + (cancelled.length ? 1 : 0) + (dateActive ? 1 : 0);
  const clearAll = () => setParam({ name: null, email: null, card: null, plan: null, cancelled: null, from: null, to: null });

  const onSort = (key: AdminUserSort) => {
    if (sort === key) setParam({ sort: key, dir: dir === "asc" ? "desc" : "asc" });
    else setParam({ sort: key, dir: DESC_FIRST.includes(key) ? "desc" : "asc" });
  };

  const navigate = useNavigate();

  // Eye toggle: expanded row shows the user's connected accounts, fetched lazily
  // once per user and cached for the page's lifetime.
  const [expandedId, setExpandedId] = useState<number | null>(null);
  const [accountsById, setAccountsById] = useState<Record<number, AdminUserAccount[] | "loading">>({});
  const toggleAccounts = (u: AdminUser) => {
    const next = expandedId === u.id ? null : u.id;
    setExpandedId(next);
    if (next != null && accountsById[u.id] === undefined) {
      setAccountsById((prev) => ({ ...prev, [u.id]: "loading" }));
      viewsMaxApi.getAdminUserAccounts(u.id).then((res) => {
        setAccountsById((prev) => ({ ...prev, [u.id]: res.success ? res.data ?? [] : [] }));
        if (!res.success) toast.error(res.error || "Couldn't load accounts.");
      });
    }
  };

  const [deletingId, setDeletingId] = useState<number | null>(null);
  const handleDelete = async (u: AdminUser) => {
    if (!window.confirm(`Delete ${u.email}? They'll be soft-deleted (recoverable from the database), signed out, and hidden from this list.`)) return;
    setDeletingId(u.id);
    const res = await viewsMaxApi.deleteUser(u.id);
    setDeletingId(null);
    if (res.success) {
      toast.success(`Deleted ${u.email}.`);
      load();
    } else {
      toast.error(res.error || "Couldn't delete user.");
    }
  };

  if (!user?.is_admin) {
    return (
      <PostShell>
        <div style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 18, padding: 48, textAlign: "center", color: "var(--ink-on-paper-3)", fontFamily: "var(--font-body)", fontSize: 14 }}>
          <Icon name="alert-circle" size={22} stroke="var(--vm-red)" />
          <div style={{ marginTop: 10, fontWeight: 600 }}>Admins only.</div>
        </div>
      </PostShell>
    );
  }

  const th = (align: "left" | "center" = "left") => ({ textAlign: align, padding: "10px 16px", fontFamily: "var(--font-mono)", fontSize: 10.5, letterSpacing: ".06em", textTransform: "uppercase" as const, fontWeight: 600, whiteSpace: "nowrap" as const, cursor: "pointer", userSelect: "none" as const });
  const td = { padding: "12px 16px", fontFamily: "var(--font-body)", fontSize: 13.5, color: "var(--ink-on-paper-1)", whiteSpace: "nowrap" as const };

  return (
    <PostShell max={1560}>
      <SectionHead eyebrow="Admin" title="Users" />

      {/* Widgets (follow the date range) */}
      <div style={{ display: "grid", gridTemplateColumns: "repeat(3, 1fr)", gap: 12 }}>
        <Widget label="Signups" value={totals.signups} data={series.map((p) => p.signups)} color="var(--ink-on-paper-1)" sub="new accounts in range" />
        <Widget label="Added card" value={totals.added_card} data={series.map((p) => p.added_card)} color="var(--vm-volt-deep)" sub="entered card details" />
        <Widget label="Subscribed" value={totals.subscribed} data={series.map((p) => p.subscribed)} color="var(--up)" sub="active or trialing plan" />
      </div>

      {/* Filters toolbar */}
      <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
        <button onClick={() => setShowFilters((s) => !s)} style={{ display: "inline-flex", alignItems: "center", gap: 8, padding: "8px 14px", borderRadius: 10, border: "1px solid var(--line-1)", background: "var(--paper-0)", cursor: "pointer", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 13, color: "var(--ink-on-paper-1)" }}>
          <Icon name="filter" size={15} stroke="var(--ink-on-paper-2)" />
          Filters
          {activeCount > 0 && <span style={{ ...mono, fontWeight: 700, background: "var(--vm-red)", color: "#fff", borderRadius: 999, minWidth: 18, height: 18, display: "inline-flex", alignItems: "center", justifyContent: "center", padding: "0 5px" }}>{activeCount}</span>}
          <Icon name={showFilters ? "chevron-up" : "chevron-down"} size={14} stroke="var(--ink-on-paper-3)" />
        </button>
        {activeCount > 0 && (
          <button onClick={clearAll} style={{ padding: "8px 12px", borderRadius: 10, border: "1px solid var(--line-1)", background: "var(--paper-0)", cursor: "pointer", fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 12.5, color: "var(--ink-on-paper-2)" }}>Clear all</button>
        )}
      </div>

      {showFilters && (
        <div style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 16, padding: 18, boxShadow: "0 1px 2px rgba(10,10,12,.04)" }}>
          <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fit, minmax(190px, 1fr))", gap: 14 }}>
            <Typeahead label="Name" field="name" value={name} onChange={(v) => setParam({ name: v || null })} />
            <Typeahead label="Email" field="email" value={email} onChange={(v) => setParam({ email: v || null })} />
            <MultiSelect label="Card" options={CARD_OPTIONS} value={card} onChange={(v) => setParam({ card: v.length ? v.join(",") : null })} />
            <MultiSelect label="Plan" options={planOptions} value={plan} onChange={(v) => setParam({ plan: v.length ? v.join(",") : null })} />
            <MultiSelect label="Cancelled" options={CANCELLED_OPTIONS} value={cancelled} onChange={(v) => setParam({ cancelled: v.length ? v.join(",") : null })} />
            <div>
              <div style={{ ...mono, fontSize: 10.5, textTransform: "uppercase", letterSpacing: ".04em", color: "var(--ink-on-paper-3)", marginBottom: 5 }}>Signed up</div>
              <div style={{ display: "flex", alignItems: "center", gap: 6 }}>
                <input type="date" value={from} max={to} onChange={(e) => setParam({ from: e.target.value })} style={inputStyle} />
                <span style={{ color: "var(--ink-on-paper-3)", fontSize: 12 }}>to</span>
                <input type="date" value={to} min={from} onChange={(e) => setParam({ to: e.target.value })} style={inputStyle} />
              </div>
              <div style={{ display: "flex", gap: 6, marginTop: 8 }}>
                {PRESETS.map((p) => (
                  <button key={p.key} onClick={() => setParam({ from: ymd(p.days - 1), to: ymd(0) })} style={{ padding: "5px 10px", borderRadius: 999, border: "1px solid var(--line-1)", background: "var(--paper-1)", cursor: "pointer", fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 11.5, color: "var(--ink-on-paper-2)" }}>{p.label}</button>
                ))}
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Users table */}
      {loading ? (
        <AnalyticsLoading />
      ) : users.length === 0 ? (
        <div style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 18, padding: 48, textAlign: "center", color: "var(--ink-on-paper-3)", fontFamily: "var(--font-body)", fontSize: 14 }}>
          No users match {activeCount > 0 ? "these filters" : "this range"}.
        </div>
      ) : (
        <div style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 18, overflow: "hidden", boxShadow: "0 1px 2px rgba(10,10,12,.04)" }}>
          <div style={{ overflowX: "auto" }}>
            <table style={{ width: "100%", borderCollapse: "collapse", minWidth: 1180 }}>
              <thead>
                <tr>
                  {COLUMNS.map((c) => {
                    const active = sort === c.key;
                    return (
                      <th key={c.key} onClick={() => onSort(c.key)} style={{ ...th(c.align), color: active ? "var(--ink-on-paper-1)" : "var(--ink-on-paper-3)" }}>
                        {c.label}{active ? (dir === "asc" ? " ↑" : " ↓") : ""}
                      </th>
                    );
                  })}
                  <th style={{ ...th("center"), cursor: "default", color: "var(--ink-on-paper-3)" }}>Actions</th>
                </tr>
              </thead>
              <tbody>
                {users.map((u, i) => (
                  <Fragment key={u.id}>
                  <tr style={{ borderTop: i ? "1px solid var(--paper-2)" : "1px solid var(--line-1)", background: expandedId === u.id ? "var(--paper-1)" : undefined }}>
                    <td style={{ ...td, fontWeight: 600 }}>{u.name || "—"}</td>
                    <td style={{ ...td, fontFamily: "var(--font-mono)", fontSize: 12.5, color: "var(--ink-on-paper-2)" }}>{u.email}</td>
                    <td style={{ ...td, color: "var(--ink-on-paper-2)" }}>{fmtDate(u.created_at)}</td>
                    <td style={{ ...td, color: "var(--ink-on-paper-2)" }}>
                      {u.last_login_at ? (
                        <span style={{ display: "inline-flex", flexDirection: "column", gap: 2 }}>
                          <span>{fmtDate(u.last_login_at)}</span>
                          {u.last_login_country && (
                            <span title={u.last_login_country} style={{ fontFamily: "var(--font-mono)", fontSize: 11, color: "var(--ink-on-paper-3)" }}>
                              {flagEmoji(u.last_login_country_code)} {u.last_login_country}
                            </span>
                          )}
                        </span>
                      ) : (
                        <span style={{ color: "var(--ink-on-paper-3)" }}>Never</span>
                      )}
                    </td>
                    <td style={{ ...td, textAlign: "center" }}>
                      {u.has_card ? <Icon name="check" size={16} stroke="var(--vm-volt-deep)" /> : <span style={{ color: "var(--ink-on-paper-3)" }}>—</span>}
                    </td>
                    <td style={{ ...td, color: u.subscribed ? "var(--ink-on-paper-1)" : "var(--ink-on-paper-3)" }}>{u.plan || (u.subscribed ? "—" : "Free")}</td>
                    <td style={{ ...td, textAlign: "center" }}>
                      {u.posts_count > 0 ? (
                        <span style={{ display: "inline-flex", flexDirection: "column", gap: 2 }}>
                          <span style={{ ...mono, fontSize: 12.5 }}>{u.posts_count}</span>
                          {u.posts_posted_count !== u.posts_count && (
                            <span style={{ ...mono, fontSize: 10.5, color: "var(--ink-on-paper-3)" }}>{u.posts_posted_count} posted</span>
                          )}
                        </span>
                      ) : (
                        <span style={{ ...mono, fontSize: 12.5, color: "var(--ink-on-paper-3)" }}>0</span>
                      )}
                    </td>
                    <td style={{ ...td, textAlign: "center" }}>
                      <span style={{ ...mono, fontSize: 12.5, color: u.accounts_count ? "var(--ink-on-paper-1)" : "var(--ink-on-paper-3)" }}>{u.accounts_count}</span>
                    </td>
                    <td style={{ ...td, textAlign: "center" }}>
                      <span style={{ ...mono, fontSize: 12.5, color: u.offers_count ? "var(--ink-on-paper-1)" : "var(--ink-on-paper-3)" }}>{u.offers_count}</span>
                    </td>
                    <td style={{ ...td, color: "var(--ink-on-paper-2)" }}>
                      {u.first_action_at ? (
                        <span style={{ display: "inline-flex", flexDirection: "column", gap: 2 }}>
                          <span title="Signup → first post or offer">{durationBetween(u.created_at, u.first_action_at) ?? "—"}</span>
                          <span style={{ fontFamily: "var(--font-mono)", fontSize: 11, color: "var(--ink-on-paper-3)" }}>{fmtDate(u.first_action_at)}</span>
                        </span>
                      ) : (
                        <span style={{ color: "var(--ink-on-paper-3)" }}>—</span>
                      )}
                    </td>
                    <td style={{ ...td, color: "var(--ink-on-paper-2)" }}>
                      {u.subscription_cancelled_at ? (
                        <span style={{ display: "inline-flex", flexDirection: "column", gap: 2 }}>
                          <span title="Signup → cancellation" style={{ color: "var(--vm-red)" }}>
                            {durationBetween(u.created_at, u.subscription_cancelled_at) ?? "—"} after signup
                          </span>
                          <span style={{ fontFamily: "var(--font-mono)", fontSize: 11, color: "var(--ink-on-paper-3)" }}>{fmtDate(u.subscription_cancelled_at)}</span>
                        </span>
                      ) : (
                        <span style={{ color: "var(--ink-on-paper-3)" }}>—</span>
                      )}
                    </td>
                    <td style={{ ...td, textAlign: "center" }}>
                      <span style={{ display: "inline-flex", gap: 6, alignItems: "center" }}>
                        <button
                          onClick={() => toggleAccounts(u)}
                          title={expandedId === u.id ? "Hide connected accounts" : "Show connected accounts"}
                          style={{ display: "grid", placeItems: "center", width: 28, height: 28, borderRadius: 8, border: "1px solid " + (expandedId === u.id ? "var(--vm-volt-deep)" : "var(--line-1)"), background: expandedId === u.id ? "var(--vm-volt-tint-l)" : "var(--paper-0)", cursor: "pointer" }}
                        >
                          <Icon name="eye" size={14} stroke={expandedId === u.id ? "var(--vm-volt-deep)" : "var(--ink-on-paper-2)"} />
                        </button>
                        <button
                          onClick={() => navigate(`/dashboard/admin/users/${u.id}/edit`)}
                          title="Edit user"
                          style={{ display: "grid", placeItems: "center", width: 28, height: 28, borderRadius: 8, border: "1px solid var(--line-1)", background: "var(--paper-0)", cursor: "pointer" }}
                        >
                          <Icon name="edit" size={14} stroke="var(--ink-on-paper-2)" />
                        </button>
                        {u.id !== user?.id && (
                          <button
                            onClick={() => handleDelete(u)}
                            disabled={deletingId === u.id}
                            style={{ padding: "5px 10px", borderRadius: 8, border: "1px solid var(--line-1)", background: "var(--paper-0)", cursor: deletingId === u.id ? "default" : "pointer", fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 12, color: "var(--vm-red)", opacity: deletingId === u.id ? 0.5 : 1 }}
                          >
                            {deletingId === u.id ? "Deleting…" : "Delete"}
                          </button>
                        )}
                      </span>
                    </td>
                  </tr>
                  {expandedId === u.id && (
                    <tr style={{ background: "var(--paper-1)" }}>
                      <td colSpan={COLUMNS.length + 1} style={{ padding: "4px 16px 14px" }}>
                        {accountsById[u.id] === "loading" || accountsById[u.id] === undefined ? (
                          <span style={{ ...mono, color: "var(--ink-on-paper-3)" }}>Loading accounts…</span>
                        ) : (accountsById[u.id] as AdminUserAccount[]).length === 0 ? (
                          <span style={{ ...mono, color: "var(--ink-on-paper-3)" }}>No connected accounts.</span>
                        ) : (
                          <div style={{ display: "flex", flexWrap: "wrap", gap: 8 }}>
                            {(accountsById[u.id] as AdminUserAccount[]).map((a) => {
                              const chip = (
                                <>
                                  {a.avatar_url
                                    ? <img src={a.avatar_url} alt="" style={{ width: 20, height: 20, borderRadius: "50%", objectFit: "cover" }} />
                                    : <span style={{ width: 20, height: 20, borderRadius: "50%", background: "var(--paper-2)", display: "grid", placeItems: "center", fontSize: 10, fontFamily: "var(--font-mono)", color: "var(--ink-on-paper-3)" }}>{(a.platform || "?")[0].toUpperCase()}</span>}
                                  <span style={{ ...mono, fontSize: 10.5, textTransform: "uppercase", letterSpacing: ".04em", color: "var(--ink-on-paper-3)" }}>{a.platform}</span>
                                  <span style={{ fontFamily: "var(--font-body)", fontSize: 12.5, fontWeight: 600, color: "var(--ink-on-paper-1)" }}>{a.name || a.username || "—"}</span>
                                  {a.username && <span style={{ ...mono, fontSize: 11, color: "var(--ink-on-paper-3)" }}>@{a.username}</span>}
                                  {a.status && a.status !== "active" && a.status !== "connected" && (
                                    <span style={{ ...mono, fontSize: 10, color: "var(--vm-red)", textTransform: "uppercase" }}>{a.status}</span>
                                  )}
                                  {a.url && <ExternalLink size={12} color="var(--ink-on-paper-3)" style={{ display: "block" }} />}
                                </>
                              );
                              const chipStyle = { display: "inline-flex", alignItems: "center", gap: 8, background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 10, padding: "6px 10px", textDecoration: "none" as const };
                              const title = `Connected ${fmtDate(a.connected_at)}${a.id.startsWith("legacy-") ? " (legacy connection)" : ""}`;
                              return a.url ? (
                                <a key={a.id} href={a.url} target="_blank" rel="noopener noreferrer" title={`${title} — open on ${a.platform}`}
                                  style={chipStyle}
                                  onMouseEnter={(e) => (e.currentTarget.style.borderColor = "var(--vm-red)")}
                                  onMouseLeave={(e) => (e.currentTarget.style.borderColor = "var(--line-1)")}>
                                  {chip}
                                </a>
                              ) : (
                                <span key={a.id} title={title} style={chipStyle}>{chip}</span>
                              );
                            })}
                          </div>
                        )}
                      </td>
                    </tr>
                  )}
                  </Fragment>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* Pagination */}
      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", gap: 12 }}>
        <div style={{ ...mono, color: "var(--ink-on-paper-3)" }}>{total.toLocaleString()} user{total === 1 ? "" : "s"}</div>
        {lastPage > 1 && (
          <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
            <button disabled={page <= 1} onClick={() => setParam({ page: String(page - 1) }, false)} style={{ padding: "6px 12px", borderRadius: 8, border: "1px solid var(--line-1)", background: "var(--paper-0)", cursor: page <= 1 ? "not-allowed" : "pointer", opacity: page <= 1 ? 0.5 : 1, fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 12.5, color: "var(--ink-on-paper-2)" }}>Prev</button>
            <span style={{ ...mono, color: "var(--ink-on-paper-2)" }}>{page} / {lastPage}</span>
            <button disabled={page >= lastPage} onClick={() => setParam({ page: String(page + 1) }, false)} style={{ padding: "6px 12px", borderRadius: 8, border: "1px solid var(--line-1)", background: "var(--paper-0)", cursor: page >= lastPage ? "not-allowed" : "pointer", opacity: page >= lastPage ? 0.5 : 1, fontFamily: "var(--font-body)", fontWeight: 600, fontSize: 12.5, color: "var(--ink-on-paper-2)" }}>Next</button>
          </div>
        )}
      </div>
    </PostShell>
  );
}
