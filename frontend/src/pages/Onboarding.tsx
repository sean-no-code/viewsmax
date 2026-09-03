import { useEffect, useRef, useState } from "react";
import { useNavigate } from "react-router-dom";
import { Check, Loader2 } from "lucide-react";
import { useAuth } from "@/hooks/useAuth";
import { viewsMaxApi } from "@/lib/api-service";
import ConnectAccounts from "@/components/ConnectAccounts";
import TrialCheckout from "@/components/onboarding/TrialCheckout";
import { toast } from "sonner";

type Step = "connect" | "trial";

const STEPS: { key: Step; label: string }[] = [
  { key: "connect", label: "Connect accounts" },
  { key: "trial", label: "Choose plan & start trial" },
];

const pillStyle: React.CSSProperties = {
  background: "var(--vm-red)",
  color: "#fff",
  border: "none",
  borderRadius: "var(--r-pill)",
  padding: "13px 24px",
  fontFamily: "var(--font-body)",
  fontWeight: 700,
  fontSize: 15,
  cursor: "pointer",
};

const Onboarding = () => {
  const navigate = useNavigate();
  const { user, refreshUser, signOut } = useAuth();
  const [step, setStep] = useState<Step>("connect");
  const [hasConnection, setHasConnection] = useState((user?.connections_count ?? 0) > 0);
  const [finishing, setFinishing] = useState(false);
  // Guard so the "all prerequisites already met" auto-complete only fires once.
  const autoCompleted = useRef(false);

  // Derive the starting step from the user's current state (reload mid-flow
  // resumes; an already-subscribed user completes immediately).
  useEffect(() => {
    if (!user) return;
    const connected = (user.connections_count ?? 0) > 0;
    const subscribed = !!user.has_active_subscription;
    setHasConnection(connected);

    // Subscription is the only hard requirement; connecting is optional.
    if (subscribed) {
      if (!autoCompleted.current) {
        autoCompleted.current = true;
        void finishOnboarding();
      }
    } else if (connected) {
      setStep("trial");
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
    setStep("trial");
  };

  const handleSubscribed = async () => {
    // TrialCheckout has already persisted the subscription + dispatched the
    // subscriptionUpdated event. Refresh so has_active_subscription is current,
    // then mark onboarding complete.
    await refreshUser();
    await finishOnboarding();
  };

  const currentIndex = STEPS.findIndex((s) => s.key === step);

  if (finishing) {
    return (
      <div style={{ minHeight: "100vh", background: "var(--paper-1)", display: "flex", alignItems: "center", justifyContent: "center" }}>
        <div style={{ textAlign: "center" }}>
          <Loader2 className="animate-spin" size={32} style={{ color: "var(--vm-red)", margin: "0 auto" }} />
          <p style={{ marginTop: 12, color: "var(--ink-on-paper-3)" }}>Setting up your account…</p>
        </div>
      </div>
    );
  }

  const title = step === "trial" ? "Start your free trial" : "Set up your account";

  return (
    <div className="onboarding-wizard" style={{ minHeight: "100vh", background: "var(--paper-1)", color: "var(--ink-on-paper-1)", fontFamily: "var(--font-body)", padding: "28px 40px 64px" }}>
      <style>{`
        .onboarding-wizard .ow-pill { transition: all 200ms var(--ease-out, cubic-bezier(.2,.7,.2,1)); }
        .onboarding-wizard .ow-pill:hover:not(:disabled) { background: var(--vm-red-hot); }
        .onboarding-wizard .ow-pill:active:not(:disabled) { background: var(--vm-red-deep); transform: translateY(1px); }
        .onboarding-wizard .ow-pill:disabled { opacity:.5; cursor:default; }
      `}</style>
      <div style={{ maxWidth: step === "trial" ? 1180 : 640, margin: "0 auto" }}>
        <div style={{ display: "flex", alignItems: "center", justifyContent: "space-between" }}>
          <h1 style={{ fontFamily: "var(--font-display)", fontWeight: 800, fontSize: 38, letterSpacing: "-.03em", lineHeight: 1.02, margin: 0 }}>
            {title}
          </h1>
          <a onClick={signOut} style={{ fontSize: 14, fontWeight: 600, color: "var(--ink-on-paper-3)", cursor: "pointer" }}>
            Log out
          </a>
        </div>

        {/* Two-step progress */}
        <div style={{ display: "flex", alignItems: "center", justifyContent: "center", gap: 12, margin: "26px 0 30px" }}>
          {STEPS.map((s, i) => {
            const isComplete = i < currentIndex;
            const isCurrent = i === currentIndex;
            return (
              <div key={s.key} style={{ display: "flex", alignItems: "center", gap: 12 }}>
                <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
                  <div
                    style={{
                      width: 26,
                      height: 26,
                      borderRadius: 999,
                      display: "flex",
                      alignItems: "center",
                      justifyContent: "center",
                      fontSize: 13,
                      fontWeight: 700,
                      boxSizing: "border-box",
                      background: isComplete ? "var(--vm-red)" : "transparent",
                      color: isComplete ? "#fff" : isCurrent ? "var(--vm-red)" : "var(--ink-on-paper-3)",
                      border: isComplete ? "none" : isCurrent ? "2px solid var(--vm-red)" : "2px solid var(--line-2)",
                    }}
                  >
                    {isComplete ? <Check size={14} strokeWidth={3} /> : i + 1}
                  </div>
                  <span style={{ fontSize: 14, fontWeight: isCurrent ? 700 : 600, color: isCurrent || isComplete ? "var(--ink-on-paper-1)" : "var(--ink-on-paper-3)" }}>
                    {s.label}
                  </span>
                </div>
                {i < STEPS.length - 1 && <div style={{ width: 36, height: 1, background: "var(--line-2)" }} />}
              </div>
            );
          })}
        </div>

        {step === "connect" && (
          <div style={{ background: "var(--paper-0)", border: "1px solid var(--line-1)", borderRadius: "var(--r-lg)", padding: 32 }}>
            <div style={{ fontFamily: "var(--font-display)", fontWeight: 700, fontSize: 19, letterSpacing: "-.01em" }}>Connect your accounts</div>
            <div style={{ fontSize: 13, color: "var(--ink-on-paper-3)", marginTop: 2 }}>
              Connect at least one platform so we can analyze your content. You can always add more later.
            </div>
            <div style={{ margin: "24px 0" }}>
              <ConnectAccounts mode="onboarding" onAnyConnected={() => setHasConnection(true)} />
            </div>
            <button className="ow-pill" style={{ ...pillStyle, width: "100%" }} disabled={!hasConnection} onClick={handleConnectContinue}>
              Continue
            </button>
            <button
              onClick={() => setStep("trial")}
              style={{ width: "100%", marginTop: 12, background: "transparent", border: "none", color: "var(--ink-on-paper-3)", fontSize: 14, fontWeight: 600, cursor: "pointer" }}
            >
              Skip for now
            </button>
          </div>
        )}

        {step === "trial" && <TrialCheckout onSubscribed={handleSubscribed} />}
      </div>
    </div>
  );
};

export default Onboarding;
