import { useState } from "react";
import Header from "@/components/Header";
import Footer from "@/components/Footer";
import { Button } from "@/components/ui/button";
import { Copy, Check } from "lucide-react";
import { API_BASE_URL } from "@/lib/api-service";

const MCP_ENDPOINT = `${API_BASE_URL}/api/mcp`;
const DISCOVERY_URL = `${API_BASE_URL}/api/ai`;
const OPENAPI_URL = `${API_BASE_URL}/docs.openapi`;
const DOCS_URL = `${API_BASE_URL}/docs`;

const TOOLS = [
  "list_connected_accounts", "upload_media", "create_post", "list_posts",
  "get_post", "update_post", "delete_post", "list_offers", "create_offer",
  "get_offer", "update_offer", "delete_offer", "create_tracking_link",
  "get_offer_stats", "get_stats_timeseries", "disconnect_account",
  "get_connect_url", "create_feature_request",
];

/** Code block with a copy button, used for every setup snippet. */
const Snippet = ({ id, code, copied, onCopy }: {
  id: string;
  code: string;
  copied: string | null;
  onCopy: (text: string, id: string) => void;
}) => (
  <div className="relative group">
    <pre className="bg-muted rounded-md p-4 pr-12 text-sm font-mono overflow-x-auto whitespace-pre-wrap break-all">{code}</pre>
    <Button
      type="button"
      variant="outline"
      size="icon"
      className="absolute top-2 right-2"
      onClick={() => onCopy(code, id)}
      aria-label="Copy snippet"
    >
      {copied === id ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
    </Button>
  </div>
);

const ConnectAI = () => {
  const [copied, setCopied] = useState<string | null>(null);

  const copy = (text: string, id: string) => {
    navigator.clipboard.writeText(text);
    setCopied(id);
    setTimeout(() => setCopied(null), 1500);
  };

  const Section = ({ title, children }: { title: string; children: React.ReactNode }) => (
    <section className="mb-8">
      <h2 className="text-2xl font-semibold mb-4">{title}</h2>
      {children}
    </section>
  );

  return (
    <div className="min-h-screen bg-background pt-16 flex flex-col">
      <Header />
      <header>
        <div className="container mx-auto px-4 py-4 max-w-4xl mt-5">
          <h1 className="text-3xl font-bold text-foreground">Connect your AI to ViewsMax</h1>
          <p className="text-muted-foreground mt-2 leading-relaxed">
            ViewsMax exposes an MCP server and a REST API so AI assistants can post to
            your connected social accounts, manage offers and tracked links, and read
            your analytics — on your behalf, with your permission. Machine-readable
            version of this page: <a className="underline underline-offset-2" href="/ai.md">/ai.md</a>.
          </p>
        </div>
      </header>

      <main className="container mx-auto px-4 py-8 max-w-4xl flex-1">
        <Section title="Endpoints">
          <ul className="list-disc list-inside text-muted-foreground space-y-2">
            <li>MCP endpoint (Streamable HTTP): <code className="bg-muted px-2 py-1 rounded">{MCP_ENDPOINT}</code></li>
            <li>Capability discovery (JSON): <a className="underline underline-offset-2" href={DISCOVERY_URL}>{DISCOVERY_URL}</a></li>
            <li>REST API reference: <a className="underline underline-offset-2" href={DOCS_URL}>{DOCS_URL}</a> · <a className="underline underline-offset-2" href={OPENAPI_URL}>OpenAPI spec</a></li>
          </ul>
        </Section>

        <Section title="Credentials">
          <p className="text-muted-foreground leading-relaxed mb-4">
            Two options, both sent as a Bearer token:
          </p>
          <ol className="list-decimal list-inside text-muted-foreground space-y-2">
            <li>
              <strong className="text-foreground">OAuth (recommended for chat apps).</strong>{" "}
              Point an MCP client at the endpoint; sign in and approve in the browser.
              The consent screen offers read-only or full access. No key handling.
            </li>
            <li>
              <strong className="text-foreground">API key (for headless agents and scripts).</strong>{" "}
              Settings → AI Assistant Access → generate key (<code className="bg-muted px-1 rounded">vmx_...</code>).
              Shown once; rotating invalidates the old one. The same key works on the
              REST API (posts, offers, tracking, stats — read-only keys are limited to GET).
            </li>
          </ol>
        </Section>

        <Section title="Claude (claude.ai / Desktop)">
          <p className="text-muted-foreground leading-relaxed mb-3">
            Settings → Connectors → Add custom connector → paste the MCP endpoint →
            complete the sign-in approval.
          </p>
          <Snippet id="claude" code={MCP_ENDPOINT} copied={copied} onCopy={copy} />
        </Section>

        <Section title="Claude Code">
          <Snippet
            id="claude-code"
            code={`claude mcp add --transport http viewsmax ${MCP_ENDPOINT}`}
            copied={copied}
            onCopy={copy}
          />
          <p className="text-muted-foreground leading-relaxed mt-3 mb-3">or in <code className="bg-muted px-1 rounded">.mcp.json</code>:</p>
          <Snippet
            id="claude-code-json"
            code={`{ "mcpServers": { "viewsmax": { "type": "http", "url": "${MCP_ENDPOINT}" } } }`}
            copied={copied}
            onCopy={copy}
          />
        </Section>

        <Section title="ChatGPT">
          <p className="text-muted-foreground leading-relaxed">
            Settings → Apps &amp; Connectors → enable Developer mode → add a connector with
            the MCP URL and complete OAuth. ViewsMax is an <em>action</em> connector
            (create/schedule posts, read stats) — use it from regular chats with
            connectors enabled.
          </p>
        </Section>

        <Section title="Cursor">
          <p className="text-muted-foreground leading-relaxed mb-3"><code className="bg-muted px-1 rounded">.cursor/mcp.json</code>:</p>
          <Snippet
            id="cursor"
            code={`{ "mcpServers": { "viewsmax": { "url": "${MCP_ENDPOINT}", "headers": { "Authorization": "Bearer vmx_YOUR_KEY" } } } }`}
            copied={copied}
            onCopy={copy}
          />
        </Section>

        <Section title="OpenClaw">
          <p className="text-muted-foreground leading-relaxed">
            Download the ViewsMax skill from{" "}
            <a className="underline underline-offset-2" href="/skills/viewsmax/SKILL.md">/skills/viewsmax/SKILL.md</a>{" "}
            into your agent's skills directory and set{" "}
            <code className="bg-muted px-1 rounded">VIEWSMAX_API_KEY</code> in its environment.
            The skill drives the REST API; alternatively point OpenClaw's MCP support at the
            endpoint above.
          </p>
        </Section>

        <Section title="Hermes Agent">
          <Snippet
            id="hermes"
            code={`{ "viewsmax": { "transport": "http", "url": "${MCP_ENDPOINT}", "headers": { "Authorization": "Bearer vmx_YOUR_KEY" } } }`}
            copied={copied}
            onCopy={copy}
          />
        </Section>

        <Section title="Plain REST / curl">
          <Snippet
            id="curl"
            code={`curl -H "Authorization: Bearer vmx_YOUR_KEY" ${API_BASE_URL}/api/posts`}
            copied={copied}
            onCopy={copy}
          />
          <p className="text-muted-foreground leading-relaxed mt-3">
            Responses use a <code className="bg-muted px-1 rounded">{"{ success, message, data }"}</code> envelope.
            Full reference: <a className="underline underline-offset-2" href={DOCS_URL}>{DOCS_URL}</a>.
          </p>
        </Section>

        <Section title="What agents can do (18 MCP tools)">
          <div className="flex flex-wrap gap-2 mb-4">
            {TOOLS.map((t) => (
              <code key={t} className="bg-muted px-2 py-1 rounded text-xs">{t}</code>
            ))}
          </div>
          <p className="text-muted-foreground leading-relaxed">
            Typical posting flow: <code className="bg-muted px-1 rounded text-xs">list_connected_accounts</code> →{" "}
            <code className="bg-muted px-1 rounded text-xs">upload_media</code> →{" "}
            <code className="bg-muted px-1 rounded text-xs">create_post</code> (draft / posted / scheduled) →
            publishing is asynchronous, so poll{" "}
            <code className="bg-muted px-1 rounded text-xs">get_post</code> for per-platform results.
          </p>
        </Section>

        <Section title="Security & limits">
          <ul className="list-disc list-inside text-muted-foreground space-y-2">
            <li>Read-only credentials cannot write, anywhere.</li>
            <li>Every AI tool call is recorded in your audit log (Settings → AI Assistant Access).</li>
            <li>Rate limits: 120 MCP requests/min per token; 180 create_post/hour; 40 upload_media/hour.</li>
            <li>Rotate your API key any time to revoke access instantly.</li>
          </ul>
        </Section>
      </main>
      <Footer />
    </div>
  );
};

export default ConnectAI;
