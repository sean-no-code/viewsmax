import { ShieldCheck } from "lucide-react";

/**
 * Prominent reassurance shown on the plan + payment steps: no charge today,
 * and the trial runs for 7 days. Kept in one place so the copy stays identical
 * across the onboarding flow.
 */
const TrialBanner = () => (
  <div className="flex items-center gap-3 rounded-xl border-2 border-primary/30 bg-primary/5 px-4 py-3">
    <ShieldCheck className="h-6 w-6 shrink-0 text-primary" />
    <div className="text-left">
      <p className="text-base font-bold leading-tight text-foreground">
        $0 today · 7-day free trial
      </p>
      <p className="mt-0.5 text-xs text-muted-foreground">
        You won't be charged until your free trial ends. Cancel anytime.
      </p>
    </div>
  </div>
);

export default TrialBanner;
