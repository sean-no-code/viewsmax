// Header entry point for the "connect your AI app" popout. Standout pill in the
// dashboard top bar; the label drops to just the icon below lg (the header also holds the title, language, Support and account).
import { useState } from "react";
import { Sparkles } from "lucide-react";
import { useTranslation } from "react-i18next";
import ConnectAiAppsDialog from "@/components/ConnectAiAppsDialog";

export default function ConnectAiAppsButton() {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);
  const label = t("nav.aiApps", "Use ViewsMax in AI apps");

  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        aria-label={label}
        title={label}
        className="inline-flex h-9 shrink-0 items-center gap-2 whitespace-nowrap rounded-full border border-[color:var(--vm-volt)] bg-[color:var(--vm-volt-tint-l)] px-3 text-sm font-semibold text-foreground transition hover:brightness-95 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[color:var(--vm-volt-deep)]"
      >
        <Sparkles className="h-4 w-4 text-[color:var(--vm-volt-deep)]" aria-hidden />
        <span className="hidden lg:inline">{label}</span>
        <span className="hidden rounded-full bg-[color:var(--vm-red)] px-1.5 py-0.5 text-[10px] font-extrabold uppercase tracking-wide text-white lg:inline">New</span>
      </button>
      <ConnectAiAppsDialog open={open} onOpenChange={setOpen} />
    </>
  );
}
