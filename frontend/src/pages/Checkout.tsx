import { useNavigate, useSearchParams } from "react-router-dom";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { ArrowLeft, Check, Crown } from "lucide-react";
import StripeTrialStep from "@/components/StripeTrialStep";
import { useAuth } from "@/hooks/useAuth";
import { useState } from "react";
import { checkoutTerms } from "@/lib/access";
import { PLAN_INCLUDES, formatMonthlyCredits } from "@/lib/plan-helpers";

const Checkout = () => {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { user, refreshUser } = useAuth();
  const [isComplete, setIsComplete] = useState(false);
  // What adding a card costs today: the card-backed trial, $0 until the free
  // window closes, or the full price once it has (see checkoutTerms).
  const terms = checkoutTerms(user);
  const chargeNow = terms.kind === "charge-now";
  const freeUntil = terms.kind === "window"
    ? new Date(terms.chargeAt).toLocaleDateString([], { day: "numeric", month: "short" })
    : null;

  // Get plan details from URL params or use defaults
  const planName = searchParams.get("plan") || "Creator Pro";
  const planPrice = searchParams.get("price") || "29";
  const planPeriod = searchParams.get("period") || "month";
  const planDescription = searchParams.get("description") || "Monthly Subscription";
  // Stripe price id of the selected tier — forwarded so the subscription is
  // created on the chosen plan rather than the trial default.
  const planPriceId = searchParams.get("price_id") || undefined;

  // The tier's limit lines arrive in the URL (comma-separated, from Plans),
  // its monthly credits as a plain number; everything else is the same on
  // every paid plan.
  const featuresParam = searchParams.get("features");
  const creditsParam = searchParams.get("credits");
  const features = [
    ...(featuresParam ? featuresParam.split(",") : []),
    ...(creditsParam !== null ? [formatMonthlyCredits(Number(creditsParam))] : []),
    ...PLAN_INCLUDES,
  ];

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
          Back
        </Button>

        <div className="max-w-6xl mx-auto">
          <div className="text-center mb-8">
            <h1 className="text-3xl md:text-4xl font-bold text-foreground mb-2">
              Complete Your Subscription
            </h1>
            <p className="text-muted-foreground">
              {chargeNow ? "Your free trial has ended — add your card to keep going" : freeUntil ? `Add your card — nothing to pay until ${freeUntil}` : "Add your card to start your free trial"}
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
                      <span className="text-muted-foreground">Monthly Price</span>
                      <div className="flex items-baseline gap-1">
                        <span className="text-3xl font-bold text-foreground">${planPrice}</span>
                        <span className="text-muted-foreground">/{planPeriod}</span>
                      </div>
                    </div>
                    <div className="border-t pt-3 mt-3">
                      <div className="flex items-center justify-between text-sm">
                        <span className="text-muted-foreground">Due Today</span>
                        <span className={`text-lg font-semibold ${chargeNow ? "text-foreground" : "text-green-600"}`}>{chargeNow ? `$${planPrice}.00` : "$0.00"}</span>
                      </div>
                      <p className="text-xs text-muted-foreground mt-2">
                        {chargeNow ? "Your free trial has ended — charged today" : "Free trial — no charge today"}
                      </p>
                    </div>
                  </div>

                  {/* Trial Information */}
                  <div className="bg-blue-50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                    <h4 className="font-semibold text-foreground mb-2 flex items-center gap-2">
                      <Check className="w-5 h-5 text-blue-600" />
                      {chargeNow ? `$${planPrice}/${planPeriod}, starting today` : `Free trial, then $${planPrice}/${planPeriod}`}
                    </h4>
                    <p className="text-sm text-muted-foreground">
                      {chargeNow
                        ? <>You'll be charged <strong>${planPrice}</strong> today and every {planPeriod} after that.</>
                        : <>You'll be charged <strong>${planPrice}</strong> {freeUntil ? `on ${freeUntil}` : "after the trial"} unless you cancel.</>}
                      {" "}Cancel anytime from your billing page.
                    </p>
                  </div>

                  {/* Features */}
                  <div>
                    <h4 className="font-semibold text-foreground mb-3">What's Included:</h4>
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
                        Subscribing as: <strong className="text-foreground">{user.email}</strong>
                      </p>
                    </div>
                  )}
                </CardContent>
              </Card>

              {/* Trust Badges */}
              <div className="flex items-center justify-center gap-6 text-sm text-muted-foreground">
                <div className="flex items-center gap-2">
                  <Check className="w-4 h-4 text-green-500" />
                  <span>Secure Payment</span>
                </div>
                <div className="flex items-center gap-2">
                  <Check className="w-4 h-4 text-green-500" />
                  <span>Cancel Anytime</span>
                </div>
                <div className="flex items-center gap-2">
                  <Check className="w-4 h-4 text-green-500" />
                  <span>Money Back Guarantee</span>
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
                    <p className="text-lg font-semibold text-foreground mb-2">You're all set!</p>
                    <p className="text-muted-foreground">Redirecting to your billing page...</p>
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
