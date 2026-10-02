// House-style building blocks shared by the per-agent guides (AgentPage) and the
// /mcp landing page: card/heading/button classes, a clipboard hook with toasts,
// a labelled code block, a copyable prompt, and a <details>-based FAQ accordion.
import { useState } from "react";
import { Check, ChevronRight, Copy } from "lucide-react";
import { toast } from "sonner";
import type { FaqItem } from "@/lib/agent-pages";

export const CARD = "rounded-[18px] border border-line-1 bg-paper-0";
export const H2 = "font-display text-[26px] font-extrabold leading-[1.06] tracking-[-0.025em] sm:text-[32px]";
export const PRIMARY_BTN =
  "inline-flex h-[50px] items-center justify-center gap-2 rounded-xl bg-vm-red px-[26px] font-body text-[15px] font-bold text-white shadow-[4px_4px_0_0_var(--ink-on-paper-1)] transition-all duration-[120ms] hover:bg-vm-red-hot active:translate-y-px active:shadow-[2px_2px_0_0_var(--ink-on-paper-1)]";
export const OUTLINE_BTN =
  "inline-flex h-[50px] items-center justify-center rounded-xl border border-line-2 bg-paper-0 px-[26px] font-body text-[15px] font-bold text-ink-on-paper-1 transition-colors duration-200 hover:border-ink-on-paper-1";

export const useCopy = () => {
  const [copied, setCopied] = useState<string | null>(null);
  const copy = async (text: string, id: string, message = "Copied") => {
    try {
      await navigator.clipboard.writeText(text);
      setCopied(id);
      toast.success(message);
      setTimeout(() => setCopied(null), 1500);
    } catch {
      toast.error("Couldn't copy — try selecting the text.");
    }
  };
  return { copied, copy };
};

export type CopyFn = ReturnType<typeof useCopy>["copy"];

export function CodeBlock({ id, label, text, copied, copy }: { id: string; label: string; text: string; copied: string | null; copy: CopyFn }) {
  return (
    <div className="mt-3 overflow-hidden rounded-xl border border-line-1 bg-paper-2">
      <div className="flex items-center justify-between border-b border-line-1 px-3.5 py-2 text-xs font-bold text-ink-on-paper-2">
        <span>{label}</span>
        <button
          type="button"
          onClick={() => copy(text, id)}
          aria-label={`Copy ${label}`}
          className="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-ink-on-paper-1 transition-colors hover:bg-paper-0"
        >
          {copied === id ? <Check className="h-3.5 w-3.5" /> : <Copy className="h-3.5 w-3.5" />} Copy
        </button>
      </div>
      <pre className="overflow-x-auto px-3.5 py-3 font-mono text-[13px] leading-[1.55] text-ink-on-paper-1">{text}</pre>
    </div>
  );
}

export function PromptBox({ id, text, copied, copy }: { id: string; text: string; copied: string | null; copy: CopyFn }) {
  return (
    <div className="mt-3 flex items-start gap-3 rounded-xl border border-line-1 bg-paper-2 px-4 py-3">
      <p className="flex-1 text-[15px] italic leading-[1.5]">{text}</p>
      <button type="button" onClick={() => copy(text, id, "Prompt copied")} aria-label="Copy prompt" className="mt-0.5 shrink-0 text-ink-on-paper-2 hover:text-ink-on-paper-1">
        {copied === id ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
      </button>
    </div>
  );
}

export function Accordion({ items }: { items: FaqItem[] }) {
  return (
    <div className="mt-6 space-y-3">
      {items.map((f) => (
        <details key={f.q} className={`group ${CARD} px-5 py-4`}>
          <summary className="flex cursor-pointer list-none items-center justify-between gap-3 font-bold [&::-webkit-details-marker]:hidden">
            {f.q}
            <ChevronRight className="h-4 w-4 shrink-0 text-ink-on-paper-3 transition-transform group-open:rotate-90" aria-hidden />
          </summary>
          <p className="mt-3 text-[15px] leading-[1.5] text-ink-on-paper-2">{f.a}</p>
        </details>
      ))}
    </div>
  );
}
