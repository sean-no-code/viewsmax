import { useEffect, useRef, useState } from "react";
import { useNavigate } from "react-router-dom";
import { Check, Loader2, LogOut } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { useAuth } from "@/hooks/useAuth";
import { viewsMaxApi, type PlanTier } from "@/lib/api-service";
import ConnectAccounts from "@/components/ConnectAccounts";
import PlanSelector from "@/components/PlanSelector";
import StripeTrialStep from "@/components/StripeTrialStep";
import TrialBanner from "@/components/TrialBanner";
import { toast } from "sonner";

type Step = "connect" | "plan" | "billing";

const STEPS: { key: Step; label: string }[] = [
  { key: "connect", label: "Connect accounts" },
  { key: "plan", label: "Choose plan" },
  { key: "billing", label: "Add payment" },
];

const Onboarding = () => {
  const navigate = useNavigate();
  const { user, refreshUser, signOut } = useAuth();
  const [step, setStep] = useState<Step>("connect");
  const [hasConnection, setHasConnection] = useState((user?.connections_count ?? 0) > 0);
  const [selectedPlan, setSelectedPlan] = useState<PlanTier | null>(null);
  const [finishing, setFinishing] = useState(false);
  // Guard so the "all prerequisites already met" auto-complete only fires once.
  const autoCompleted = useRef(false);

  // Derive the starting step from the user's current state (so a reload mid-flow
  // resumes correctly, and an already-eligible user completes immediately).
  useEffect(() => {
    if (!user) return;
    const connected = (user.connections_count ?? 0) > 0;
    const subscribed = !!user.has_active_subscription;
    setHasConnection(connected);

    // Subscription is the only hard requirement; connecting an account is
    // optional. Once subscribed, onboarding can complete regardless of whether
    // the user connected (or skipped) an account.
    if (subscribed) {
      if (!autoCompleted.current) {
        autoCompleted.current = true;
        void finishOnboarding();
      }
    } else if (connected) {
      // Connected but not subscribed → pick a plan before payment.
      setStep((prev) => (prev === "billing" ? "billing" : "plan"));
    } else {
      setStep("connect");
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [user]);

  const finishOnboarding = async () => {
    setFinishing(true);
    const result = await viewsMaxApi.completeOnboarding();
    if (result.success) {
      await refreshUser();
      navigate("/dashboard/post", { replace: true });
    } else {
      setFinishing(false);
      toast.error(result.error || "Couldn't finish setup. Please try again.");
    }
  };

  const handleConnectContinue = async () => {
    await refreshUser();
    setStep("plan");
  };

  const handleSubscribed = async () => {
    // StripeTrialStep has already written active_subscription + dispatched the
    // subscriptionUpdated event. Refresh the user so has_active_subscription is
    // current, then mark onboarding complete.
    await refreshUser();
    await finishOnboarding();
  };

  const currentIndex = STEPS.findIndex((s) => s.key === step);

  if (finishing) {
    return (
      <div className="min-h-screen flex items-center justify-center">
        <div className="text-center">
          <Loader2 className="mx-auto h-8 w-8 animate-spin text-primary" />
          <p className="mt-3 text-muted-foreground">Setting up your account…</p>
        </div>
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-background py-10 px-4">
      {/* The plan step shows up to four tiers side by side, so give it room. */}
      <div className={`mx-auto w-full ${step === "plan" ? "max-w-5xl" : "max-w-2xl"}`}>
        <div className="mb-4 flex justify-end">
          <Button
            variant="ghost"
            size="sm"
            className="text-muted-foreground"
            onClick={signOut}
          >
            <LogOut className="mr-2 h-4 w-4" />
            Log out
          </Button>
        </div>
        <div className="mb-8 text-center">
          <h1 className="text-3xl font-bold">Set up your account</h1>
          <p className="text-muted-foreground mt-1">
            Just three quick steps before you get started.
          </p>
        </div>

        {/* Step indicator */}
        <div className="mb-8 flex items-center justify-center gap-4">
          {STEPS.map((s, i) => {
            const isComplete = i < currentIndex;
            const isCurrent = i === currentIndex;
            return (
              <div key={s.key} className="flex items-center gap-2">
                <div
                  className={`flex h-8 w-8 items-center justify-center rounded-full text-sm font-medium ${isComplete
                    ? "bg-primary text-primary-foreground"
                    : isCurrent
                      ? "border-2 border-primary text-primary"
                      : "border-2 border-muted text-muted-foreground"
                    }`}
                >
                  {isComplete ? <Check className="h-4 w-4" /> : i + 1}
                </div>
                <span className={`text-sm ${isCurrent ? "font-medium" : "text-muted-foreground"}`}>
                  {s.label}
                </span>
                {i < STEPS.length - 1 && <div className="h-px w-8 bg-border" />}
              </div>
            );
          })}
        </div>

        {step === "connect" && (
          <Card>
            <CardHeader>
              <CardTitle>Connect your accounts</CardTitle>
              <CardDescription>
                Connect at least one platform so we can analyze your content.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
              <ConnectAccounts mode="onboarding" onAnyConnected={() => setHasConnection(true)} />
              <Button
                className="w-full"
                disabled={!hasConnection}
                onClick={handleConnectContinue}
              >
                Continue
              </Button>
              {/* Connecting an account is optional — let users move on to plans. */}
              <Button
                variant="ghost"
                size="sm"
                className="w-full text-muted-foreground"
                onClick={() => setStep("plan")}
              >
                Skip for now
              </Button>
            </CardContent>
          </Card>
        )}

        {step === "plan" && (
          <Card>
            <CardHeader>
              <CardTitle>Choose your plan</CardTitle>
              <CardDescription>
                Pick the tier that fits you. You can upgrade or downgrade anytime.
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
              <TrialBanner />
              <PlanSelector
                value={selectedPlan?.id ?? null}
                onChange={setSelectedPlan}
              />
              <div className="flex gap-3">
                <Button
                  variant="outline"
                  className="flex-1"
                  onClick={() => setStep("connect")}
                >
                  Back
                </Button>
                <Button
                  className="flex-1"
                  disabled={!selectedPlan}
                  onClick={() => setStep("billing")}
                >
                  Continue to payment
                </Button>
              </div>
            </CardContent>
          </Card>
        )}

        {step === "billing" && (
          <div className="space-y-4">
            {selectedPlan && (
              <div className="flex items-center justify-between rounded-lg border bg-muted/40 px-4 py-3 text-sm">
                <span className="text-muted-foreground">
                  Subscribing to{" "}
                  <strong className="text-foreground">{selectedPlan.display_name}</strong>
                </span>
                <Button variant="ghost" size="sm" onClick={() => setStep("plan")}>
                  Change
                </Button>
              </div>
            )}
            <StripeTrialStep
              onSubscribed={handleSubscribed}
              priceId={selectedPlan?.stripe_price_id ?? undefined}
            />
            <Button
              variant="outline"
              className="w-full"
              onClick={() => setStep("plan")}
            >
              Back to plans
            </Button>
          </div>
        )}
      </div>
    </div>
  );
};

export default Onboarding;
