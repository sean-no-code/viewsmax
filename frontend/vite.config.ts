import { defineConfig, loadEnv, type Plugin } from "vite";
import react from "@vitejs/plugin-react-swc";
import path from "path";
import { componentTagger } from "lovable-tagger";
import { AGENT_LIST, briefPath, buildAgentMarkdown } from "./src/lib/agent-pages";
import { MCP_MD_PATH, buildMcpMarkdown } from "./src/lib/mcp-page";

// Serves the markdown twins (/claude.md, ..., /mcp.md) in dev. The production build writes
// these files in scripts/prerender-agents.ts from the same builders.
const markdownPages = (env: Record<string, string>): Plugin => ({
  name: "viewsmax-markdown",
  configureServer(server) {
    server.middlewares.use((req, res, next) => {
      const path = req.url?.split("?")[0];
      const agent = AGENT_LIST.find((a) => path === briefPath(a));
      const body = agent ? buildAgentMarkdown(agent, env) : path === MCP_MD_PATH ? buildMcpMarkdown() : null;
      if (body === null) return next();
      res.setHeader("Content-Type", "text/markdown; charset=utf-8");
      res.end(body);
    });
  },
});

// https://vitejs.dev/config/
export default defineConfig(({ mode }) => {
  // Load env file based on `mode` in the current working directory.
  // Set the third parameter to '' to load all env regardless of the `VITE_` prefix.
  const env = loadEnv(mode, process.cwd(), '');

  return {
    base: '/',
    server: {
      host: "::",
      port: 8080,
      // Allow serving the dev app through an ngrok tunnel (for OAuth testing).
      allowedHosts: [".ngrok-free.app", "oxalic-carry-interparenthetically.ngrok-free.dev"],
      // Forward backend paths to the API so the browser only ever talks to this
      // origin. Needed when the app is served through a public tunnel: Chrome
      // blocks requests from a public https page to http://localhost (reported
      // as a CORS error), so VITE_API_BASE_URL is left empty in dev and the
      // backend is reached via this proxy instead. Target is overridable for
      // Docker, where the API is reachable as http://app:8000, not localhost.
      proxy: Object.fromEntries(
        ["/api", "/storage", "/docs", "/tracker.js"].map((prefix) => [
          prefix,
          { target: env.VITE_DEV_PROXY_TARGET || "http://localhost:8000", changeOrigin: true },
        ]),
      ),
    },
    plugins: [
      react(),
      markdownPages(env),
      mode === 'development' &&
      componentTagger(),
    ].filter(Boolean),
    resolve: {
      alias: {
        "@": path.resolve(__dirname, "./src"),
      },
    },
    // Explicitly define env file loading order for development
    envDir: mode === 'development' ? '.' : undefined,
    envPrefix: ['VITE_'],
  };
});
