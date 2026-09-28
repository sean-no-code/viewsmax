import { defineConfig, loadEnv, type Plugin } from "vite";
import react from "@vitejs/plugin-react-swc";
import path from "path";
import { componentTagger } from "lovable-tagger";
import { AGENT_LIST, briefPath, buildAgentMarkdown } from "./src/lib/agent-pages";

// Serves each agent setup brief (/claude.md, ...) in dev. The production build writes these files in
// scripts/prerender-seo.ts from the same buildAgentMarkdown().
const agentMarkdown = (env: Record<string, string>): Plugin => ({
  name: "viewsmax-agent-markdown",
  configureServer(server) {
    server.middlewares.use((req, res, next) => {
      const agent = AGENT_LIST.find((a) => req.url?.split("?")[0] === briefPath(a));
      if (!agent) return next();
      res.setHeader("Content-Type", "text/markdown; charset=utf-8");
      res.end(buildAgentMarkdown(agent, env));
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
    },
    plugins: [
      react(),
      agentMarkdown(env),
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
