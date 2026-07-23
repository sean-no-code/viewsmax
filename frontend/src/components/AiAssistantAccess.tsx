import { useCallback, useEffect, useState } from "react";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "@/components/ui/tabs";
import {
  Dialog,
  DialogTrigger,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogClose,
} from "@/components/ui/dialog";
import { toast } from "sonner";
import { Copy, Check, KeyRound, RefreshCw, Loader2, TriangleAlert } from "lucide-react";
import { viewsMaxApi, API_BASE_URL } from "@/lib/api-service";

type AccessLevel = "read" | "full";

interface KeyInfo {
  hint: string;
  access: AccessLevel;
  created_at: string;
}

const MCP_ENDPOINT = `${API_BASE_URL}/api/mcp`;

/**
 * Connect an AI assistant to the MCP endpoint. Claude connects via OAuth
 * (paste the endpoint URL, sign in + approve in the browser — no key).
 * Other assistants use a single non-expiring API key, shown in full exactly
 * once right after generation/rotation; afterwards only a masked hint is
 * recoverable — matching the backend, which stores the key hashed. The key UI
 * lives under the "Other assistants" tab so the Claude path never implies a
 * key is needed.
 */
const AiAssistantAccess = () => {
  const [loading, setLoading] = useState(true);
  const [keyInfo, setKeyInfo] = useState<KeyInfo | null>(null);
  const [rotating, setRotating] = useState(false);
  const [revealedKey, setRevealedKey] = useState<string | null>(null);
  const [copied, setCopied] = useState<string | null>(null);
  const [access, setAccess] = useState<AccessLevel>("full");

  const load = useCallback(async () => {
    setLoading(true);
    const res = await viewsMaxApi.getApiKey();
    if (res.success) {
      setKeyInfo(res.data ?? null);
      if (res.data) setAccess(res.data.access);
    }
    setLoading(false);
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const rotate = async () => {
    setRotating(true);
    try {
      const res = await viewsMaxApi.rotateApiKey(access);
      if (!res.success || !res.data) {
        throw new Error(res.error || "Could not generate a key.");
      }
      setRevealedKey(res.data.key);
      setKeyInfo({ hint: res.data.hint, access: res.data.access, created_at: res.data.created_at });
      toast.success("New key generated. Copy it now — it won't be shown again.");
    } catch (e) {
      toast.error(e instanceof Error ? e.message : "Could not generate a key.");
    } finally {
      setRotating(false);
    }
  };

  const copy = async (value: string, label: string) => {
    try {
      await navigator.clipboard.writeText(value);
      setCopied(label);
      setTimeout(() => setCopied((c) => (c === label ? null : c)), 1500);
    } catch {
      toast.error("Couldn't copy to clipboard.");
    }
  };

  const hasKey = keyInfo !== null;

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <KeyRound className="h-4 w-4" />
          AI Assistant Access
        </CardTitle>
        <CardDescription>
          Connect an AI assistant (Claude, Cursor, VS Code, …) to post, track
          offers, and read analytics on your behalf. Most connect with just the
          endpoint URL — you sign in and approve in your browser, no key needed.
          For programmatic use, connect with an API key instead.
        </CardDescription>
      </CardHeader>
      <CardContent>
        <Tabs defaultValue="oauth">
          <TabsList>
            <TabsTrigger value="oauth">Browser sign-in</TabsTrigger>
            <TabsTrigger value="header">API key</TabsTrigger>
          </TabsList>

          {/* Claude — OAuth, no key needed */}
          <TabsContent value="oauth" className="space-y-2 pt-4">
            <div className="text-sm font-medium">Endpoint</div>
            <div className="flex items-center gap-2">
              <Input readOnly value={MCP_ENDPOINT} className="font-mono text-sm" />
              <Button
                type="button"
                variant="outline"
                size="icon"
                onClick={() => copy(MCP_ENDPOINT, "oauth-endpoint")}
                aria-label="Copy MCP endpoint"
              >
                {copied === "oauth-endpoint" ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
              </Button>
            </div>
            <p className="text-sm text-muted-foreground">
              The recommended way to connect, supported by most MCP clients
              (Claude, Cursor, VS Code, Gemini CLI, …). Add a custom MCP
              connector with this URL — the client opens a browser where you
              sign in to ViewsMax and approve access, no API key involved. In
              Claude, that's Settings → Connectors → Add custom connector. You
              can revoke access anytime by removing the connection.
            </p>
          </TabsContent>

          {/* Other assistants — endpoint + API key */}
          <TabsContent value="header" className="space-y-4 pt-4">
            <div className="space-y-2">
              <div className="text-sm font-medium">Endpoint</div>
              <div className="flex items-center gap-2">
                <Input readOnly value={MCP_ENDPOINT} className="font-mono text-sm" />
                <Button
                  type="button"
                  variant="outline"
                  size="icon"
                  onClick={() => copy(MCP_ENDPOINT, "endpoint")}
                  aria-label="Copy MCP endpoint"
                >
                  {copied === "endpoint" ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
                </Button>
              </div>
              <p className="text-sm text-muted-foreground">
                For programmatic use, or clients that take a static auth header
                instead of the sign-in flow: use this URL and send your API key
                as{" "}
                <code className="text-xs">Authorization: Bearer &lt;key&gt;</code>.
                The same key also works on the REST API (posts, offers,
                tracking, stats) — see{" "}
                <a href="/ai" className="underline underline-offset-2">
                  the agent setup guide
                </a>
                .
              </p>
            </div>

            {/* API key */}
            <div className="space-y-2 border-t pt-4">
              <div className="text-sm font-medium">API Key</div>

              {revealedKey ? (
                <div className="space-y-2">
                  <div className="flex items-center gap-2">
                    <Input readOnly value={revealedKey} className="font-mono text-sm" />
                    <Button
                      type="button"
                      variant="outline"
                      size="icon"
                      onClick={() => copy(revealedKey, "key")}
                      aria-label="Copy API key"
                    >
                      {copied === "key" ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
                    </Button>
                  </div>
                  <div className="flex items-start gap-2 text-sm text-amber-600 dark:text-amber-500">
                    <TriangleAlert className="mt-0.5 h-4 w-4 shrink-0" />
                    <span>Copy this key now — for your security it won't be shown again.</span>
                  </div>
                </div>
              ) : loading ? (
                <div className="text-sm text-muted-foreground">Loading…</div>
              ) : hasKey ? (
                <div className="flex items-center justify-between gap-2">
                  <div className="font-mono text-sm text-muted-foreground">{keyInfo!.hint}</div>
                  <div className="text-xs text-muted-foreground">
                    {keyInfo!.access === "full" ? "Full access" : "Read-only"} · Created{" "}
                    {new Date(keyInfo!.created_at).toLocaleDateString()}
                  </div>
                </div>
              ) : (
                <div className="text-sm text-muted-foreground">
                  No key yet. Generate one to connect an AI assistant.
                </div>
              )}
            </div>

            {/* Access level for the next key */}
            <div className="space-y-2">
              <div className="text-sm font-medium">Access</div>
              <div className="flex gap-2">
                <Button
                  type="button"
                  variant={access === "full" ? "default" : "outline"}
                  size="sm"
                  onClick={() => setAccess("full")}
                >
                  Full access
                </Button>
                <Button
                  type="button"
                  variant={access === "read" ? "default" : "outline"}
                  size="sm"
                  onClick={() => setAccess("read")}
                >
                  Read-only
                </Button>
              </div>
              <p className="text-xs text-muted-foreground">
                {access === "full"
                  ? "Can read and make changes — post, edit, delete, and manage offers and connections."
                  : "Can only read — list and view posts, offers, and analytics. It can't make changes."}
                {hasKey && keyInfo!.access !== access && " Applied when you rotate the key below."}
              </p>
            </div>

            {/* Generate / rotate action */}
            <div className="flex items-center justify-between gap-4">
              <div className="text-sm text-muted-foreground">
                {hasKey
                  ? "Rotating creates a new key and immediately invalidates the current one."
                  : "You'll see the full key once, right after generating it."}
              </div>

              {hasKey ? (
                <Dialog>
                  <DialogTrigger asChild>
                    <Button variant="outline" disabled={rotating}>
                      {rotating ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <RefreshCw className="mr-2 h-4 w-4" />}
                      Rotate Key
                    </Button>
                  </DialogTrigger>
                  <DialogContent>
                    <DialogHeader>
                      <DialogTitle>Rotate your API key?</DialogTitle>
                      <DialogDescription>
                        Your current key stops working immediately. Any AI assistant
                        using it will need the new key to keep posting. This can't be
                        undone.
                      </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                      <DialogClose asChild>
                        <Button variant="outline">Cancel</Button>
                      </DialogClose>
                      <DialogClose asChild>
                        <Button onClick={rotate} variant="destructive">
                          Yes, rotate key
                        </Button>
                      </DialogClose>
                    </DialogFooter>
                  </DialogContent>
                </Dialog>
              ) : (
                <Button onClick={rotate} disabled={rotating}>
                  {rotating ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <KeyRound className="mr-2 h-4 w-4" />}
                  Generate Key
                </Button>
              )}
            </div>
          </TabsContent>
        </Tabs>
      </CardContent>
    </Card>
  );
};

export default AiAssistantAccess;
