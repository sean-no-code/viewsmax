import { ShieldCheck } from "lucide-react";

/**
 * Prominent reassurance on the payment step for a user inside their card-free
 * window: no charge today, the card is charged when the window closes. The
 * caller supplies the dates (see checkoutTerms); there is no default copy
 * because there is no other trial to describe.
 */
const TrialBanner = ({ title, text }: { title: string; text: string }) => (
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
