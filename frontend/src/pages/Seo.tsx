// SEO engine — per-offer growth automation for USERS. Pick an offer, tell us
// your competitors and your WordPress blog, and the daily pipeline mines
// high-intent keywords, drafts articles, publishes on cadence, and builds a
// backlink outreach list. All config lives here (not .env).
import { useCallback, useEffect, useMemo, useState, type CSSProperties } from "react";
import { Eye, Pencil, RotateCcw } from "lucide-react";
import { toast } from "sonner";
import { Btn, SectionHead } from "@/components/analytics/primitives";
import { AnalyticsLoading } from "@/components/analytics/useAnalytics";
import { PostShell } from "@/components/post/PostList";
import { fmtNum } from "@/lib/analytics-model";
import { viewsMaxApi, type SeoArticleRow, type SeoKeywordRow, type SeoProfileRow, type SeoProspectRow, type TrackingEvent } from "@/lib/api-service";
import { AdminMetricTable, adminSearchStyle, subText, type AdminColumn } from "./admin/AdminMetricTable";

type Tab = "keywords" | "articles" | "prospects";

const chip = (bg: string, fg: string): CSSProperties => ({
  display: "inline-flex", alignItems: "center", background: bg, color: fg,
  borderRadius: 999, padding: "3px 9px", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 11.5,
});
const STATUS_CHIP: Record<string, { bg: string; fg: string }> = {
  discovered: { bg: "var(--paper-2)", fg: "var(--ink-on-paper-2)" },
  drafted: { bg: "rgba(255,176,32,.16)", fg: "var(--warn)" },
  review: { bg: "rgba(255,176,32,.16)", fg: "var(--warn)" },
  queued: { bg: "rgba(22,224,196,.14)", fg: "var(--vm-volt-deep)" },
  published: { bg: "rgba(15,182,126,.12)", fg: "var(--up)" },
  failed: { bg: "var(--vm-red-tint-l)", fg: "var(--vm-red)" },
  skipped: { bg: "var(--paper-2)", fg: "var(--ink-on-paper-3)" },
  new: { bg: "var(--paper-2)", fg: "var(--ink-on-paper-2)" },
  contacted: { bg: "rgba(255,176,32,.16)", fg: "var(--warn)" },
  won: { bg: "rgba(15,182,126,.12)", fg: "var(--up)" },
  rejected: { bg: "var(--vm-red-tint-l)", fg: "var(--vm-red)" },
};
// Plain-words meaning of every status — surfaced as hover text on the chips
// and in the legend, so "discovered vs drafted vs skipped" is never a mystery.
const STATUS_HELP: Record<string, string> = {
  discovered: "Found from your competitors — waiting its turn to be drafted (highest intent first).",
  drafted: "An article has been written for this keyword.",
  published: "Its article is live on your blog.",
  skipped: "You told the engine to ignore this keyword — it will never be drafted. Restore to undo.",
  review: "Draft is waiting for your approval before it can publish.",
  queued: "Approved — publishes automatically in the next run (within your weekly cap).",
  failed: "Publishing failed — see the error under the title, fix settings, then hit retry (↺).",
  new: "Fresh backlink opportunity — nobody has reached out yet.",
  contacted: "You've reached out to this site.",
  won: "They linked to you. 🎉",
  rejected: "Not pursuing this one.",
};

const StatusChip = ({ status }: { status: string }) => {
  const c = STATUS_CHIP[status] ?? STATUS_CHIP.discovered;
  return <span style={{ ...chip(c.bg, c.fg), cursor: "help" }} title={STATUS_HELP[status]}>{status}</span>;
};

const CATEGORY_TONE: Record<string, { bg: string; fg: string }> = {
  "Guide: Explainer": { bg: "rgba(22,224,196,.14)", fg: "var(--vm-volt-deep)" },
  "Guide: How-to": { bg: "rgba(22,224,196,.14)", fg: "var(--vm-volt-deep)" },
  "List: Round-up": { bg: "rgba(255,176,32,.16)", fg: "var(--warn)" },
  "List: Resources": { bg: "rgba(255,176,32,.16)", fg: "var(--warn)" },
  "List: Examples": { bg: "rgba(255,176,32,.16)", fg: "var(--warn)" },
};
const CATEGORIES = Object.keys(CATEGORY_TONE);

const smallBtn: CSSProperties = {
  display: "inline-flex", alignItems: "center", gap: 5, height: 26, padding: "0 11px", borderRadius: 999,
  border: "1px solid var(--line-1)", background: "var(--paper-0)", cursor: "pointer",
  fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 11.5, color: "var(--ink-on-paper-2)",
};
const iconBtn: CSSProperties = { ...smallBtn, width: 28, padding: 0, justifyContent: "center", flexShrink: 0 };
const field: CSSProperties = {
  fontFamily: "var(--font-body)", fontSize: 13.5, color: "var(--ink-on-paper-1)",
  background: "var(--paper-0)", border: "1px solid var(--line-2)", borderRadius: 10, padding: "9px 12px", outline: "none", width: "100%",
};
const label: CSSProperties = { fontSize: 11.5, fontWeight: 700, color: "var(--ink-on-paper-3)", marginBottom: 5, display: "block" };

function Toggle({ checked, onChange, text }: { checked: boolean; onChange: (v: boolean) => void; text: string }) {
  return (
    <label style={{ display: "inline-flex", alignItems: "center", gap: 8, cursor: "pointer" }}>
      <span onClick={() => onChange(!checked)} style={{ width: 36, height: 20, borderRadius: 999, background: checked ? "var(--ink-on-paper-1)" : "var(--line-2)", position: "relative", transition: "background var(--dur)", flexShrink: 0 }}>
        <span style={{ position: "absolute", top: 2, left: checked ? 18 : 2, width: 16, height: 16, borderRadius: "50%", background: "#fff", transition: "left var(--dur)", boxShadow: "0 1px 2px rgba(0,0,0,.3)" }} />
      </span>
      <span style={{ fontSize: 12.5, color: "var(--ink-on-paper-2)", fontWeight: 600 }}>{text}</span>
    </label>
  );
}

export default function Seo() {
  const [offers, setOffers] = useState<TrackingEvent[]>([]);
  const [profiles, setProfiles] = useState<SeoProfileRow[]>([]);
  const [offerId, setOfferId] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);

  // Settings form state (seeded from the selected offer's profile).
  const [competitors, setCompetitors] = useState("");
  const [wpUrl, setWpUrl] = useState("");
  const [wpUser, setWpUser] = useState("");
  const [wpPass, setWpPass] = useState("");
  const [hasWpPass, setHasWpPass] = useState(false);
  const [perWeek, setPerWeek] = useState(3);
  const [autoPublish, setAutoPublish] = useState(true);
  const [enabled, setEnabled] = useState(false);
  const [saving, setSaving] = useState(false);

  const [tab, setTab] = useState<Tab>("keywords");
  const [q, setQ] = useState("");
  const [editing, setEditing] = useState<SeoArticleRow | null>(null);
  const [keywords, setKeywords] = useState<SeoKeywordRow[]>([]);
  const [articles, setArticles] = useState<SeoArticleRow[]>([]);
  const [prospects, setProspects] = useState<SeoProspectRow[]>([]);
  const [selected, setSelected] = useState<Set<number>>(new Set()); // prospect ids for bulk actions
  const [dataLoading, setDataLoading] = useState(false);

  const profile = useMemo(() => profiles.find((p) => p.tracking_event_id === offerId) ?? null, [profiles, offerId]);

  const loadBase = useCallback(async () => {
    setLoading(true);
    const [ev, pr] = await Promise.all([viewsMaxApi.getTrackingEvents(), viewsMaxApi.getSeoProfiles()]);
    const offerList = ev.success && ev.data ? ev.data : [];
    setOffers(offerList);
    if (pr.success && pr.data) setProfiles(pr.data);
    setOfferId((cur) => cur ?? offerList[0]?.id ?? null);
    setLoading(false);
  }, []);

  useEffect(() => { loadBase(); }, [loadBase]);

  // Seed the settings form whenever the selected offer's profile changes.
  useEffect(() => {
    setCompetitors(profile?.competitors.join(", ") ?? "");
    setWpUrl(profile?.wp_url ?? "");
    setWpUser(profile?.wp_username ?? "");
    setWpPass("");
    setHasWpPass(!!profile?.has_wp_password);
    setPerWeek(profile?.articles_per_week ?? 3);
    setAutoPublish(profile?.auto_publish ?? true);
    setEnabled(profile?.enabled ?? false);
    setSelected(new Set());
  }, [profile]);

  const loadData = useCallback(async () => {
    if (!profile) { setKeywords([]); setArticles([]); setProspects([]); return; }
    setDataLoading(true);
    const [kw, ar, pr] = await Promise.all([
      viewsMaxApi.getSeoKeywords(profile.id),
      viewsMaxApi.getSeoArticles(profile.id),
      viewsMaxApi.getSeoProspects(profile.id),
    ]);
    if (kw.success && kw.data) setKeywords(kw.data); else if (!kw.success) toast.error(kw.error || "Couldn't load SEO data.");
    if (ar.success && ar.data) setArticles(ar.data);
    if (pr.success && pr.data) setProspects(pr.data);
    setDataLoading(false);
  }, [profile]);

  useEffect(() => { loadData(); }, [loadData]);

  const save = async () => {
    if (!offerId) return;
    const list = competitors.split(",").map((s) => s.trim()).filter(Boolean);
    if (list.length === 0) { toast.error("Add at least one competitor domain."); return; }
    setSaving(true);
    const res = await viewsMaxApi.saveSeoProfile(offerId, {
      competitors: list,
      wp_url: wpUrl || null,
      wp_username: wpUser || null,
      ...(wpPass ? { wp_app_password: wpPass } : {}),
      articles_per_week: perWeek,
      auto_publish: autoPublish,
      enabled,
    });
    setSaving(false);
    if (res.success && res.data) {
      setProfiles((all) => {
        const next = all.filter((p) => p.tracking_event_id !== offerId);
        return [...next, res.data!];
      });
      toast.success("SEO settings saved.");
    } else {
      toast.error(res.error || "Couldn't save settings.");
    }
  };

  const act = async (p: Promise<{ success: boolean; error?: string }>, okMsg: string) => {
    const res = await p;
    if (res.success) { toast.success(okMsg); loadData(); }
    else toast.error(res.error || "Update failed.");
  };

  const match = (s: string | null | undefined) => !q || (s ?? "").toLowerCase().includes(q.toLowerCase());

  // Shared by the table AND the select-all header so "all" always means "all
  // rows currently visible under the search filter", not the whole list.
  const filteredProspects = useMemo(
    () => prospects.filter((r) => match(r.domain) || match(r.competitor_domain)),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [prospects, q],
  );

  const toggleSelected = (id: number) => setSelected((cur) => {
    const next = new Set(cur);
    if (next.has(id)) next.delete(id); else next.add(id);
    return next;
  });

  const bulkSetStatus = (status: string) => {
    const ids = [...selected];
    setSelected(new Set());
    act(viewsMaxApi.bulkUpdateSeoProspects(ids, status), `${ids.length} prospect${ids.length === 1 ? "" : "s"} marked ${status}.`);
  };

  const keywordCols: AdminColumn<SeoKeywordRow>[] = [
    { key: "keyword", head: "Keyword", sort: (r) => r.keyword, render: (r) => (
      <div style={{ minWidth: 0 }}>
        <div style={{ fontWeight: 700 }}>{r.keyword}</div>
        {r.competitor_domain && <div style={subText}>via {r.competitor_domain}</div>}
      </div>
    ) },
    { key: "search_volume", head: "Volume", align: "right", sort: (r) => r.search_volume, render: (r) => fmtNum(r.search_volume) },
    { key: "difficulty", head: "KD", align: "right", sort: (r) => r.difficulty, render: (r) => r.difficulty },
    { key: "cpc", head: "CPC", align: "right", sort: (r) => Number(r.cpc), render: (r) => `$${Number(r.cpc).toFixed(2)}` },
    { key: "intent_score", head: "Intent", align: "right", sort: (r) => r.intent_score, render: (r) => <span style={{ fontWeight: 700, color: "var(--vm-volt-deep)" }}>{r.intent_score}</span> },
    { key: "status", head: "Status", sort: (r) => r.status, render: (r) => <StatusChip status={r.status} /> },
    { key: "actions", head: "", render: (r) => (
      r.status === "discovered" ? (
        <button style={smallBtn} title="Don't write an article for this keyword — the engine will ignore it (you can restore it later)." onClick={() => act(viewsMaxApi.updateSeoKeyword(r.id, "skipped"), "Keyword skipped — no article will be written for it.")}>Skip</button>
      ) : r.status === "skipped" ? (
        <button style={smallBtn} title="Put this keyword back in the drafting queue." onClick={() => act(viewsMaxApi.updateSeoKeyword(r.id, "discovered"), "Keyword restored.")}>Restore</button>
      ) : null
    ) },
  ];

  const articleCols: AdminColumn<SeoArticleRow>[] = [
    { key: "title", head: "Article", sort: (r) => r.title.toLowerCase(), render: (r) => (
      <div style={{ display: "flex", gap: 10, minWidth: 0, maxWidth: 460 }}>
        {r.featured_image_url && (
          <img src={r.featured_image_url} alt="" onClick={() => setEditing(r)}
            style={{ width: 56, height: 42, objectFit: "cover", borderRadius: 8, border: "1px solid var(--line-1)", flexShrink: 0, cursor: "pointer", marginTop: 2 }} />
        )}
        <div style={{ minWidth: 0 }}>
          {r.category && (() => { const t = CATEGORY_TONE[r.category] ?? { bg: "var(--paper-2)", fg: "var(--ink-on-paper-2)" }; return (
            <span style={{ ...chip(t.bg, t.fg), fontSize: 10.5, marginBottom: 3 }}>{r.category}</span>
          ); })()}
          <div style={{ fontWeight: 700 }}>
            <span onClick={() => setEditing(r)} title="Click to preview & edit" style={{ cursor: "pointer" }}
              onMouseEnter={(e) => { e.currentTarget.style.textDecoration = "underline"; }}
              onMouseLeave={(e) => { e.currentTarget.style.textDecoration = "none"; }}>{r.title}</span>
            {r.published_url && (
              <a href={r.published_url} target="_blank" rel="noopener noreferrer" title="View the live post"
                style={{ color: "var(--vm-volt-deep)", textDecoration: "none", marginLeft: 6 }}>↗</a>
            )}
          </div>
          <div style={subText}>{r.keyword?.keyword ?? r.slug}</div>
          {r.error && <div style={{ ...subText, color: "var(--vm-red)", maxWidth: 420, whiteSpace: "normal" }}>{r.error}</div>}
        </div>
      </div>
    ) },
    { key: "status", head: "Status", sort: (r) => r.status, render: (r) => <StatusChip status={r.status} /> },
    { key: "published_at", head: "Published", sort: (r) => r.published_at ?? "", render: (r) => (
      <span style={{ fontFamily: "var(--font-mono)", fontSize: 12 }}>{r.published_at ? new Date(r.published_at).toLocaleDateString() : "—"}</span>
    ) },
    { key: "actions", head: "", render: (r) => (
      <div style={{ display: "flex", gap: 6, flexWrap: "wrap" }}>
        {r.status === "review" || r.status === "queued" ? (
          <button style={iconBtn} title="Edit — change the title, category, meta description, image or body before it publishes." onClick={() => setEditing(r)}><Pencil size={13} /></button>
        ) : (
          <button style={iconBtn} title="Preview — see how the article looks." onClick={() => setEditing(r)}><Eye size={14} /></button>
        )}
        {r.status === "failed" && (
          <button style={iconBtn} title="Retry — re-queues the article; it publishes with the next run." onClick={() => act(viewsMaxApi.updateSeoArticle(r.id, { status: "queued" }), "Re-queued — publishes with the next run.")}><RotateCcw size={13} /></button>
        )}
        {r.status === "review" ? (
          <button style={{ ...smallBtn, background: "var(--vm-red)", border: "none", color: "#fff" }} title={STATUS_HELP.queued} onClick={() => act(viewsMaxApi.updateSeoArticle(r.id, { status: "queued" }), "Approved — publishes with the next run.")}>Approve</button>
        ) : r.status === "queued" ? (
          <button style={smallBtn} title={STATUS_HELP.review} onClick={() => act(viewsMaxApi.updateSeoArticle(r.id, { status: "review" }), "Held for review.")}>Hold</button>
        ) : null}
      </div>
    ) },
  ];

  const prospectCols: AdminColumn<SeoProspectRow>[] = [
    { key: "select", head: (
      <input
        type="checkbox"
        aria-label="Select all visible prospects"
        checked={filteredProspects.length > 0 && filteredProspects.every((r) => selected.has(r.id))}
        onChange={(e) => setSelected(e.target.checked ? new Set(filteredProspects.map((r) => r.id)) : new Set())}
        style={{ cursor: "pointer" }}
      />
    ), render: (r) => (
      <input type="checkbox" aria-label={`Select ${r.domain}`} checked={selected.has(r.id)} onChange={() => toggleSelected(r.id)} style={{ cursor: "pointer" }} />
    ) },
    { key: "domain", head: "Prospect", sort: (r) => r.domain, render: (r) => (
      <div style={{ minWidth: 0, maxWidth: 360 }}>
        <a href={r.url} target="_blank" rel="noopener noreferrer" style={{ fontWeight: 700, color: "var(--vm-volt-deep)", textDecoration: "none" }}>{r.domain} ↗</a>
        <div style={subText}>links to {r.competitor_domain ?? "?"}{r.anchor ? ` · “${r.anchor}”` : ""}</div>
      </div>
    ) },
    { key: "domain_rank", head: (
      <span title="Authority of the prospect's domain (DataForSEO domain rank, 0–1000, logarithmic — like Ahrefs DR ×10). Higher = a link from this site moves your rankings more." style={{ cursor: "help", borderBottom: "1px dotted currentColor" }}>Rank</span>
    ), align: "right", sort: (r) => r.domain_rank, render: (r) => fmtNum(r.domain_rank) },
    { key: "status", head: "Status", sort: (r) => r.status, render: (r) => (
      <select value={r.status} onChange={(e) => act(viewsMaxApi.updateSeoProspect(r.id, { status: e.target.value }), "Prospect updated.")}
        style={{ border: "1px solid var(--line-1)", borderRadius: 8, padding: "4px 8px", fontFamily: "var(--font-body)", fontSize: 12, background: "var(--paper-0)", color: "var(--ink-on-paper-1)" }}>
        {["new", "contacted", "won", "rejected"].map((s) => <option key={s} value={s}>{s}</option>)}
      </select>
    ) },
  ];

  const tabBtn = (id: Tab, text: string, count: number) => (
    <button key={id} onClick={() => setTab(id)} style={{
      display: "inline-flex", alignItems: "center", gap: 7, padding: "7px 14px", borderRadius: 999, cursor: "pointer",
      border: "1px solid " + (tab === id ? "var(--ink-on-paper-1)" : "var(--line-1)"),
      background: tab === id ? "var(--ink-on-paper-1)" : "var(--paper-0)",
      color: tab === id ? "#fff" : "var(--ink-on-paper-2)", fontFamily: "var(--font-body)", fontWeight: 700, fontSize: 13,
    }}>
      {text}<span style={{ fontFamily: "var(--font-mono)", fontSize: 11, opacity: 0.75 }}>{count}</span>
    </button>
  );

  if (loading) return <PostShell max={1280}><AnalyticsLoading /></PostShell>;

  if (offers.length === 0) {
    return (
      <PostShell max={1280}>
        <SectionHead eyebrow="GROWTH" title="SEO engine." />
        <div style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 18, padding: 48, textAlign: "center", color: "var(--ink-on-paper-3)", fontFamily: "var(--font-body)", fontSize: 14 }}>
          Create an offer first (Monetization → Offers) — SEO campaigns promote an offer.
        </div>
      </PostShell>
    );
  }

  return (
    <PostShell max={1280}>
      <SectionHead eyebrow="GROWTH" title="SEO engine."
        right={
          <select value={offerId ?? ""} onChange={(e) => setOfferId(Number(e.target.value))}
            style={{ ...field, width: 260 }}>
            {offers.map((o) => <option key={o.id} value={o.id}>{o.name || o.offer_url}</option>)}
          </select>
        } />

      {/* Settings — competitors + the user's own WordPress blog. */}
      <div style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 18, padding: 20, boxShadow: "0 1px 2px rgba(10,10,12,.04)", display: "flex", flexDirection: "column", gap: 14 }}>
        <div style={{ display: "grid", gridTemplateColumns: "2fr 1fr", gap: 14 }}>
          <div>
            <span style={label}>Competitor domains (comma-separated)</span>
            <input style={field} placeholder="hootsuite.com, buffer.com" value={competitors} onChange={(e) => setCompetitors(e.target.value)} />
          </div>
          <div>
            <span style={label}>Articles per week</span>
            <input style={field} type="number" min={0} max={21} value={perWeek} onChange={(e) => setPerWeek(Math.max(0, Math.min(21, Number(e.target.value) || 0)))} />
          </div>
        </div>
        <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 14 }}>
          <div>
            <span style={label}>WordPress site URL</span>
            <input style={field} placeholder="https://blog.yoursite.com" value={wpUrl} onChange={(e) => setWpUrl(e.target.value)} />
          </div>
          <div>
            <span style={label}>WordPress username</span>
            <input style={field} placeholder="editor" value={wpUser} onChange={(e) => setWpUser(e.target.value)} />
          </div>
          <div>
            <span style={label}>Application password {hasWpPass && <em style={{ fontWeight: 400 }}>(saved — leave blank to keep)</em>}</span>
            <input style={field} type="password" placeholder={hasWpPass ? "••••••••" : "xxxx xxxx xxxx xxxx"} value={wpPass} onChange={(e) => setWpPass(e.target.value)} />
          </div>
        </div>
        <div style={{ display: "flex", alignItems: "center", gap: 22, flexWrap: "wrap" }}>
          <Toggle checked={enabled} onChange={setEnabled} text="Engine on for this offer" />
          <Toggle checked={autoPublish} onChange={setAutoPublish} text="Auto-publish (off = review every draft)" />
          <div style={{ marginLeft: "auto" }}>
            <Btn onClick={saving ? undefined : save}>{saving ? "Saving…" : profile ? "Save settings" : "Start SEO for this offer"}</Btn>
          </div>
        </div>
        <div style={{ fontFamily: "var(--font-body)", fontSize: 11.5, color: "var(--ink-on-paper-3)", lineHeight: 1.5 }}>
          Create the application password in your WordPress admin: Users → Profile → Application Passwords. The pipeline
          runs daily: it mines high-intent keywords from your competitors, drafts articles that link to your offer, publishes
          up to your weekly cap, and collects backlink outreach targets.
        </div>
      </div>

      {profile && (
        <>
          <div style={{ display: "flex", alignItems: "center", gap: 10, flexWrap: "wrap" }}>
            {tabBtn("keywords", "Keywords", keywords.length)}
            {tabBtn("articles", "Articles", articles.length)}
            {tabBtn("prospects", "Backlink prospects", prospects.length)}
            <input placeholder="Search…" value={q} onChange={(e) => setQ(e.target.value)} style={{ ...adminSearchStyle, marginLeft: "auto" }} />
          </div>
          {/* Bulk bar — appears once any prospect row is checked. */}
          {tab === "prospects" && selected.size > 0 && (
            <div style={{ display: "flex", alignItems: "center", gap: 12, background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: 12, padding: "10px 16px", fontFamily: "var(--font-body)", fontSize: 13, color: "var(--ink-on-paper-1)" }}>
              <strong>{selected.size} selected</strong>
              <select defaultValue="" onChange={(e) => { if (e.target.value) bulkSetStatus(e.target.value); e.target.value = ""; }}
                style={{ border: "1px solid var(--line-1)", borderRadius: 8, padding: "5px 10px", fontFamily: "var(--font-body)", fontSize: 12.5, background: "var(--paper-0)", color: "var(--ink-on-paper-1)", cursor: "pointer" }}>
                <option value="" disabled>Set status…</option>
                {["new", "contacted", "won", "rejected"].map((s) => <option key={s} value={s}>{s}</option>)}
              </select>
              <button style={{ ...smallBtn, marginLeft: "auto" }} onClick={() => setSelected(new Set())}>Clear selection</button>
            </div>
          )}
          {dataLoading ? (
            <AnalyticsLoading />
          ) : tab === "keywords" ? (
            <AdminMetricTable rows={keywords.filter((r) => match(r.keyword) || match(r.competitor_domain))} columns={keywordCols} minWidth={860} initialSort="intent_score" />
          ) : tab === "articles" ? (
            <AdminMetricTable rows={articles.filter((r) => match(r.title) || match(r.keyword?.keyword))} columns={articleCols} minWidth={760} initialSort="published_at" />
          ) : (
            <AdminMetricTable rows={filteredProspects} columns={prospectCols} minWidth={760} initialSort="domain_rank" />
          )}

          {/* Status legend for the active tab — what each state means. */}
          <div style={{ display: "flex", flexWrap: "wrap", gap: "6px 16px", fontFamily: "var(--font-body)", fontSize: 11.5, color: "var(--ink-on-paper-3)", lineHeight: 1.5 }}>
            {(tab === "keywords" ? ["discovered", "drafted", "published", "skipped"]
              : tab === "articles" ? ["review", "queued", "published", "failed"]
              : ["new", "contacted", "won", "rejected"]).map((s) => (
              <span key={s} style={{ display: "inline-flex", alignItems: "center", gap: 6 }}>
                <StatusChip status={s} /> {STATUS_HELP[s]}
              </span>
            ))}
          </div>
        </>
      )}

      {editing && (
        <EditArticleModal
          article={editing}
          onClose={() => setEditing(null)}
          onSaved={() => { setEditing(null); loadData(); }}
        />
      )}
    </PostShell>
  );
}

/* ---------------- Article preview + edit modal ----------------
   Every article opens here. Preview renders the post as it will look on the
   blog (sandboxed iframe — no scripts run). Editing is only offered while the
   article is pre-publish (review/queued); published/failed articles are
   read-only since the live copy lives on WordPress. */
const escapeHtml = (s: string) => s.replace(/[&<>"]/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]!));

function EditArticleModal({ article, onClose, onSaved }: { article: SeoArticleRow; onClose: () => void; onSaved: () => void }) {
  const editable = article.status === "review" || article.status === "queued";
  const [mode, setMode] = useState<"preview" | "edit">("preview");
  const [title, setTitle] = useState(article.title);
  const [category, setCategory] = useState<string>(article.category ?? "Guide: Explainer");
  const [meta, setMeta] = useState(article.meta_description ?? "");
  const [html, setHtml] = useState(article.html ?? "");
  const [imgUrl, setImgUrl] = useState(article.featured_image_url ?? "");
  const [saving, setSaving] = useState(false);

  // Approximates the blog's rendering; edits show up live when you flip back
  // to Preview.
  const previewDoc = useMemo(() => `<!doctype html><html><head><meta charset="utf-8"><style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; max-width: 720px; margin: 0 auto; padding: 28px 20px 56px; color: #1c1917; line-height: 1.65; font-size: 16px; }
    h1 { font-size: 30px; line-height: 1.15; letter-spacing: -.02em; margin: 20px 0 8px; }
    h2 { font-size: 22px; margin: 32px 0 10px; } h3 { font-size: 17px; margin: 24px 0 8px; }
    p { margin: 0 0 14px; } ul { margin: 0 0 14px; padding-left: 22px; } li { margin-bottom: 6px; }
    img { max-width: 100%; height: auto; border-radius: 10px; } figure { margin: 24px 0; }
    table { border-collapse: collapse; width: 100%; margin: 0 0 16px; font-size: 14.5px; }
    th, td { border: 1px solid #e7e5e4; padding: 8px 10px; text-align: left; } th { background: #fafaf9; }
    a { color: #0d9488; } .vm-meta { color: #78716c; font-size: 14px; margin: 0 0 18px; }
  </style></head><body>
    ${imgUrl ? `<img src="${escapeHtml(imgUrl)}" alt="" />` : ""}
    <h1>${escapeHtml(title)}</h1>
    ${meta ? `<p class="vm-meta">${escapeHtml(meta)}</p>` : ""}
    ${html}
  </body></html>`, [title, meta, html, imgUrl]);

  const save = async () => {
    setSaving(true);
    const res = await viewsMaxApi.updateSeoArticle(article.id, {
      title,
      category: category as SeoArticleRow["category"],
      meta_description: meta || null,
      html,
      featured_image_url: imgUrl || null,
    });
    setSaving(false);
    if (res.success) { toast.success("Article updated."); onSaved(); }
    else toast.error(res.error || "Couldn't save the article.");
  };

  const modeBtn = (id: "preview" | "edit", text: string) => (
    <button onClick={() => setMode(id)} style={{
      ...smallBtn, height: 30, padding: "0 14px",
      background: mode === id ? "var(--ink-on-paper-1)" : "var(--paper-0)",
      border: "1px solid " + (mode === id ? "var(--ink-on-paper-1)" : "var(--line-1)"),
      color: mode === id ? "#fff" : "var(--ink-on-paper-2)",
    }}>{text}</button>
  );

  return (
    <div onClick={onClose} style={{ position: "fixed", inset: 0, background: "rgba(10,10,12,.5)", zIndex: 60, display: "grid", placeItems: "center", padding: 20 }}>
      <div onClick={(e) => e.stopPropagation()} style={{ background: "var(--paper-0)", borderRadius: 18, border: "1px solid var(--line-1)", boxShadow: "var(--hard)", width: "min(920px, 100%)", maxHeight: "92vh", overflow: "auto", padding: 22, display: "flex", flexDirection: "column", gap: 14 }}>
        <div style={{ display: "flex", alignItems: "center", gap: 12, flexWrap: "wrap" }}>
          <div style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 18, color: "var(--ink-on-paper-1)", marginRight: "auto" }}>
            {editable ? "Article" : "Article (read-only)"}
          </div>
          <StatusChip status={article.status} />
          {article.published_url && (
            <a href={article.published_url} target="_blank" rel="noopener noreferrer" style={{ ...smallBtn, textDecoration: "none", height: 30 }}>View live ↗</a>
          )}
          <div style={{ display: "flex", gap: 6 }}>
            {modeBtn("preview", "Preview")}
            {editable && modeBtn("edit", "Edit")}
          </div>
        </div>

        {mode === "preview" ? (
          <iframe sandbox="" title="Article preview" srcDoc={previewDoc}
            style={{ width: "100%", height: "62vh", border: "1px solid var(--line-1)", borderRadius: 12, background: "#fff" }} />
        ) : (
          <>
            <div style={{ display: "grid", gridTemplateColumns: "2fr 1fr", gap: 14 }}>
              <div>
                <span style={label}>Title</span>
                <input style={field} value={title} onChange={(e) => setTitle(e.target.value)} />
              </div>
              <div>
                <span style={label}>Category</span>
                <select style={field} value={category} onChange={(e) => setCategory(e.target.value)}>
                  {CATEGORIES.map((c) => <option key={c} value={c}>{c}</option>)}
                </select>
              </div>
            </div>
            <div>
              <span style={label}>Meta description ({meta.length}/155)</span>
              <input style={field} value={meta} onChange={(e) => setMeta(e.target.value)} maxLength={320} />
            </div>
            <div>
              <span style={label}>Featured image URL</span>
              <div style={{ display: "flex", gap: 10, alignItems: "center" }}>
                {imgUrl && <img src={imgUrl} alt="" style={{ width: 72, height: 48, objectFit: "cover", borderRadius: 8, border: "1px solid var(--line-1)", flexShrink: 0 }} />}
                <input style={field} placeholder="https://… (generated automatically if left empty)" value={imgUrl} onChange={(e) => setImgUrl(e.target.value)} />
              </div>
            </div>
            <div>
              <span style={label}>Body (HTML)</span>
              <textarea style={{ ...field, minHeight: 300, fontFamily: "var(--font-mono)", fontSize: 12.5, lineHeight: 1.55, resize: "vertical" }} value={html} onChange={(e) => setHtml(e.target.value)} />
            </div>
          </>
        )}

        <div style={{ display: "flex", justifyContent: "flex-end", gap: 10 }}>
          <button style={{ ...smallBtn, height: 34, padding: "0 16px" }} onClick={onClose}>{editable ? "Cancel" : "Close"}</button>
          {editable && (
            <button style={{ ...smallBtn, height: 34, padding: "0 16px", background: "var(--vm-red)", border: "none", color: "#fff" }} onClick={saving ? undefined : save}>
              {saving ? "Saving…" : "Save article"}
            </button>
          )}
        </div>
      </div>
    </div>
  );
}
