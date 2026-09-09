// Shown whenever the Instagram account lacks the comments + messages
// permissions (or its token died). Re-runs the normal connect flow, which
// now requests the extra scopes, then refreshes the account directory.
import { useState } from "react";
import { toast } from "sonner";
import { Btn } from "@/components/analytics/primitives";
import { connectSocialPlatform } from "@/lib/oauth-connect";
import { refreshAccountDirectory } from "@/components/post/useAccountDirectory";
import { Notice } from "./ui";

export function ReconnectInstagramNotice({ onReconnected, compact }: { onReconnected?: () => void; compact?: boolean }) {
  const [busy, setBusy] = useState(false);
  const reconnect = async () => {
    setBusy(true);
    try {
      await connectSocialPlatform("instagram");
      refreshAccountDirectory();
      toast.success("Instagram reconnected with comment & message permissions.");
      onReconnected?.();
    } catch (e) {
      toast.error((e as Error).message || "Couldn't reconnect Instagram.");
    } finally {
      setBusy(false);
    }
  };
  return (
    <Notice action={<Btn kind="dark" size="sm" icon="plug" onClick={busy ? undefined : reconnect}>{busy ? "Connecting…" : "Reconnect Instagram"}</Btn>}>
      {compact ? "Reconnect Instagram to allow comment & message permissions." : "Automations need permission to read comments and send messages. Reconnect your Instagram account to grant them — your existing posts and links are unaffected."}
    </Notice>
  );
}
