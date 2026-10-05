import { useEffect, useState } from "react";
import { Link, useLocation } from "react-router-dom";
import { LandingNav, LandingFooter } from "@/pages/landing/LandingChrome";
import { Button } from "@/components/ui/button";
import { Copy, Check } from "lucide-react";
import { API_BASE_URL } from "@/lib/api-service";
import { AGENT_LIST, AGENTS, CLAUDE_DIRECTORY_URL, MCP_TOOLS } from "@/lib/agent-pages";

const MCP_ENDPOINT = `${API_BASE_URL}/api/mcp`;
const DISCOVERY_URL = `${API_BASE_URL}/api/ai`;
const OPENAPI_URL = `${API_BASE_URL}/docs.openapi`;
const DOCS_URL = `${API_BASE_URL}/docs`;

/** "Full guide" link to an agent's own setup page. */
const Guide = ({ slug }: { slug: keyof typeof AGENTS }) => (
  <p className="text-muted-foreground leading-relaxed mt-3">
    <Link className="underline underline-offset-2" to={AGENTS[slug].slug}>Full {AGENTS[slug].name} guide →</Link>
  </p>
);

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
  const { hash } = useLocation();

  useEffect(() => {
    if (hash) document.getElementById(hash.slice(1))?.scrollIntoView({ behavior: "smooth", block: "start" });
  }, [hash]);

  const copy = (text: string, id: string) => {
    navigator.clipboard.writeText(text);
    setCopied(id);
    setTimeout(() => setCopied(null), 1500);
  };

  const Section = ({ id, title, children }: { id?: string; title: string; children: React.ReactNode }) => (
    <section id={id} className="mb-8 scroll-mt-24">
      <h2 className="text-2xl font-semibold mb-4">{title}</h2>
      {children}
    </section>
  );

  return (
    <div className="min-h-screen bg-background flex flex-col">
      <LandingNav />
      <header>
        <div className="container mx-auto px-4 py-4 max-w-4xl mt-5">
          <h1 className="text-3xl font-bold text-foreground">Connect your AI to ViewsMax</h1>
          <p className="text-muted-foreground mt-2 leading-relaxed">
            ViewsMax exposes an MCP server and a REST API so AI assistants can post to
            your connected social accounts, manage offers and tracked links, and read
            your analytics — on your behalf, with your permission. Machine-readable
            version of this page: <a className="underline underline-offset-2" href="/ai.md">/ai.md</a>.
            New to MCP? Start with the <a className="underline underline-offset-2" href="/mcp">MCP server overview</a> —
            what it exposes, how auth works, and every tool grouped by job.
          </p>
        </div>
      </header>

      <main className="container mx-auto px-4 py-8 max-w-4xl flex-1">
        <Section id="agents" title="Pick your AI agent">
          <div className="grid gap-3 sm:grid-cols-2">
            {AGENT_LIST.map((a) => (
              <Link key={a.key} to={a.slug} className="block rounded-md border p-4 hover:border-foreground transition-colors">
                <span className="block font-semibold text-foreground">{a.name}</span>
                <span className="block text-sm text-muted-foreground mt-0.5">{a.card}</span>
              </Link>
            ))}
          </div>
        </Section>

        <Section id="endpoints" title="Endpoints">
          <ul className="list-disc list-inside text-muted-foreground space-y-2">
            <li>MCP endpoint (Streamable HTTP): <code className="bg-muted px-2 py-1 rounded">{MCP_ENDPOINT}</code></li>
            <li>Capability discovery (JSON): <a className="underline underline-offset-2" href={DISCOVERY_URL}>{DISCOVERY_URL}</a></li>
            <li>REST API reference: <a className="underline underline-offset-2" href={DOCS_URL}>{DOCS_URL}</a> · <a className="underline underline-offset-2" href={OPENAPI_URL}>OpenAPI spec</a></li>
          </ul>
        </Section>

        <Section id="credentials" title="Credentials">
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

        <Section id="claude" title="Claude (claude.ai / Desktop)">
          <p className="text-muted-foreground leading-relaxed mb-3">
            Fastest route: <a className="underline underline-offset-2" href={CLAUDE_DIRECTORY_URL} target="_blank" rel="noreferrer">add ViewsMax from the Claude directory</a> — nothing to paste.
            Or add it as a custom connector:
          </p>
          <p className="text-muted-foreground leading-relaxed mb-3">
            Customize → Connectors → + → Add custom connector → paste the MCP endpoint →
            Add → Connect and complete the sign-in approval. The connector also works in
            the mobile apps and Cowork.
          </p>
          <Snippet id="claude" code={MCP_ENDPOINT} copied={copied} onCopy={copy} />
          <Guide slug="claude" />
        </Section>

        <Section id="cli" title="Claude Code">
          <Snippet
            id="claude-code"
            code={`claude mcp add --transport http --scope user viewsmax ${MCP_ENDPOINT}`}
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
          <p className="text-muted-foreground leading-relaxed mt-3">
            Then run <code className="bg-muted px-1 rounded">/mcp</code> inside Claude Code and sign in.
          </p>
          <Guide slug="claude-code" />
        </Section>

        <Section id="chatgpt" title="ChatGPT">
          <p className="text-muted-foreground leading-relaxed">
            Settings → Security and login → turn on Developer mode. Then go to
            chatgpt.com/plugins → + → paste the MCP URL and complete OAuth. ViewsMax is
            an <em>action</em> connector (create/schedule posts, read stats) — use it from
            regular chats.
          </p>
          <Guide slug="chatgpt" />
        </Section>

        <Section id="codex" title="Codex">
          <Snippet
            id="codex"
            code={`codex mcp add viewsmax --url ${MCP_ENDPOINT}\ncodex mcp login viewsmax`}
            copied={copied}
            onCopy={copy}
          />
          <Guide slug="codex" />
        </Section>

        <Section id="cursor" title="Cursor">
          <p className="text-muted-foreground leading-relaxed mb-3"><code className="bg-muted px-1 rounded">.cursor/mcp.json</code>:</p>
          <Snippet
            id="cursor"
            code={`{ "mcpServers": { "viewsmax": { "url": "${MCP_ENDPOINT}", "headers": { "Authorization": "Bearer vmx_YOUR_KEY" } } } }`}
            copied={copied}
            onCopy={copy}
          />
          <Guide slug="cursor" />
        </Section>

        <Section id="openclaw" title="OpenClaw">
          <p className="text-muted-foreground leading-relaxed">
            Download the ViewsMax skill from{" "}
            <a className="underline underline-offset-2" href="/skills/viewsmax/SKILL.md">/skills/viewsmax/SKILL.md</a>{" "}
            into your agent's skills directory and set{" "}
            <code className="bg-muted px-1 rounded">VIEWSMAX_API_KEY</code> in its environment.
            The skill drives the REST API; alternatively point OpenClaw's MCP support at the
            endpoint above.
          </p>
          <Guide slug="openclaw" />
        </Section>

        <Section id="hermes" title="Hermes Agent">
          <p className="text-muted-foreground leading-relaxed mb-3"><code className="bg-muted px-1 rounded">~/.hermes/config.yaml</code>:</p>
          <Snippet
            id="hermes"
            code={`mcp_servers:\n  viewsmax:\n    url: "${MCP_ENDPOINT}"\n    auth: oauth`}
            copied={copied}
            onCopy={copy}
          />
          <Guide slug="hermes" />
        </Section>

        <Section id="rest" title="Plain REST / curl">
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

        <Section id="tools" title={`What agents can do (${MCP_TOOLS.length} MCP tools)`}>
          <div className="flex flex-wrap gap-2 mb-4">
            {MCP_TOOLS.map((t) => (
              <code key={t} className="bg-muted px-2 py-1 rounded text-xs">{t}</code>
            ))}
          </div>
          <p className="text-muted-foreground leading-relaxed">
            Typical posting flow: <code className="bg-muted px-1 rounded text-xs">list_connected_accounts</code> →{" "}
            <code className="bg-muted px-1 rounded text-xs">upload_media</code> →{" "}
            <code className="bg-muted px-1 rounded text-xs">create_post</code> (draft / posted / scheduled) →
            publishing is asynchronous, so poll{" "}
            <code className="bg-muted px-1 rounded text-xs">get_post</code> for per-platform results.
            The same tools grouped by job, with what each needs: <a className="underline underline-offset-2" href="/mcp#tools">/mcp</a>.
          </p>
        </Section>

        <Section id="security" title="Security & limits">
          <ul className="list-disc list-inside text-muted-foreground space-y-2">
            <li>Read-only credentials cannot write, anywhere.</li>
            <li>Every AI tool call is recorded in your audit log (Settings → AI Assistant Access).</li>
            <li>Rate limits: 120 MCP requests/min per token; 180 create_post/hour; 40 upload_media/hour.</li>
            <li>Rotate your API key any time to revoke access instantly.</li>
          </ul>
        </Section>
      </main>
      <LandingFooter />
    </div>
  );
};

export default ConnectAI;
