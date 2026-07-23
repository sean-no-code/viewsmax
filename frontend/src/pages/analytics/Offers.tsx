// Analytics — Landing Pages list + Add-landing-page modal (creates a real
// tracking_event with a conversion goal).
import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { toast } from "sonner";
import { CARD, Icon, SectionHead, Btn, Chip } from "@/components/analytics/primitives";
import { Modal, Field, TextInput } from "@/components/analytics/Modal";
import { Empty } from "@/components/analytics/shared";
import { AnalyticsShell, AnalyticsLoading, useFunnlModel } from "@/components/analytics/useAnalytics";
import { FiltersPanel, useUrlFilters, matchesText, matchesMulti, matchesRange, matchesDateRange, type FilterField } from "@/components/analytics/FiltersPanel";
import { PlacementIcon } from "@/components/analytics/PlacementIcon";
import { fmtNum, fmtMoneyK, fmtMoney, fmtPct, platformMeta, type PageMetric } from "@/lib/analytics-model";
import { viewsMaxApi } from "@/lib/api-service";

function OfferCard({ page, onOpen }: { page: PageMetric; onOpen: () => void }) {
  return (
    <div onClick={onOpen}
      onMouseEnter={(e) => { e.currentTarget.style.boxShadow = "var(--hard)"; e.currentTarget.style.transform = "translate(-1px,-1px)"; }}
      onMouseLeave={(e) => { e.currentTarget.style.boxShadow = "0 1px 2px rgba(10,10,12,.04)"; e.currentTarget.style.transform = "none"; }}
      style={{ ...CARD, padding: 20, cursor: "pointer", transition: "box-shadow var(--dur), transform var(--dur)" }}>
      <div style={{ display: "flex", alignItems: "flex-start", justifyContent: "space-between", gap: 10, marginBottom: 14 }}>
        <div style={{ minWidth: 0 }}>
          <div style={{ fontFamily: "var(--font-display)", fontWeight: 700, fontSize: 17, color: "var(--ink-on-paper-1)", whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>{page.name}</div>
          <div style={{ fontFamily: "var(--font-mono)", fontSize: 12, color: "var(--ink-on-paper-3)", marginTop: 4, display: "flex", alignItems: "center", gap: 5 }}><Icon name="link" size={12} stroke="var(--ink-on-paper-3)" />{page.url}</div>
        </div>
        {page.platforms.length > 0 && (
          <div style={{ display: "flex", gap: 4, flexShrink: 0 }}>
            {page.platforms.slice(0, 5).map((p) => (
              <span key={p} title={platformMeta(p).name}><PlacementIcon placement={p} size={22} /></span>
            ))}
            {page.platforms.length > 5 && (
              <span style={{ fontFamily: "var(--font-mono)", fontSize: 10.5, fontWeight: 700, color: "var(--ink-on-paper-3)", alignSelf: "center" }}>+{page.platforms.length - 5}</span>
            )}
          </div>
        )}
      </div>
      <div style={{ display: "grid", gridTemplateColumns: "repeat(5,1fr)", gap: 10, padding: "14px 0", borderTop: "1px solid var(--paper-2)", borderBottom: "1px solid var(--paper-2)" }}>
        {([["Views", page.viewsSince > 0 ? fmtNum(page.viewsSince) : "—"], ["Clicks", fmtNum(page.clicks)], ["Conv %", page.viewCr == null ? "—" : fmtPct(page.viewCr)], ["Sales", String(page.conversions)], ["Revenue", fmtMoneyK(page.rev)]] as const).map(([k, v]) => (
          <div key={k} style={{ minWidth: 0 }}>
            <div title={k === "Conv %" ? "Conversion rate (reach-based)" : undefined} style={{ fontFamily: "var(--font-mono)", fontSize: 9.5, letterSpacing: ".05em", textTransform: "uppercase", color: "var(--ink-on-paper-3)", whiteSpace: "nowrap" }}>{k}</div>
            <div style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 19, color: "var(--ink-on-paper-1)", marginTop: 2, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>{v}</div>
          </div>
        ))}
      </div>
      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", marginTop: 14 }}>
        <Chip tone="ghost"><Icon name="target" size={12} stroke="var(--ink-on-paper-3)" />{page.conv.url}</Chip>
        <span style={{ fontFamily: "var(--font-mono)", fontWeight: 700, fontSize: 13, color: "var(--vm-volt-deep)" }}>{page.conv.value ? fmtMoney(page.conv.value) : "lead"}</span>
      </div>
    </div>
  );
}

function AddOfferModal({ onClose, onCreated }: { onClose: () => void; onCreated: (id: string) => void }) {
  const [name, setName] = useState("");
  const [url, setUrl] = useState("");
  const [saving, setSaving] = useState(false);

  const create = async () => {
    if (!url.trim()) { toast.error("Add the offer URL."); return; }
    const normalized = /^https?:\/\//.test(url) ? url : `https://${url}`;
    setSaving(true);
    const res = await viewsMaxApi.createTrackingEvent({
      name: name.trim() || null,
      offer_url: normalized,
      conversion_value: 0,
    } as never);
    setSaving(false);
    if (res.success && res.data) {
      toast.success("Offer created.");
      onCreated(String(res.data.id));
    } else {
      toast.error(res.error || "Couldn't create the offer.");
    }
  };

  return (
    <Modal title="Add an offer" sub="Add the page you're driving traffic to — set up conversion events next." onClose={onClose}>
      <Field label="Offer name" hint="internal label"><TextInput placeholder="e.g. Spring Launch" value={name} onChange={(e) => setName(e.target.value)} /></Field>
      <Field label="Offer URL"><TextInput placeholder="yoursite.com/spring-launch" value={url} onChange={(e) => setUrl(e.target.value)} /></Field>
      <div style={{ display: "flex", justifyContent: "flex-end", gap: 10, marginTop: 4 }}>
        <Btn kind="ghost" onClick={onClose}>Cancel</Btn>
        <Btn kind="aqua" icon="check" onClick={saving ? undefined : create}>{saving ? "Creating…" : "Create offer"}</Btn>
      </div>
    </Modal>
  );
}

export default function Offers() {
  const navigate = useNavigate();
  const { model, loading, reload } = useFunnlModel();
  const [modal, setModal] = useState(false);

  // Filters (URL-persisted, AND-combined) applied client-side over the loaded
  // offers — the index endpoint returns the full set.
  const allPages = useMemo(() => model?.pages ?? [], [model]);
  const filterFields = useMemo<FilterField[]>(() => {
    const present = [...new Set(allPages.flatMap((p) => p.platforms))];
    return [
      { kind: "text", key: "q", label: "Name", placeholder: "Search offers…", suggestions: allPages.map((p) => p.name) },
      { kind: "multi", key: "platforms", label: "Platform", options: present.map((p) => ({ value: p, label: platformMeta(p).name, icon: <PlacementIcon placement={p} size={18} /> })) },
      { kind: "daterange", key: "created", label: "Created" },
      { kind: "minmax", key: "revenue", label: "Revenue ($)" },
      { kind: "minmax", key: "sales", label: "Sales" },
    ];
  }, [allPages]);
  const filters = useUrlFilters(filterFields);
  const pages = useMemo(() => {
    const v = filters.values;
    return allPages.filter((p) =>
      (matchesText(p.name, v.q) || matchesText(p.url, v.q))
      && matchesMulti(p.platforms, v.platforms)
      && matchesDateRange(p.createdAt, v.created_from, v.created_to)
      && matchesRange(p.rev, v.revenue_min, v.revenue_max)
      && matchesRange(p.conversions, v.sales_min, v.sales_max));
  }, [allPages, filters.values]);

  // Plan offer-cap usage, to gate "Add landing page" at the limit. Count is the
  // unfiltered offer total (matches the backend); the 422 stays authoritative.
  const [offerLimit, setOfferLimit] = useState<number | null>(null);
  const [offerCount, setOfferCount] = useState(0);
  const [planName, setPlanName] = useState("current");
  const atOfferCap = offerLimit !== null && offerCount >= offerLimit;

  useEffect(() => {
    (async () => {
      const [planRes, eventsRes] = await Promise.all([
        viewsMaxApi.getCurrentPlan(),
        viewsMaxApi.getTrackingEvents(),
      ]);
      if (planRes.success && planRes.data?.plan) {
        setOfferLimit(planRes.data.plan.max_offers ?? null);
        setPlanName(planRes.data.plan.display_name ?? "current");
      } else {
        // No active subscription (past_due / lapsed): fall back to the Free cap so
        // the button gates to "Upgrade" instead of staying enabled and 422-ing.
        const plansRes = await viewsMaxApi.getPlans();
        const free = plansRes.success
          ? (plansRes.data ?? []).find((p) => p.name === "free")
          : null;
        setOfferLimit(free?.max_offers ?? 0);
        setPlanName(free?.display_name ?? "Free");
      }
      if (eventsRes.success && Array.isArray(eventsRes.data)) setOfferCount(eventsRes.data.length);
    })();
  }, [model]);

  return (
    <AnalyticsShell>
      <SectionHead eyebrow="LANDING PAGES" title="Pages you're tracking."
        right={atOfferCap ? (
          <span title={offerLimit === 0
            ? `Your ${planName} plan doesn't include offer tracking. Upgrade to add offers.`
            : `You've reached your ${planName} plan's limit of ${offerLimit} offer${offerLimit === 1 ? "" : "s"}. Upgrade to add more.`}>
            <Btn kind="ghost" icon="plus" onClick={() => navigate("/dashboard/billing")}>Upgrade to add more</Btn>
          </span>
        ) : (
          <Btn icon="plus" onClick={() => setModal(true)}>Add landing page</Btn>
        )} />
      {loading || !model ? (
        <AnalyticsLoading />
      ) : model.pages.length === 0 ? (
        <div style={{ ...CARD, padding: 48 }}><Empty label="No offers yet — add your first to get a tracking snippet." /></div>
      ) : (
        <>
          <div style={{ marginBottom: 14 }}>
            <FiltersPanel fields={filterFields} filters={filters} />
          </div>
          {pages.length === 0 ? (
            <div style={{ ...CARD, padding: 48 }}><Empty label="No offers match your filters." /></div>
          ) : (
            <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill,minmax(340px,1fr))", gap: 16 }}>
              {pages.map((p) => <OfferCard key={p.id} page={p} onOpen={() => navigate(`/dashboard/monetization/offers/${p.id}`)} />)}
            </div>
          )}
        </>
      )}
      {modal && <AddOfferModal onClose={() => setModal(false)} onCreated={(id) => { setModal(false); reload(); navigate(`/dashboard/monetization/offers/${id}`); }} />}
    </AnalyticsShell>
  );
}
