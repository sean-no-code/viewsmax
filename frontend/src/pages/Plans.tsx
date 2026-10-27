import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Check, ArrowRight, CheckCircle2, AlertTriangle } from "lucide-react";
import { useEffect, useState } from "react";
import { useAuth } from "@/hooks/useAuth";
import { useNavigate } from "react-router-dom";
import { viewsMaxApi, type PlanTier } from "@/lib/api-service";
import { toast } from "sonner";
import { Trans, useTranslation } from "react-i18next";
import type { TFunction } from "i18next";
import { checkoutTerms } from "@/lib/access";
import {
	AlertDialog,
	AlertDialogAction,
	AlertDialogCancel,
	AlertDialogContent,
	AlertDialogDescription,
	AlertDialogFooter,
	AlertDialogHeader,
	AlertDialogTitle,
	AlertDialogTrigger,
} from "@/components/ui/alert-dialog";
import {
	findCurrentPlan,
	decidePlanCta,
	sortPlansByPrice,
	iconForPlan,
	planIncludes,
	type PlanCta,
} from "@/lib/plan-helpers";

// Helper to get initial subscription from localStorage
const getInitialSubscription = (): { subscriptionId: string; details?: { status?: string; plan_id?: string; billing_info?: { next_billing_time?: string } }; cancelled_at?: string; expires_at?: string; createdAt?: number } | null => {
	try {
		const raw = localStorage.getItem("active_subscription");
		if (raw) {
			const subscription = JSON.parse(raw);
			if (subscription.subscriptionId) {
				return subscription;
			}
		}
	} catch (_e) { /* ignore */ }
	return null;
};

const ctaLabels = (t: TFunction): Record<PlanCta, string> => ({
	subscribe: t("billing.plans.ctaStartTrial", "Start Free Trial"),
	current: t("billing.plans.ctaCurrent", "Current Plan"),
	upgrade: t("billing.plans.ctaUpgrade", "Upgrade"),
	downgrade: t("billing.plans.ctaDowngrade", "Downgrade"),
});

// Plan limit lines (null = unlimited), with real plurals per language.
const limitLines = (t: TFunction, plan: PlanTier): string[] => [
	plan.max_channels === null
		? t("billing.plans.unlimitedChannels", "Unlimited channels")
		: t("billing.plans.channelCount", { count: plan.max_channels, defaultValue_one: "{{count}} channel", defaultValue_other: "{{count}} channels" }),
	plan.max_offers === null
		? t("billing.plans.unlimitedOffers", "Unlimited offers")
		: t("billing.plans.offerCount", { count: plan.max_offers, defaultValue_one: "{{count}} offer", defaultValue_other: "{{count}} offers" }),
	plan.max_posts_per_month === null
		? t("billing.plans.unlimitedPostsPerMonth", "Unlimited posts/mo")
		: t("billing.plans.postsPerMonthCount", { count: plan.max_posts_per_month, defaultValue_one: "{{count}} post/mo", defaultValue_other: "{{count}} posts/mo" }),
];


const Plans = () => {
	const { t } = useTranslation();
	const ctaLabel = ctaLabels(t);
	const navigate = useNavigate();
	const { user } = useAuth();
	// Free window closed: subscribing now charges today, so don't promise a trial.
	const trialUsed = checkoutTerms(user).kind === "charge-now";

	const [active, setActive] = useState(() => getInitialSubscription());
	const [plans, setPlans] = useState<PlanTier[]>([]);
	const [plansLoading, setPlansLoading] = useState(true);
	const [changingPriceId, setChangingPriceId] = useState<string | null>(null);
	const [isCancelling, setIsCancelling] = useState(false);
	const [showCancelDialog, setShowCancelDialog] = useState(false);
	// A plan change is a billed action (Stripe prorates immediately), so confirm
	// before firing. Holds the tier + whether it's an upgrade or downgrade.
	const [pendingChange, setPendingChange] = useState<{ plan: PlanTier; cta: PlanCta } | null>(null);
	// Past-due detection (getCurrentPlan hides it). Drives the recovery banner.
	const [isPastDue, setIsPastDue] = useState(false);
	const [openingPortal, setOpeningPortal] = useState(false);

	const subStatus = active?.details?.status?.toLowerCase();
	const isActive = Boolean(active) && (subStatus === 'active' || subStatus === 'trialing');
	// The live subscription's Stripe price id identifies which tier the user is on.
	const currentPriceId = isActive ? (active?.details?.plan_id ?? null) : null;

	const formatExpiry = (dateString?: string | null) => {
		if (!dateString) return null;
		const parsed = new Date(dateString);
		return Number.isNaN(parsed.getTime())
			? null
			: parsed.toLocaleDateString(undefined, { year: "numeric", month: "long", day: "numeric" });
	};

	// Trial / renewal status, derived from the live subscription pivot.
	const subExpiry = active?.expires_at ?? active?.details?.billing_info?.next_billing_time ?? null;
	const expiryLabel = formatExpiry(subExpiry);
	const isTrialing = subStatus === 'trialing';

	// Load the pricing tiers from the backend.
	useEffect(() => {
		let cancelled = false;
		(async () => {
			setPlansLoading(true);
			const result = await viewsMaxApi.getPlans();
			if (cancelled) return;
			if (result.success && result.data) {
				// Free plan ($0) isn't part of the paid pricing grid.
				const paid = result.data.filter((p) => Number(p.price) > 0);
				setPlans(sortPlansByPrice(paid));
			} else {
				toast.error(result.error || t("billing.plans.loadFailed", "Failed to load plans"));
			}
			setPlansLoading(false);
		})();
		return () => { cancelled = true; };
	}, [t]);

	// Load + cache the current subscription (mirrors the prior behaviour).
	useEffect(() => {
		let isLoading = false;
		let debounceTimer: NodeJS.Timeout;

		const loadSubscription = async (forceRefresh = false) => {
			if (isLoading) return;

			if (!forceRefresh) {
				try {
					const cached = localStorage.getItem("active_subscription");
					if (cached) {
						const parsed = JSON.parse(cached);
						const age = Date.now() - (parsed.createdAt || 0);
						if (age < 30000) { setActive(parsed); return; }
					} else {
						const lastCheck = localStorage.getItem("subscription_last_check");
						if (lastCheck && Date.now() - parseInt(lastCheck) < 30000) { setActive(null); return; }
					}
				} catch (_e) { /* ignore */ }
			}

			isLoading = true;
			try {
				if (user) {
					const result = await viewsMaxApi.getCurrentPlan();
					const pivot = result.data?.pivot;
					const subId = pivot?.stripe_subscription_id || pivot?.paypal_subscription_id;
					if (result.success && pivot && subId) {
						const subscriptionData = {
							subscriptionId: subId,
							details: {
								status: pivot?.status,
								plan_id: pivot?.stripe_price_id ?? pivot?.plan_id ?? pivot?.paypal_plan_id,
								billing_info: { next_billing_time: pivot?.expires_at },
							},
							cancelled_at: pivot?.cancelled_at,
							expires_at: pivot?.expires_at,
							createdAt: Date.now(),
						};
						setActive(subscriptionData);
						localStorage.setItem("active_subscription", JSON.stringify(subscriptionData));
						localStorage.setItem("subscription_last_check", Date.now().toString());
						window.dispatchEvent(new CustomEvent("subscriptionUpdated"));
						return;
					}
				}
				setActive(null);
				localStorage.removeItem("active_subscription");
				localStorage.setItem("subscription_last_check", Date.now().toString());
				window.dispatchEvent(new CustomEvent("subscriptionUpdated"));
			} catch (_e) {
				console.error('[Plans] Failed to load subscription:', _e);
			} finally {
				isLoading = false;
			}
		};

		loadSubscription();

		const handleSubscriptionUpdate = () => {
			clearTimeout(debounceTimer);
			debounceTimer = setTimeout(() => loadSubscription(true), 500);
		};
		window.addEventListener("subscriptionUpdated", handleSubscriptionUpdate);
		return () => {
			clearTimeout(debounceTimer);
			window.removeEventListener("subscriptionUpdated", handleSubscriptionUpdate);
		};
	}, [user]);

	// Detect past_due (getCurrentPlan hides it) so we can show a recovery banner.
	useEffect(() => {
		let cancelled = false;
		(async () => {
			if (!user) { setIsPastDue(false); return; }
			const res = await viewsMaxApi.getBillingStatus();
			if (cancelled) return;
			setIsPastDue(Boolean(res.success && res.data?.has_subscription && res.data?.status === "past_due"));
		})();
		const refresh = () => { if (user) viewsMaxApi.getBillingStatus().then((r) => setIsPastDue(Boolean(r.success && r.data?.has_subscription && r.data?.status === "past_due"))); };
		window.addEventListener("subscriptionUpdated", refresh);
		return () => { cancelled = true; window.removeEventListener("subscriptionUpdated", refresh); };
	}, [user]);

	// Send the user to the Stripe Billing Portal to update their card / pay the
	// open invoice. Recovery (past_due -> active) is handled by the webhook.
	const openBillingPortal = async () => {
		setOpeningPortal(true);
		try {
			const res = await viewsMaxApi.createBillingPortalSession();
			if (res.success && res.data?.url) {
				window.location.href = res.data.url;
			} else {
				toast.error(res.error || t("billing.plans.portalFailed", "Couldn't open the billing portal."));
				setOpeningPortal(false);
			}
		} catch {
			toast.error(t("billing.plans.portalFailed", "Couldn't open the billing portal."));
			setOpeningPortal(false);
		}
	};

	// Upgrade / downgrade an existing subscription. Stripe prorates. A blocked
	// downgrade (over the offer cap) returns 422 — surface that message so the
	// user knows to delete offers first.
	const handleChangePlan = async (priceId: string) => {
		if (!user) { navigate("/auth"); return; }
		setChangingPriceId(priceId);
		try {
			const result = await viewsMaxApi.changePlan(priceId);
			if (result.success) {
				toast.success(t("billing.plans.changeRequested", "Plan change requested — it'll update once Stripe confirms."));
				// Give the webhook a moment, then refresh the current plan.
				setTimeout(() => window.dispatchEvent(new CustomEvent("subscriptionUpdated")), 1500);
			} else {
				// e.g. "The Starter plan allows 1 offer(s), but you have 3. Please delete…"
				toast.error(result.error || t("billing.plans.changeFailed", "Failed to change plan"));
			}
		} finally {
			setChangingPriceId(null);
		}
	};

	const handlePlanCta = (plan: PlanTier, cta: PlanCta) => {
		if (!user) { navigate("/auth"); return; }
		if (cta === "subscribe") {
			const params = new URLSearchParams({
				plan: plan.display_name,
				price: String(Math.round(Number(plan.price))),
				period: "month",
				description: plan.description ?? t("billing.plans.monthlySubscription", "Monthly Subscription"),
			});
			if (plan.features?.length) params.set("features", plan.features.join(","));
			if (plan.stripe_price_id) params.set("price_id", plan.stripe_price_id);
			navigate(`/checkout?${params.toString()}`);
			return;
		}
		if ((cta === "upgrade" || cta === "downgrade") && plan.stripe_price_id) {
			// Don't bill on a single click — confirm first.
			setPendingChange({ plan, cta });
		}
	};

	const confirmChangePlan = async () => {
		if (!pendingChange?.plan.stripe_price_id) return;
		const priceId = pendingChange.plan.stripe_price_id;
		setPendingChange(null);
		await handleChangePlan(priceId);
	};

	const handleCancelSubscription = async () => {
		if (!active?.subscriptionId) { toast.error(t("billing.plans.noActiveSubscription", "No active subscription found")); return; }
		let expiresAt = active?.expires_at || active?.details?.billing_info?.next_billing_time;
		setIsCancelling(true);
		try {
			const result = await viewsMaxApi.cancelSubscription(
				active.subscriptionId,
				"User requested cancellation from billing page"
			);
			if (result.success) {
				try {
					const refreshed = await viewsMaxApi.getCurrentPlan();
					if (refreshed.success && (refreshed.data?.pivot?.stripe_subscription_id || refreshed.data?.pivot?.paypal_subscription_id)) {
						expiresAt = refreshed.data.pivot?.expires_at || expiresAt;
					}
				} catch (_e) { /* fall back to local */ }

				const finalSubscription = {
					...active,
					expires_at: expiresAt,
					cancelled_at: new Date().toISOString(),
				};
				const formattedExpiry = formatExpiry(finalSubscription.expires_at || finalSubscription.details?.billing_info?.next_billing_time);
				setActive(finalSubscription);
				localStorage.setItem("active_subscription", JSON.stringify(finalSubscription));
				localStorage.setItem("subscription_last_check", Date.now().toString());
				window.dispatchEvent(new CustomEvent("subscriptionUpdated"));
				toast.success(
					formattedExpiry
						? t("billing.plans.cancelledUntil", "Subscription cancelled successfully. You'll have access until {{date}}.", { date: formattedExpiry })
						: t("billing.plans.cancelled", "Subscription cancelled successfully.")
				);
			} else {
				toast.error(result.error || t("billing.plans.cancelFailed", "Failed to cancel subscription. Please try again."));
			}
		} catch (error) {
			console.error("Cancel subscription error:", error);
			toast.error(t("billing.plans.unexpectedError", "An unexpected error occurred. Please try again."));
		} finally {
			setIsCancelling(false);
			setShowCancelDialog(false);
		}
	};

	const current = findCurrentPlan(plans, currentPriceId);

	return (
		<div className="min-h-screen bg-background">
			<div className="container mx-auto px-4 py-12">
				<div className="text-center mb-8">
					<h1 className="text-3xl md:text-4xl font-bold text-foreground mb-4">
						{isActive ? t("billing.plans.manageTitle", "Manage Your Plan") : t("billing.plans.chooseTitle", "Choose Your Growth Plan")}
					</h1>
					{!isActive && (
						<p className="text-lg text-muted-foreground max-w-2xl mx-auto">
							{trialUsed
								? t("billing.plans.trialEndedIntro", "Your free trial has ended. Pick the tier that fits how you grow.")
								: t("billing.plans.trialIntro", "7-day free trial — add a card, nothing is charged until it ends — then pick the tier that fits how you grow.")}
						</p>
					)}
				</div>

				{isPastDue && (
					<div className="max-w-3xl mx-auto mb-8 rounded-lg border border-destructive/40 bg-destructive/10 p-4">
						<div className="flex items-start gap-3">
							<AlertTriangle className="w-5 h-5 text-destructive flex-shrink-0 mt-0.5" />
							<div className="flex-1">
								<p className="font-semibold text-foreground">{t("billing.plans.pastDueTitle", "Your last payment failed")}</p>
								<p className="text-sm text-muted-foreground mt-0.5">
									{t("billing.plans.pastDueBody", "Update your payment method to settle the outstanding invoice and restore full access.")}
								</p>
							</div>
							<Button size="sm" variant="cta" disabled={openingPortal} onClick={openBillingPortal}>
								{openingPortal ? t("billing.plans.opening", "Opening…") : t("billing.plans.updatePayment", "Update payment method")}
							</Button>
						</div>
					</div>
				)}

				{plansLoading ? (
					<p className="text-center text-muted-foreground">{t("billing.plans.loading", "Loading plans…")}</p>
				) : (
					<div className="grid gap-6 md:grid-cols-2 lg:grid-cols-4 max-w-7xl mx-auto items-stretch">
						{plans.map((plan) => {
							const cta = decidePlanCta(plan, current);
							const isCurrent = cta === "current";
							const busy = changingPriceId === plan.stripe_price_id;
							const PlanIcon = iconForPlan(plan.name);
							return (
								<Card
									key={plan.id}
									className={`relative flex flex-col transition-all duration-300 hover:shadow-card ${
										isCurrent ? 'border-primary/50 ring-2 ring-primary/30' : 'border-border/50 hover:border-primary/20'
									}`}
								>
									{isCurrent && (
										<div className="absolute -top-3 left-1/2 -translate-x-1/2 z-10">
											<Badge className="bg-green-500 text-white px-4 py-1 rounded-full text-sm font-semibold">
												<CheckCircle2 className="w-3 h-3 mr-1" />
												{t("billing.plans.currentBadge", "Current Plan")}
											</Badge>
										</div>
									)}

									<CardHeader className="text-center pb-4">
										<div className="w-14 h-14 mx-auto rounded-full flex items-center justify-center mb-4 bg-secondary">
											<PlanIcon className="w-7 h-7 text-primary" />
										</div>
										<CardTitle className="text-2xl font-bold text-foreground">
											{plan.display_name}
										</CardTitle>
										<CardDescription className="text-muted-foreground mb-2 min-h-[40px]">
											{plan.description}
										</CardDescription>
										<div className="flex items-baseline justify-center gap-1">
											<span className="text-4xl font-bold text-foreground">
												${Math.round(Number(plan.price))}
											</span>
											<span className="text-muted-foreground">{plan.billing_cycle === "yearly" ? t("billing.plans.perYear", "/yr") : t("billing.plans.perMonth", "/mo")}</span>
										</div>
									</CardHeader>

									<CardContent className="flex flex-col flex-1 space-y-6">
										<ul className="space-y-3">
											{limitLines(t, plan).map((line, i) => (
												<li key={`limit-${i}`} className="flex items-center gap-3">
													<Check className="w-5 h-5 text-green-500 flex-shrink-0" />
													<span className="text-muted-foreground">{line}</span>
												</li>
											))}
											{planIncludes(t).map((f) => (
												<li key={f} className="flex items-start gap-3">
													<Check className="w-5 h-5 text-green-500 flex-shrink-0" />
													<span className="text-muted-foreground">{f}</span>
												</li>
											))}
										</ul>

										<div className="mt-auto space-y-3">
											{!isCurrent && (
												<Button
													variant={cta === "downgrade" ? "outline" : "cta"}
													size="lg"
													className="w-full group font-semibold"
													disabled={busy || !plan.stripe_price_id}
													onClick={() => handlePlanCta(plan, cta)}
												>
													{busy ? t("billing.plans.processing", "Processing…") : cta === "subscribe" && trialUsed ? t("billing.plans.ctaSubscribe", "Subscribe") : ctaLabel[cta]}
													{!busy && <ArrowRight className="w-4 h-4 group-hover:translate-x-1 transition-transform" />}
												</Button>
											)}

											{isCurrent && isActive && (
												active?.cancelled_at ? (
													<div className="bg-orange-50 dark:bg-orange-950/20 border border-orange-200 dark:border-orange-800 rounded-lg p-4">
														<div className="flex items-start gap-3">
															<AlertTriangle className="w-5 h-5 text-orange-600 flex-shrink-0 mt-0.5" />
															<p className="text-sm text-orange-700 dark:text-orange-300">
																<Trans
																	i18nKey="billing.plans.cancelledAccessUntil"
																	defaults="Cancelled — access until <0>{{date}}</0>."
																	values={{ date: formatExpiry(active?.expires_at || active?.details?.billing_info?.next_billing_time) || t("billing.plans.billingPeriodEnds", "your billing period ends") }}
																	components={[<strong key="date" />]}
																/>
															</p>
														</div>
													</div>
												) : (
													<>
														{expiryLabel && (
															<p className="text-sm text-center text-muted-foreground mb-2">
																{isTrialing
																	? <Trans i18nKey="billing.plans.trialEnds" defaults="Free trial · ends <0>{{date}}</0>" values={{ date: expiryLabel }} components={[<strong key="date" className="text-foreground" />]} />
																	: <Trans i18nKey="billing.plans.renews" defaults="Renews <0>{{date}}</0>" values={{ date: expiryLabel }} components={[<strong key="date" className="text-foreground" />]} />}
															</p>
														)}
														<AlertDialog open={showCancelDialog} onOpenChange={setShowCancelDialog}>
														<AlertDialogTrigger asChild>
															<Button variant="ghost" size="sm" className="w-full text-destructive hover:text-destructive hover:bg-destructive/10" disabled={isCancelling}>
																{isCancelling ? t("billing.plans.cancelling", "Cancelling...") : t("billing.plans.cancelSubscription", "Cancel Subscription")}
															</Button>
														</AlertDialogTrigger>
														<AlertDialogContent>
															<AlertDialogHeader>
																<AlertDialogTitle className="flex items-center gap-2">
																	<AlertTriangle className="w-5 h-5 text-destructive" />
																	{t("billing.plans.cancelConfirmTitle", "Are you sure you want to cancel?")}
																</AlertDialogTitle>
																<AlertDialogDescription className="space-y-3">
																	<p>{t("billing.plans.cancelConfirmBody", "You'll keep access to {{plan}} until your billing period ends, then move to the Free plan.", { plan: plan.display_name })}</p>
																</AlertDialogDescription>
															</AlertDialogHeader>
															<AlertDialogFooter>
																<AlertDialogCancel disabled={isCancelling}>{t("billing.plans.keepSubscription", "Keep Subscription")}</AlertDialogCancel>
																<AlertDialogAction onClick={handleCancelSubscription} disabled={isCancelling} className="bg-destructive text-destructive-foreground hover:bg-destructive/90">
																	{isCancelling ? t("billing.plans.cancelling", "Cancelling...") : t("billing.plans.yesCancel", "Yes, Cancel")}
																</AlertDialogAction>
															</AlertDialogFooter>
														</AlertDialogContent>
													</AlertDialog>
													</>
												)
											)}
										</div>
									</CardContent>
								</Card>
							);
						})}
					</div>
				)}

				<div className="text-center mt-12">
					<div className="flex items-center justify-center gap-6 text-sm text-muted-foreground">
						<div className="flex items-center gap-2"><Check className="w-4 h-4 text-green-500" /><span>{t("billing.plans.cancelAnytime", "Cancel anytime")}</span></div>
						<div className="flex items-center gap-2"><Check className="w-4 h-4 text-green-500" /><span>{t("billing.plans.prorated", "Upgrades & downgrades are prorated")}</span></div>
						<div className="flex items-center gap-2"><Check className="w-4 h-4 text-green-500" /><span>{t("billing.plans.moneyBack", "30-day money back")}</span></div>
					</div>
				</div>
			</div>

			{/* Confirm before a (billed, prorated) upgrade or downgrade. */}
			<AlertDialog open={!!pendingChange} onOpenChange={(open) => { if (!open) setPendingChange(null); }}>
				<AlertDialogContent>
					<AlertDialogHeader>
						<AlertDialogTitle className="flex items-center gap-2">
							{pendingChange?.cta === "downgrade" && <AlertTriangle className="w-5 h-5 text-orange-500" />}
							{pendingChange?.cta === "downgrade"
								? t("billing.plans.downgradeTitle", "Downgrade to {{plan}}?", { plan: pendingChange?.plan.display_name })
								: t("billing.plans.upgradeTitle", "Upgrade to {{plan}}?", { plan: pendingChange?.plan.display_name })}
						</AlertDialogTitle>
						<AlertDialogDescription className="space-y-3">
							{pendingChange?.cta === "downgrade" ? (
								<p>
									{t("billing.plans.downgradeBody", "You'll switch to {{plan}} (${{price}}/mo) now. Stripe applies a prorated credit toward your next invoice, and your plan limits drop to the new tier — if you're over the new offer cap you'll be asked to delete offers first.", {
										plan: pendingChange?.plan.display_name,
										price: pendingChange ? Math.round(Number(pendingChange.plan.price)) : 0,
									})}
								</p>
							) : (
								<p>
									{t("billing.plans.upgradeBody", "You'll move to {{plan}} (${{price}}/mo) right away. Stripe charges a prorated amount for the rest of this billing period.", {
										plan: pendingChange?.plan.display_name,
										price: pendingChange ? Math.round(Number(pendingChange.plan.price)) : 0,
									})}
								</p>
							)}
						</AlertDialogDescription>
					</AlertDialogHeader>
					<AlertDialogFooter>
						<AlertDialogCancel>{t("billing.plans.keepCurrentPlan", "Keep current plan")}</AlertDialogCancel>
						<AlertDialogAction onClick={confirmChangePlan}>
							{pendingChange?.cta === "downgrade" ? t("billing.plans.yesDowngrade", "Yes, downgrade") : t("billing.plans.yesUpgrade", "Yes, upgrade")}
						</AlertDialogAction>
					</AlertDialogFooter>
				</AlertDialogContent>
			</AlertDialog>
		</div>
	);
};

export default Plans;
