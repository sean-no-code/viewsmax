// "Connect ViewsMax to your AI app" popout, opened from the dashboard sidebar.
// Everything shown here comes from lib/agent-pages so it stays in step with the
// public /claude, /chatgpt and /ai pages: the MCP address, each app's directory
// listing (env-gated, falls back to the setup guide while unlisted) and the
// check prompt.
import { useState } from "react";
import { Link } from "react-router-dom";
import { ArrowRight, Check, Copy, ExternalLink, MessageSquareText } from "lucide-react";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { AGENTS, CHECK_PROMPT, MCP_ENDPOINT, agentListingUrl, type AgentConfig } from "@/lib/agent-pages";

/** Pasted into any agent that isn't Claude or ChatGPT; it reads /ai.md and sets itself up. */
export const SETUP_PROMPT =
  `Connect to ViewsMax, my social posting and analytics tool. MCP server: ${MCP_ENDPOINT}. ` +
  `Setup and tool docs: https://viewsmax.com/ai.md. Once connected, answer: ${CHECK_PROMPT}`;

const BTN =
  "inline-flex h-9 shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full border border-line-2 bg-paper-0 px-4 text-sm font-bold text-ink-on-paper-1 transition-colors hover:border-ink-on-paper-1 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-vm-red";

function useCopy() {
  const [copied, setCopied] = useState<string | null>(null);
  const copy = (text: string, id: string) => {
    // Clipboard access can be refused (permissions, insecure context); the "Copied" state still shows.
    navigator.clipboard?.writeText(text).catch(() => {});
    setCopied(id);
    setTimeout(() => setCopied(null), 1500);
  };
  return { copied, copy };
}

const ClaudeMark = () => (
  <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden className="text-[#D97757]">
    <g stroke="currentColor" strokeWidth="2.6" strokeLinecap="round">
      <path d="M12 3.5v17M3.5 12h17M6 6l12 12M18 6 6 18" />
    </g>
  </svg>
);

const ChatGptMark = () => (
  <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden className="text-ink-on-paper-1">
    <circle cx="12" cy="12" r="10.5" fill="currentColor" />
    <g fill="none" stroke="#fff" strokeWidth="1.5">
      <circle cx="12" cy="12" r="5.6" />
      <circle cx="12" cy="6.6" r="1.4" />
      <circle cx="16.7" cy="9.3" r="1.4" />
      <circle cx="16.7" cy="14.7" r="1.4" />
      <circle cx="12" cy="17.4" r="1.4" />
      <circle cx="7.3" cy="14.7" r="1.4" />
      <circle cx="7.3" cy="9.3" r="1.4" />
    </g>
  </svg>
);

/** Directory listing when ViewsMax is listed there, else that agent's setup guide. */
function ListingAction({ agent }: { agent: AgentConfig }) {
  const url = agentListingUrl(agent, import.meta.env as Record<string, string | undefined>);
  return url ? (
    <a href={url} target="_blank" rel="noreferrer" className={BTN}>
      Connect <ExternalLink className="h-3.5 w-3.5" aria-hidden />
    </a>
  ) : (
    <Link to={agent.slug} className={BTN}>
      Setup guide
    </Link>
  );
}

function Row({ icon, name, blurb, action }: { icon: React.ReactNode; name: string; blurb: string; action: React.ReactNode }) {
  return (
    <li className="flex items-center gap-3 py-3">
      <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-line-1 bg-paper-1">{icon}</span>
      <div className="min-w-0 flex-1">
        <div className="text-[15px] font-bold leading-tight">{name}</div>
        <div className="mt-0.5 text-[13px] leading-snug text-ink-on-paper-3">{blurb}</div>
      </div>
      {action}
    </li>
  );
}

interface ConnectAiAppsDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

export default function ConnectAiAppsDialog({ open, onOpenChange }: ConnectAiAppsDialogProps) {
  const { copied, copy } = useCopy();

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-md gap-0 rounded-2xl border-line-1 bg-paper-0 p-6 text-ink-on-paper-1">
        <DialogHeader className="text-left">
          <DialogTitle className="font-display text-[22px] font-extrabold tracking-[-0.02em]">Connect ViewsMax to your AI app</DialogTitle>
          <DialogDescription className="text-ink-on-paper-3">
            Post, find outliers and track sales from Claude, ChatGPT and more.
          </DialogDescription>
        </DialogHeader>

        <div className="mt-5 flex items-center gap-2 rounded-xl border border-line-1 bg-paper-1 py-2 pl-3 pr-2">
          <code className="min-w-0 flex-1 truncate font-mono text-[13px]">{MCP_ENDPOINT}</code>
          <button
            type="button"
            onClick={() => copy(MCP_ENDPOINT, "url")}
            aria-label="Copy MCP address"
            className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-ink-on-paper-2 hover:bg-paper-0 hover:text-ink-on-paper-1"
          >
            {copied === "url" ? <Check className="h-4 w-4" aria-hidden /> : <Copy className="h-4 w-4" aria-hidden />}
          </button>
        </div>

        <ul className="mt-3 divide-y divide-line-1">
          <Row icon={<ClaudeMark />} name="Claude" blurb="Connect ViewsMax to Claude" action={<ListingAction agent={AGENTS.claude} />} />
          <Row icon={<ChatGptMark />} name="ChatGPT" blurb="Connect ViewsMax to ChatGPT" action={<ListingAction agent={AGENTS.chatgpt} />} />
          <Row
            icon={<MessageSquareText className="h-[18px] w-[18px] text-ink-on-paper-2" aria-hidden />}
            name="Connect with a prompt"
            blurb="Send it to your AI agent for setup help."
            action={
              <button type="button" onClick={() => copy(SETUP_PROMPT, "prompt")} className={BTN}>
                {copied === "prompt" ? (
                  <>
                    Copied <Check className="h-3.5 w-3.5" aria-hidden />
                  </>
                ) : (
                  "Copy prompt"
                )}
              </button>
            }
          />
        </ul>

        <p className="mt-4 text-center text-[13px] text-ink-on-paper-3">
          Need help?{" "}
          <Link to="/ai" className="inline-flex items-center gap-1 font-semibold text-ink-on-paper-1 underline-offset-2 hover:underline">
            Setup instructions <ArrowRight className="h-3.5 w-3.5" aria-hidden />
          </Link>
        </p>
      </DialogContent>
    </Dialog>
  );
}
