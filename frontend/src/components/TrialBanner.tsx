import { ShieldCheck } from "lucide-react";

/**
 * Prominent reassurance shown on the plan + payment steps: no charge today,
 * and the trial runs for 7 days. Kept in one place so the copy stays identical
 * across the onboarding flow. Checkout overrides the copy for a user who is
 * inside their card-free window (see checkoutTerms).
 */
const TrialBanner = ({
  title = "$0 today · 7-day free trial",
  text = "You won't be charged until your free trial ends. Cancel anytime.",
}: { title?: string; text?: string }) => (
  <div className="flex items-center gap-3 rounded-xl border-2 border-primary/30 bg-primary/5 px-4 py-3">
    <ShieldCheck className="h-6 w-6 shrink-0 text-primary" />
    <div className="text-left">
      <p className="text-base font-bold leading-tight text-foreground">
        {title}
      </p>
      <p className="mt-0.5 text-xs text-muted-foreground">
        {text}
      </p>
    </div>
  </div>
);

export default TrialBanner;
