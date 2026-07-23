import { useEffect, useState } from "react";
import { Loader2, Check, X as XIcon, AlertTriangle, RefreshCw } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  viewsMaxApi,
  accountNeedsReconnect,
  type Connection,
  type OAuthProvider,
  type SocialAccount,
  type SocialPlatformInfo,
} from "@/lib/api-service";
import { BrandIcon } from "@/components/post/brand-icons";
import { PMAP, PAvatar } from "@/components/post/composer";
import { refreshAccountDirectory } from "@/components/post/useAccountDirectory";
import { BeehiivIntegration } from "@/components/BeehiivIntegration";
import {
  connectProvider,
  connectSocialPlatform,
  isProviderConfigured,
  PROVIDER_CONFIG,
} from "@/lib/oauth-connect";
import { isMockApi } from "@/lib/mock-api";
import { toast } from "sonner";

interface ConnectAccountsProps {
  mode: "onboarding" | "settings";
  onAnyConnected?: () => void;
}

// Each platform lists/renders through one of two backend systems:
//  - "legacy": the Connection store (`/api/connections`).
//  - "social": the SocialAccount store (`/api/social/*`) — multi-account.
// `connectVia` overrides the CONNECT method independently of listing: YouTube
// lists as multi-account (social) but still connects through the legacy
// YouTube-OAuth flow, which also populates the channels/analytics data the
// legacy `social` provider path wouldn't.
type ConnectSystem = "legacy" | "social";

interface ProviderEntry {
  key: string;
  system: ConnectSystem;
  connectVia?: ConnectSystem;
}

const PROVIDERS: ProviderEntry[] = [
  { key: "tiktok", system: "social" },
  { key: "instagram", system: "social" },
  { key: "x", system: "social" },
  { key: "linkedin", system: "social" },
  { key: "threads", system: "social" },
  { key: "facebook", system: "social" },
  { key: "bluesky", system: "social" },
  { key: "youtube", system: "social", connectVia: "legacy" },
];

const labelFor = (key: string): string =>
  (PROVIDER_CONFIG as Record<string, { label: string }>)[key]?.label || PMAP[key]?.name || key;

const ConnectAccounts = ({ mode, onAnyConnected }: ConnectAccountsProps) => {
  const [connections, setConnections] = useState<Connection[]>([]);
  const [accounts, setAccounts] = useState<SocialAccount[]>([]);
  const [platforms, setPlatforms] = useState<SocialPlatformInfo[]>([]);
  const [loading, setLoading] = useState(true);
  const [busyKey, setBusyKey] = useState<string | null>(null);
  const [blueskyOpen, setBlueskyOpen] = useState(false);
  const [blueskyId, setBlueskyId] = useState("");
  const [blueskyPassword, setBlueskyPassword] = useState("");

  const loadAll = async () => {
    const [legacy, social, catalog] = await Promise.all([
      viewsMaxApi.getConnections(),
      viewsMaxApi.getSocialAccounts(),
      viewsMaxApi.getSocialPlatforms(),
    ]);
    if (legacy.success && legacy.data) setConnections(legacy.data);
    if (social.success && social.data) setAccounts(social.data);
    if (catalog.success && catalog.data) setPlatforms(catalog.data);
    setLoading(false);
  };

  useEffect(() => {
    loadAll();
  }, []);

  const legacyConn = (key: string) => connections.find((c) => c.provider === key);
  const socialAcct = (key: string) => accounts.find((a) => a.platform === key);

  const isConnected = (e: ProviderEntry) =>
    e.system === "legacy" ? !!legacyConn(e.key) : !!socialAcct(e.key);

  // A social account with an expired/revoked token exists but can't publish —
  // it must show as "needs reconnecting", never as a green "Connected".
  const needsReconnect = (e: ProviderEntry): boolean => {
    if (e.system !== "social") return false; // legacy store has no token status yet
    const a = socialAcct(e.key);
    return !!a && accountNeedsReconnect(a);
  };

  const accountName = (e: ProviderEntry): string => {
    if (e.system === "legacy") return legacyConn(e.key)?.account_name ?? "";
    const a = socialAcct(e.key);
    return a?.name || (a?.username ? `@${a.username}` : "");
  };

  const accountAvatar = (e: ProviderEntry): string | null => {
    if (e.system === "legacy") return legacyConn(e.key)?.avatar_url || null;
    return socialAcct(e.key)?.avatar_url || null;
  };

  const isConfigured = (e: ProviderEntry): boolean => {
    if (e.system === "legacy") return isProviderConfigured(e.key as OAuthProvider) || isMockApi();
    return platforms.find((p) => p.platform === e.key)?.configured ?? true;
  };

  const handleConnect = async (e: ProviderEntry) => {
    // Bluesky has no OAuth — it connects with a handle + app password.
    if (e.key === "bluesky") {
      setBlueskyOpen(true);
      return;
    }
    setBusyKey(e.key);
    try {
      // Connect method can differ from the listing system (see ProviderEntry).
      if ((e.connectVia ?? e.system) === "legacy") await connectProvider(e.key as OAuthProvider);
      else await connectSocialPlatform(e.key);
      await loadAll();
      refreshAccountDirectory();
      toast.success(`${labelFor(e.key)} connected!`);
      onAnyConnected?.();
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Failed to connect account.");
    } finally {
      setBusyKey(null);
    }
  };

  const handleBlueskyConnect = async () => {
    if (!blueskyId.trim() || !blueskyPassword.trim()) {
      toast.error("Enter your Bluesky handle and an app password.");
      return;
    }
    setBusyKey("bluesky");
    const res = await viewsMaxApi.connectBlueskyAccount(blueskyId.trim(), blueskyPassword.trim());
    setBusyKey(null);
    if (!res.success) {
      toast.error(res.error || "Failed to connect Bluesky.");
      return;
    }
    setBlueskyOpen(false);
    setBlueskyId("");
    setBlueskyPassword("");
    await loadAll();
    refreshAccountDirectory();
    toast.success("Bluesky connected!");
    onAnyConnected?.();
  };

  const disconnectSocialAccountRow = async (e: ProviderEntry, accountId: number) => {
    setBusyKey(`${e.key}:${accountId}`);
    const result = await viewsMaxApi.disconnectSocialAccount(accountId);
    if (result.success) {
      await loadAll();
      refreshAccountDirectory();
      toast.success(`${labelFor(e.key)} account disconnected.`);
    } else {
      toast.error(result.error || "Failed to disconnect.");
    }
    setBusyKey(null);
  };

  const handleDisconnect = async (e: ProviderEntry) => {
    setBusyKey(e.key);
    const result =
      e.system === "legacy"
        ? await viewsMaxApi.disconnectConnection(legacyConn(e.key)!.id)
        : await viewsMaxApi.disconnectSocialAccount(socialAcct(e.key)!.id);
    if (result.success) {
      await loadAll();
      refreshAccountDirectory();
      toast.success(`${labelFor(e.key)} disconnected.`);
    } else {
      toast.error(result.error || "Failed to disconnect.");
    }
    setBusyKey(null);
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center py-6">
        <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
      </div>
    );
  }

  return (
    // Two-column grid in BOTH modes so all providers stay above the fold —
    // the settings page grew too tall as providers/accounts were added.
    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
      {PROVIDERS.map((entry) => {
        // Social-store platforms can hold several accounts — render one card
        // per provider with a sub-row per connected account + "Add account".
        if (entry.system === "social") {
          const accts = accounts.filter((a) => a.platform === entry.key);
          const configured = isConfigured(entry);
          const isBusy = busyKey === entry.key;
          const accent = PMAP[entry.key]?.accent ?? "#0A0A0C";

          return (
            <div key={entry.key} className="rounded-lg border p-3">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-3">
                  <span className="grid h-9 w-9 place-items-center rounded-full" style={{ background: accent }}>
                    <BrandIcon platform={entry.key} size={18} color="#fff" />
                  </span>
                  <div>
                    <p className="font-medium">{labelFor(entry.key)}</p>
                    {accts.length === 0 && (
                      <p className="text-sm text-muted-foreground">
                        {configured ? "Not connected" : "Coming soon"}
                      </p>
                    )}
                  </div>
                </div>
                <Button size="sm" disabled={!configured || isBusy} onClick={() => handleConnect(entry)}>
                  {isBusy ? <Loader2 className="h-4 w-4 animate-spin" /> : accts.length ? "Add account" : "Connect"}
                </Button>
              </div>
              {accts.length > 0 && (
                <div className="mt-3 space-y-2">
                  {accts.map((a) => {
                    const stale = accountNeedsReconnect(a);
                    const rowBusy = busyKey === `${entry.key}:${a.id}`;
                    return (
                      <div key={a.id} className="flex items-center justify-between rounded-md bg-muted/40 px-3 py-1.5">
                        <div className="flex min-w-0 items-center gap-2">
                          <PAvatar id={entry.key} size={28} avatarUrl={a.avatar_url} />
                          <span className="truncate text-sm">{a.name || (a.username ? `@${a.username}` : `Account ${a.id}`)}</span>
                          {stale ? (
                            <span className="flex items-center gap-1 text-xs text-amber-600">
                              <AlertTriangle className="h-3 w-3" /> expired
                            </span>
                          ) : (
                            <Check className="h-3.5 w-3.5 text-green-600" />
                          )}
                        </div>
                        <div className="flex items-center gap-2">
                          {stale && (
                            <Button size="sm" disabled={rowBusy || isBusy} onClick={() => handleConnect(entry)}>
                              <RefreshCw className="h-4 w-4" />
                              <span className="ml-1">Reconnect</span>
                            </Button>
                          )}
                          {mode === "settings" && (
                            <Button variant="outline" size="sm" disabled={rowBusy} onClick={() => disconnectSocialAccountRow(entry, a.id)}>
                              {rowBusy ? <Loader2 className="h-4 w-4 animate-spin" /> : <XIcon className="h-4 w-4" />}
                            </Button>
                          )}
                        </div>
                      </div>
                    );
                  })}
                </div>
              )}
              {entry.key === "tiktok" && (
                <p className="mt-2 text-xs text-muted-foreground">
                  TikTok doesn't show an account picker. To add a <em>different</em> account, sign out of
                  TikTok (or open a private/incognito window) first, then click Add&nbsp;account.
                </p>
              )}
            </div>
          );
        }

        const connected = isConnected(entry);
        const broken = needsReconnect(entry);
        const configured = isConfigured(entry);
        const isBusy = busyKey === entry.key;
        const accent = PMAP[entry.key]?.accent ?? "#0A0A0C";

        return (
          <div
            key={entry.key}
            className="flex items-center justify-between rounded-lg border p-3"
          >
            <div className="flex items-center gap-3">
              {connected && accountAvatar(entry) ? (
                <PAvatar id={entry.key} size={36} avatarUrl={accountAvatar(entry)} />
              ) : (
                <span
                  className="grid h-9 w-9 place-items-center rounded-full"
                  style={{ background: accent }}
                >
                  <BrandIcon platform={entry.key} size={18} color="#fff" />
                </span>
              )}
              <div>
                <p className="font-medium">{labelFor(entry.key)}</p>
                {broken ? (
                  <p className="flex items-center gap-1 text-sm text-amber-600">
                    <AlertTriangle className="h-3 w-3" />
                    Authorization expired — reconnect to keep posting
                  </p>
                ) : connected ? (
                  <p className="flex items-center gap-1 text-sm text-green-600">
                    <Check className="h-3 w-3" />
                    {accountName(entry)}
                  </p>
                ) : (
                  <p className="text-sm text-muted-foreground">
                    {configured ? "Not connected" : "Coming soon"}
                  </p>
                )}
              </div>
            </div>

            {broken ? (
              <div className="flex items-center gap-2">
                <Button size="sm" disabled={isBusy} onClick={() => handleConnect(entry)}>
                  {isBusy ? <Loader2 className="h-4 w-4 animate-spin" /> : <RefreshCw className="h-4 w-4" />}
                  <span className="ml-1">Reconnect</span>
                </Button>
                {mode === "settings" && (
                  <Button variant="outline" size="sm" disabled={isBusy} onClick={() => handleDisconnect(entry)}>
                    <XIcon className="h-4 w-4" />
                  </Button>
                )}
              </div>
            ) : connected ? (
              mode === "settings" ? (
                <Button
                  variant="outline"
                  size="sm"
                  disabled={isBusy}
                  onClick={() => handleDisconnect(entry)}
                >
                  {isBusy ? <Loader2 className="h-4 w-4 animate-spin" /> : <XIcon className="h-4 w-4" />}
                  <span className="ml-1">Disconnect</span>
                </Button>
              ) : (
                <span className="flex items-center gap-1 text-sm font-medium text-green-600">
                  <Check className="h-4 w-4" /> Connected
                </span>
              )
            ) : (
              <Button
                size="sm"
                disabled={!configured || isBusy}
                onClick={() => handleConnect(entry)}
              >
                {isBusy ? <Loader2 className="h-4 w-4 animate-spin" /> : "Connect"}
              </Button>
            )}
          </div>
        );
      })}

      {/* Beehiiv — API-key connection (not OAuth). Clicking Connect prompts for
          the key, which is validated + stored encrypted server-side. */}
      <div className="rounded-lg border p-3">
        <BeehiivIntegration />
      </div>

      {/* Bluesky connects with a handle + app password, not OAuth. */}
      <Dialog open={blueskyOpen} onOpenChange={setBlueskyOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Connect Bluesky</DialogTitle>
            <DialogDescription>
              Enter your handle and an app password. Create one under
              Settings&nbsp;→&nbsp;Privacy and security&nbsp;→&nbsp;App passwords on bsky.app —
              never your main account password.
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-3">
            <Input
              placeholder="you.bsky.social"
              value={blueskyId}
              onChange={(e) => setBlueskyId(e.target.value)}
              autoFocus
            />
            <Input
              placeholder="app password (xxxx-xxxx-xxxx-xxxx)"
              type="password"
              value={blueskyPassword}
              onChange={(e) => setBlueskyPassword(e.target.value)}
            />
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setBlueskyOpen(false)}>Cancel</Button>
            <Button disabled={busyKey === "bluesky"} onClick={handleBlueskyConnect}>
              {busyKey === "bluesky" ? <Loader2 className="h-4 w-4 animate-spin" /> : "Connect"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
};

export default ConnectAccounts;
