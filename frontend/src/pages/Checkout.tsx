import { useNavigate, useSearchParams } from "react-router-dom";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { ArrowLeft, Check, Crown } from "lucide-react";
import StripeTrialStep from "@/components/StripeTrialStep";
import { useAuth } from "@/hooks/useAuth";
import { useState } from "react";
import { ACTIVATION_FEE_USD, checkoutTerms } from "@/lib/access";
import { planIncludes } from "@/lib/plan-helpers";
import { Trans, useTranslation } from "react-i18next";

const Checkout = () => {
  const navigate = useNavigate();
  const { t } = useTranslation();
  const [searchParams] = useSearchParams();
  const { user, refreshUser } = useAuth();
  const [isComplete, setIsComplete] = useState(false);
  // What adding a card costs today: the $1 activation fee while the free
  // window is open (the plan price follows when it closes), or the full price
  // once it has closed (see checkoutTerms).
  const terms = checkoutTerms(user);
  const chargeNow = terms.kind === "charge-now";
  const freeUntil = terms.kind === "window"
    ? new Date(terms.chargeAt).toLocaleDateString([], { day: "numeric", month: "short" })
    : null;

  // Get plan details from URL params or use defaults
  const planName = searchParams.get("plan") || "Creator Pro";
  const planPrice = searchParams.get("price") || "29";
  const planPeriod = searchParams.get("period") || "month";
  // Plans always sends "month"; show it in the UI language, pass anything else through.
  const periodLabel = planPeriod === "month" ? t("checkout.period.month", "month") : planPeriod;
  const planDescription = searchParams.get("description") || t("checkout.defaultDescription", "Monthly Subscription");
  // Stripe price id of the selected tier — forwarded so the subscription is
  // created on the chosen plan rather than the trial default.
  const planPriceId = searchParams.get("price_id") || undefined;

  // The tier's limit lines arrive in the URL (comma-separated, from Plans);
  // everything else is the same on every paid plan.
  const featuresParam = searchParams.get("features");
  const features = [...(featuresParam ? featuresParam.split(",") : []), ...planIncludes(t)];

  // StripeTrialStep already creates the subscription, writes active_subscription
  // to localStorage, and dispatches "subscriptionUpdated". We just confirm + redirect.
  const handleSubscribed = () => {
    setIsComplete(true);
    // Re-read the profile so a promotional customer whose window had closed
    // is unlocked (has_active_plan) before we land back in the dashboard.
    void refreshUser();
    setTimeout(() => {
      navigate("/dashboard/billing", { replace: true });
    }, 1500);
  };

  return (
    <div className="min-h-screen bg-background">
      <div className="container mx-auto px-4 py-8">
        {/* Back Button */}
        <Button
          variant="ghost"
          onClick={() => navigate(-1)}
          className="mb-6"
        >
          <ArrowLeft className="w-4 h-4 mr-2" />
          {t("checkout.back", "Back")}
        </Button>

        <div className="max-w-6xl mx-auto">
          <div className="text-center mb-8">
            <h1 className="text-3xl md:text-4xl font-bold text-foreground mb-2">
              {t("checkout.title", "Complete Your Subscription")}
            </h1>
            <p className="text-muted-foreground">
              {chargeNow
                ? t("checkout.subtitle.chargeNow", "Your free trial has ended — add your card to keep going")
                : freeUntil
                  ? t("checkout.subtitle.window", "Add your card — $1 today, then nothing until {{date}}", { date: freeUntil })
                  : t("checkout.subtitle.trial", "Add your card to start your free trial")}
            </p>
          </div>

          <div className="grid lg:grid-cols-2 gap-8">
            {/* Left Column - Order Summary */}
            <div className="space-y-6">
              <Card className="border-primary/50 shadow-lg">
                <CardHeader>
                  <div className="flex items-center gap-3 mb-2">
                    <div className="w-12 h-12 rounded-full bg-gradient-to-r from-yellow-500 to-orange-500 flex items-center justify-center">
                      <Crown className="w-6 h-6 text-white" />
                    </div>
                    <div>
                      <CardTitle className="text-2xl">{planName}</CardTitle>
                      <CardDescription>{planDescription}</CardDescription>
                    </div>
                  </div>
                </CardHeader>
                <CardContent className="space-y-6">
                  {/* Pricing */}
                  <div className="bg-muted/50 rounded-lg p-4">
                    <div className="flex items-baseline justify-between mb-2">
                      <span className="text-muted-foreground">{t("checkout.summary.monthlyPrice", "Monthly Price")}</span>
                      <div className="flex items-baseline gap-1">
                        <span className="text-3xl font-bold text-foreground">${planPrice}</span>
                        <span className="text-muted-foreground">/{periodLabel}</span>
                      </div>
                    </div>
                    <div className="border-t pt-3 mt-3">
                      <div className="flex items-center justify-between text-sm">
                        <span className="text-muted-foreground">{t("checkout.summary.dueToday", "Due Today")}</span>
                        <span className={`text-lg font-semibold ${chargeNow ? "text-foreground" : "text-green-600"}`}>{chargeNow ? `$${planPrice}.00` : `$${ACTIVATION_FEE_USD}.00`}</span>
                      </div>
                      <p className="text-xs text-muted-foreground mt-2">
                        {chargeNow ? t("checkout.summary.chargedToday", "Your free trial has ended — charged today") : t("checkout.summary.noChargeToday", "Free trial — $1 activation fee today")}
                      </p>
                    </div>
                  </div>

                  {/* Trial Information */}
                  <div className="bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                    <h4 className="font-semibold text-foreground mb-2 flex items-center gap-2">
                      <Check className="w-5 h-5 text-blue-600" />
                      {chargeNow
                        ? t("checkout.terms.headingChargeNow", "${{price}}/{{period}}, starting today", { price: planPrice, period: periodLabel })
                        : t("checkout.terms.headingTrial", "Free trial, then ${{price}}/{{period}}", { price: planPrice, period: periodLabel })}
                    </h4>
                    <p className="text-sm text-muted-foreground">
                      {chargeNow ? (
                        <Trans
                          i18nKey="checkout.terms.chargeNow"
                          defaults="You'll be charged <0>${{price}}</0> today and every {{period}} after that."
                          values={{ price: planPrice, period: periodLabel }}
                          components={[<strong />]}
                        />
                      ) : freeUntil ? (
                        <Trans
                          i18nKey="checkout.terms.chargeOnDate"
                          defaults="You pay $1 today, then <0>${{price}}</0> on {{date}} unless you cancel."
                          values={{ price: planPrice, date: freeUntil }}
                          components={[<strong />]}
                        />
                      ) : (
                        <Trans
                          i18nKey="checkout.terms.chargeAfterTrial"
                          defaults="You'll be charged <0>${{price}}</0> after the trial unless you cancel."
                          values={{ price: planPrice }}
                          components={[<strong />]}
                        />
                      )}
                      {" "}{t("checkout.terms.cancelAnytime", "Cancel anytime from your billing page.")}
                    </p>
                  </div>

                  {/* Features */}
                  <div>
                    <h4 className="font-semibold text-foreground mb-3">{t("checkout.summary.whatsIncluded", "What's Included:")}</h4>
                    <ul className="space-y-2">
                      {features.map((feature, index) => (
                        <li key={index} className="flex items-center gap-3">
                          <Check className="w-5 h-5 text-green-500 flex-shrink-0" />
                          <span className="text-muted-foreground">{feature}</span>
                        </li>
                      ))}
                    </ul>
                  </div>

                  {/* User Info */}
                  {user && (
                    <div className="border-t pt-4">
                      <p className="text-sm text-muted-foreground">
                        <Trans
                          i18nKey="checkout.summary.subscribingAs"
                          defaults="Subscribing as: <0>{{email}}</0>"
                          values={{ email: user.email }}
                          components={[<strong className="text-foreground" />]}
                        />
                      </p>
                    </div>
                  )}
                </CardContent>
              </Card>

              {/* Trust Badges */}
              <div className="flex items-center justify-center gap-6 text-sm text-muted-foreground">
                <div className="flex items-center gap-2">
                  <Check className="w-4 h-4 text-green-500" />
                  <span>{t("checkout.badges.securePayment", "Secure Payment")}</span>
                </div>
                <div className="flex items-center gap-2">
                  <Check className="w-4 h-4 text-green-500" />
                  <span>{t("checkout.badges.cancelAnytime", "Cancel Anytime")}</span>
                </div>
                <div className="flex items-center gap-2">
                  <Check className="w-4 h-4 text-green-500" />
                  <span>{t("checkout.badges.moneyBack", "Money Back Guarantee")}</span>
                </div>
              </div>
            </div>

            {/* Right Column - Payment Method (Stripe) */}
            <div>
              {isComplete ? (
                <Card>
                  <CardContent className="text-center py-12">
                    <div className="w-16 h-16 mx-auto mb-4 rounded-full bg-green-100 flex items-center justify-center">
                      <Check className="w-8 h-8 text-green-600" />
                    </div>
                    <p className="text-lg font-semibold text-foreground mb-2">{t("checkout.complete.title", "You're all set!")}</p>
                    <p className="text-muted-foreground">{t("checkout.complete.redirecting", "Redirecting to your billing page...")}</p>
                  </CardContent>
                </Card>
              ) : (
                <StripeTrialStep onSubscribed={handleSubscribed} priceId={planPriceId} />
              )}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

export default Checkout;
