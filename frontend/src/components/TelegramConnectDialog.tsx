import { useEffect, useRef, useState } from "react";
import { Check, Copy, Loader2 } from "lucide-react";
import { useTranslation } from "react-i18next";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { viewsMaxApi, type TelegramConnectCode } from "@/lib/api-service";

// Telegram has no OAuth: ViewsMax runs one bot. The user adds it to their
// channel or group as an admin and posts a one-time code there; the backend
// sees the message through the bot and links that chat. A chat id typed here
// would let anyone post into anyone's chat, so there is no such field.

const POLL_MS = 3000;
// Stop polling after this long; "Check again" starts another round.
const POLL_FOR_MS = 3 * 60 * 1000;

type Phase = "loading" | "waiting" | "paused" | "failed";

interface Props {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onConnected: () => void;
}

export function TelegramConnectDialog({ open, onOpenChange, onConnected }: Props) {
  const { t } = useTranslation();
  const [code, setCode] = useState<TelegramConnectCode | null>(null);
  const [phase, setPhase] = useState<Phase>("loading");
  // The latest answer from the server: "not seen yet" is a note, anything else a problem to fix.
  const [note, setNote] = useState<{ text: string; problem: boolean } | null>(null);
  const [copied, setCopied] = useState(false);
  const pollUntil = useRef(0);
  const timer = useRef<number | null>(null);
  // Bumped on every start/stop: an answer from an older round (the dialog was
  // closed, or a new code was taken) must not schedule another check.
  const round = useRef(0);

  const stopPolling = () => {
    round.current += 1;
    if (timer.current !== null) window.clearTimeout(timer.current);
    timer.current = null;
  };

  const expired = (c: TelegramConnectCode | null) => !!c && new Date(c.expires_at).getTime() <= Date.now();

  const poll = async (current: TelegramConnectCode, myRound: number) => {
    if (myRound !== round.current) return;
    if (expired(current)) {
      setPhase("paused");
      setNote({ text: t("connections.telegram.codeExpired", "This code has expired. Get a new one."), problem: true });
      return;
    }

    const res = await viewsMaxApi.connectTelegram(current.code);
    if (myRound !== round.current) return;
    if (res.success) {
      stopPolling();
      onConnected();
      return;
    }
    // Pending is the normal "keep waiting"; a refusal (e.g. the bot isn't an
    // admin yet) stays on screen and polling goes on, so fixing it in
    // Telegram is enough — no need to press anything here.
    setNote(res.pending
      ? { text: t("connections.telegram.waitingNote", "Waiting for the code to appear in your channel or group…"), problem: false }
      : { text: res.error || t("connections.telegram.connectFailed", "Failed to connect Telegram."), problem: true });

    if (Date.now() >= pollUntil.current) {
      setPhase("paused");
      return;
    }
    timer.current = window.setTimeout(() => poll(current, myRound), POLL_MS);
  };

  const startPolling = (current: TelegramConnectCode) => {
    stopPolling();
    const myRound = round.current;
    pollUntil.current = Date.now() + POLL_FOR_MS;
    setPhase("waiting");
    timer.current = window.setTimeout(() => poll(current, myRound), POLL_MS);
  };

  const newCode = async () => {
    stopPolling();
    setPhase("loading");
    setNote(null);
    setCopied(false);
    const myRound = round.current;
    const res = await viewsMaxApi.getTelegramConnectCode();
    if (myRound !== round.current) return;
    if (!res.success || !res.data) {
      setPhase("failed");
      setNote({ text: res.error || t("connections.telegram.codeFailed", "Couldn't get a code. Try again."), problem: true });
      return;
    }
    setCode(res.data);
    startPolling(res.data);
  };

  useEffect(() => {
    if (open) newCode();
    else stopPolling();
    return stopPolling;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const copy = async () => {
    if (!code) return;
    try {
      await navigator.clipboard.writeText(code.command);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2000);
    } catch {
      // Clipboard blocked: the command is on screen to select by hand.
    }
  };

  const bot = code ? `@${code.bot_username}` : "";

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t("connections.telegram.title", "Connect Telegram")}</DialogTitle>
          <DialogDescription>
            {t("connections.telegram.description", "Publish to a Telegram channel or group through the ViewsMax bot. Prove the chat is yours by posting a one-time code in it.")}
          </DialogDescription>
        </DialogHeader>

        {phase === "loading" ? (
          <div className="flex items-center justify-center py-6">
            <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
          </div>
        ) : code ? (
          <ol className="list-decimal space-y-3 pl-5 text-sm">
            <li>
              {t("connections.telegram.stepAddBot", "Add {{bot}} to your channel or group as an administrator. In a channel, turn on its \"Post messages\" right.", { bot })}{" "}
              <a href={code.bot_url} target="_blank" rel="noopener noreferrer" className="font-medium underline">
                {t("connections.telegram.openBot", "Open {{bot}}", { bot })}
              </a>
            </li>
            <li>
              {t("connections.telegram.stepPost", "Post this message in that channel or group:")}
              <div className="mt-2 flex items-center gap-2">
                <code className="flex-1 select-all rounded-md bg-muted px-3 py-2 font-mono text-sm">{code.command}</code>
                <Button variant="outline" size="sm" onClick={copy} aria-label={t("connections.telegram.copy", "Copy")}>
                  {copied ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
                </Button>
              </div>
            </li>
            <li>{t("connections.telegram.stepWait", "Come back here. ViewsMax spots the message, links the chat and removes the message. The code works for 15 minutes.")}</li>
          </ol>
        ) : null}

        {note && (
          <p className={`flex items-start gap-2 text-sm ${note.problem ? "text-amber-600" : "text-muted-foreground"}`} role="status">
            {phase === "waiting" && !note.problem && <Loader2 className="mt-0.5 h-4 w-4 shrink-0 animate-spin" />}
            <span>{note.text}</span>
          </p>
        )}
        {phase === "waiting" && !note && (
          <p className="flex items-center gap-2 text-sm text-muted-foreground" role="status">
            <Loader2 className="h-4 w-4 animate-spin" />
            {t("connections.telegram.waitingNote", "Waiting for the code to appear in your channel or group…")}
          </p>
        )}
        {phase === "paused" && !expired(code) && (
          <p className="text-sm text-muted-foreground">
            {t("connections.telegram.paused", "Stopped checking. Posted the code? Press Check again.")}
          </p>
        )}

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>{t("connections.telegram.cancel", "Cancel")}</Button>
          {phase === "failed" || expired(code) ? (
            <Button onClick={newCode}>{t("connections.telegram.newCode", "Get a new code")}</Button>
          ) : phase === "paused" && code ? (
            <Button onClick={() => startPolling(code)}>{t("connections.telegram.checkAgain", "Check again")}</Button>
          ) : null}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
