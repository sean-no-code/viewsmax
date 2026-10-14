// Analytics — Landing Pages list + Add-landing-page modal (creates a real
// tracking_event with a conversion goal).
import { useEffect, useMemo, useState } from "react";
import { useNavigate } from "react-router-dom";
import { toast } from "sonner";
import i18n from "i18next";
import { useTranslation } from "react-i18next";
import { CARD, Icon, SectionHead, Btn, Chip } from "@/components/analytics/primitives";
import { Modal, Field, TextInput } from "@/components/analytics/Modal";
import { Empty } from "@/components/analytics/shared";
import { AnalyticsShell, AnalyticsLoading, useFunnlModel } from "@/components/analytics/useAnalytics";
import { FiltersPanel, useUrlFilters, matchesText, matchesMulti, matchesRange, matchesDateRange, type FilterField } from "@/components/analytics/FiltersPanel";
import { PlacementIcon } from "@/components/analytics/PlacementIcon";
import { fmtNum, fmtMoneyK, fmtMoney, fmtPct, platformMeta, type PageMetric } from "@/lib/analytics-model";
import { viewsMaxApi } from "@/lib/api-service";

function OfferCard({ page, onOpen }: { page: PageMetric; onOpen: () => void }) {
  const { t } = useTranslation();
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
        {([
          ["views", t("offers.list.card.views", "Views"), page.viewsSince > 0 ? fmtNum(page.viewsSince) : "—", undefined],
          ["clicks", t("offers.list.card.clicks", "Clicks"), fmtNum(page.clicks), undefined],
          ["conv", t("offers.list.card.convPct", "Conv %"), page.viewCr == null ? "—" : fmtPct(page.viewCr), t("offers.list.card.convPctTitle", "Conversion rate (reach-based)")],
          ["sales", t("offers.list.card.sales", "Sales"), String(page.conversions), undefined],
          ["revenue", t("offers.list.card.revenue", "Revenue"), fmtMoneyK(page.rev), undefined],
        ] as const).map(([key, k, v, title]) => (
          <div key={key} style={{ minWidth: 0 }}>
            <div title={title} style={{ fontFamily: "var(--font-mono)", fontSize: 9.5, letterSpacing: ".05em", textTransform: "uppercase", color: "var(--ink-on-paper-3)", whiteSpace: "nowrap" }}>{k}</div>
            <div style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 19, color: "var(--ink-on-paper-1)", marginTop: 2, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>{v}</div>
          </div>
        ))}
      </div>
      <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between", marginTop: 14 }}>
        <Chip tone="ghost"><Icon name="target" size={12} stroke="var(--ink-on-paper-3)" />{page.conv.url}</Chip>
        <span style={{ fontFamily: "var(--font-mono)", fontWeight: 700, fontSize: 13, color: "var(--vm-volt-deep)" }}>{page.conv.value ? fmtMoney(page.conv.value) : t("offers.list.card.lead", "lead")}</span>
      </div>
    </div>
  );
}

function AddOfferModal({ onClose, onCreated }: { onClose: () => void; onCreated: (id: string) => void }) {
  const { t } = useTranslation();
  const [name, setName] = useState("");
  const [url, setUrl] = useState("");
  const [saving, setSaving] = useState(false);

  const create = async () => {
    if (!url.trim()) { toast.error(t("offers.create.urlRequired", "Add the offer URL.")); return; }
    const normalized = /^https?:\/\//.test(url) ? url : `https://${url}`;
    setSaving(true);
    const res = await viewsMaxApi.createTrackingEvent({
      name: name.trim() || null,
      offer_url: normalized,
      conversion_value: 0,
    } as never);
    setSaving(false);
    if (res.success && res.data) {
      toast.success(t("offers.create.created", "Offer created."));
      onCreated(String(res.data.id));
    } else {
      toast.error(res.error || t("offers.create.failed", "Couldn't create the offer."));
    }
  };

  return (
    <Modal title={t("offers.create.title", "Add an offer")} sub={t("offers.create.sub", "Add the page you're driving traffic to — set up conversion events next.")} onClose={onClose}>
      <Field label={t("offers.create.nameLabel", "Offer name")} hint={t("offers.create.nameHint", "internal label")}><TextInput placeholder={t("offers.create.namePlaceholder", "e.g. Spring Launch")} value={name} onChange={(e) => setName(e.target.value)} /></Field>
      <Field label={t("offers.create.urlLabel", "Offer URL")}><TextInput placeholder={t("offers.create.urlPlaceholder", "yoursite.com/spring-launch")} value={url} onChange={(e) => setUrl(e.target.value)} /></Field>
      <div style={{ display: "flex", justifyContent: "flex-end", gap: 10, marginTop: 4 }}>
        <Btn kind="ghost" onClick={onClose}>{t("offers.create.cancel", "Cancel")}</Btn>
        <Btn kind="aqua" icon="check" onClick={saving ? undefined : create}>{saving ? t("offers.create.creating", "Creating…") : t("offers.create.submit", "Create offer")}</Btn>
      </div>
    </Modal>
  );
}

export default function Offers() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { model, loading, reload } = useFunnlModel();
  const [modal, setModal] = useState(false);

  // Filters (URL-persisted, AND-combined) applied client-side over the loaded
  // offers — the index endpoint returns the full set.
  const allPages = useMemo(() => model?.pages ?? [], [model]);
  const filterFields = useMemo<FilterField[]>(() => {
    const present = [...new Set(allPages.flatMap((p) => p.platforms))];
    return [
      { kind: "text", key: "q", label: t("offers.list.filters.name", "Name"), placeholder: t("offers.list.filters.namePlaceholder", "Search offers…"), suggestions: allPages.map((p) => p.name) },
      { kind: "multi", key: "platforms", label: t("offers.list.filters.platform", "Platform"), options: present.map((p) => ({ value: p, label: platformMeta(p).name, icon: <PlacementIcon placement={p} size={18} /> })) },
      { kind: "daterange", key: "created", label: t("offers.list.filters.created", "Created") },
      { kind: "minmax", key: "revenue", label: t("offers.list.filters.revenue", "Revenue ($)") },
      { kind: "minmax", key: "sales", label: t("offers.list.filters.sales", "Sales") },
    ];
  }, [allPages, t]);
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
  const [planName, setPlanName] = useState("");
  const planLabel = planName || t("offers.list.planCurrent", "current");
  const atOfferCap = offerLimit !== null && offerCount >= offerLimit;

  useEffect(() => {
    (async () => {
      const [planRes, eventsRes] = await Promise.all([
        viewsMaxApi.getCurrentPlan(),
        viewsMaxApi.getTrackingEvents(),
      ]);
      if (planRes.success && planRes.data?.plan) {
        setOfferLimit(planRes.data.plan.max_offers ?? null);
        setPlanName(planRes.data.plan.display_name ?? "");
      } else {
        // No active subscription (past_due / lapsed): fall back to the Free cap so
        // the button gates to "Upgrade" instead of staying enabled and 422-ing.
        const plansRes = await viewsMaxApi.getPlans();
        const free = plansRes.success
          ? (plansRes.data ?? []).find((p) => p.name === "free")
          : null;
        setOfferLimit(free?.max_offers ?? 0);
        setPlanName(free?.display_name ?? i18n.t("offers.list.planFree", "Free"));
      }
      if (eventsRes.success && Array.isArray(eventsRes.data)) setOfferCount(eventsRes.data.length);
    })();
  }, [model]);

  return (
    <AnalyticsShell>
      <SectionHead eyebrow={t("offers.list.eyebrow", "LANDING PAGES")} title={t("offers.list.title", "Pages you're tracking.")}
        right={atOfferCap ? (
          <span title={offerLimit === 0
            ? t("offers.list.capNoTracking", "Your {{plan}} plan doesn't include offer tracking. Upgrade to add offers.", { plan: planLabel })
            : t("offers.list.capReached", {
                count: offerLimit ?? 0,
                plan: planLabel,
                defaultValue_one: "You've reached your {{plan}} plan's limit of {{count}} offer. Upgrade to add more.",
                defaultValue_other: "You've reached your {{plan}} plan's limit of {{count}} offers. Upgrade to add more.",
              })}>
            <Btn kind="ghost" icon="plus" onClick={() => navigate("/dashboard/billing")}>{t("offers.list.upgrade", "Upgrade to add more")}</Btn>
          </span>
        ) : (
          <Btn icon="plus" onClick={() => setModal(true)}>{t("offers.list.addLandingPage", "Add landing page")}</Btn>
        )} />
      {loading || !model ? (
        <AnalyticsLoading />
      ) : model.pages.length === 0 ? (
        <div style={{ ...CARD, padding: 48 }}><Empty label={t("offers.list.empty", "No offers yet — add your first to get a tracking snippet.")} /></div>
      ) : (
        <>
          <div style={{ marginBottom: 14 }}>
            <FiltersPanel fields={filterFields} filters={filters} />
          </div>
          {pages.length === 0 ? (
            <div style={{ ...CARD, padding: 48 }}><Empty label={t("offers.list.noMatches", "No offers match your filters.")} /></div>
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
