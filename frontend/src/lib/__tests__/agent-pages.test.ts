import { describe, it, expect } from "vitest";
import { readFileSync } from "node:fs";
import { join } from "node:path";
import {
  AGENT_LIST,
  AGENTS,
  CLAUDE_DIRECTORY_URL,
  MCP_ENDPOINT,
  MCP_TOOLS,
  CHECK_PROMPT,
  agentListingUrl,
  briefPath,
  buildAgentMarkdown,
  buildAgentJsonLd,
  PLATFORMS,
} from "@/lib/agent-pages";
import { hasBrandIcon } from "@/components/post/brand-icons";
import { STATIC_PAGE_SEO } from "@/lib/static-page-seo";

const sitemap = readFileSync(join(process.cwd(), "public", "sitemap-pages.xml"), "utf8");

describe("AGENT_LIST", () => {
  it("has a top-level page for every agent ViewsMax documents", () => {
    expect(AGENT_LIST.map((a) => a.slug).sort()).toEqual(
      ["/chatgpt", "/claude", "/claude-code", "/claude-cowork", "/codex", "/cursor", "/hermes", "/openclaw"].sort(),
    );
  });

  it("gives every page real head tags and enough content to be useful", () => {
    for (const a of AGENT_LIST) {
      expect(a.slug).toMatch(/^\/[a-z0-9-]+$/);
      expect(a.seo.title.trim()).not.toBe("");
      expect(a.seo.description.trim()).not.toBe("");
      expect(a.seo.keywords.trim()).not.toBe("");
      expect(a.install.length).toBeGreaterThanOrEqual(2);
      expect(a.faq.length).toBeGreaterThanOrEqual(3);
    }
  });

  it("does not collide with the other prerendered public pages", () => {
    const others = STATIC_PAGE_SEO.map((p) => p.path);
    for (const a of AGENT_LIST) expect(others).not.toContain(a.slug);
  });

  it("points every MCP-based install at the production endpoint", () => {
    for (const a of AGENT_LIST.filter((x) => x.transport === "mcp")) {
      const text = a.install.map((s) => s.code?.text ?? "").join("\n");
      expect(text).toContain(MCP_ENDPOINT);
    }
  });
});

describe("MCP_TOOLS", () => {
  it("matches the tools the backend MCP server registers", () => {
    // Read the $tools array in ViewsMaxServer.php rather than the Tools
    // directory: a tool class can exist while its registration is commented
    // out (hidden from the MCP), and the docs must list only what is served.
    const backend = join(process.cwd(), "..", "backend", "app", "Mcp");
    const server = readFileSync(join(backend, "ViewsMaxServer.php"), "utf8");
    const block = server.match(/public array \$tools = \[([\s\S]*?)\];/)?.[1] ?? "";
    const registered = block
      .split("\n")
      .filter((line) => !/^\s*\/\//.test(line))
      .flatMap((line) => [...line.matchAll(/([A-Za-z]+)::class/g)].map((m) => m[1]));
    expect(registered.length).toBeGreaterThan(0);
    const names = registered.map(
      (cls) => readFileSync(join(backend, "Tools", `${cls}.php`), "utf8").match(/function name\(\): string\s*\{\s*return '([a-z_]+)'/)?.[1],
    );
    expect([...MCP_TOOLS].sort()).toEqual(names.sort());
  });
});

describe("page content", () => {
  it("mentions outlier research in every intro, since the headline promises it", () => {
    for (const a of AGENT_LIST) expect(a.subhead.toLowerCase()).toContain("outlier");
  });

  it("has an example reply for every first task", () => {
    for (const a of AGENT_LIST) expect(a.firstReply.trim()).not.toBe("");
  });

  it("has a brand icon for every platform", () => {
    for (const p of PLATFORMS) expect(hasBrandIcon(p.icon)).toBe(true);
  });

  it("only promises the media each publisher really sends (backend/app/Jobs, Services/Social/Providers)", () => {
    const note = (name: string) => PLATFORMS.find((p) => p.name === name)!.note.toLowerCase();
    for (const name of ["X", "LinkedIn", "Bluesky"]) expect(note(name)).toContain("no video");
    expect(note("X")).toContain("4 images");
    expect(note("Bluesky")).toContain("4 images");
    expect(note("LinkedIn")).toContain("one image");
    expect(note("Threads")).toContain("one image or one video");
    expect(note("Instagram")).toContain("one photo or one reel");
  });

  it("notes Instagram's Business or Creator account requirement", () => {
    expect(PLATFORMS.find((p) => p.name === "Instagram")!.note).toMatch(/business or creator/i);
  });

  it("never answers an open question with a bare yes or no", () => {
    for (const a of AGENT_LIST) {
      for (const f of a.faq.filter((x) => /^(what|how|which|why)\b/i.test(x.q))) {
        expect(f.a, `${a.name}: ${f.q}`).not.toMatch(/^(yes|no)\b/i);
      }
    }
  });

  it("answers 'Can <agent> post to social media?' with a yes", () => {
    for (const a of AGENT_LIST) {
      const f = a.faq.find((x) => /post to social media\?$/.test(x.q))!;
      expect(f.a, a.name).toMatch(/^yes/i);
    }
  });

  it("names every Claude plan where plans matter", () => {
    for (const key of ["claude", "claude-cowork"] as const) {
      const note = AGENTS[key].install.map((s) => s.note ?? "").join(" ");
      for (const plan of ["Free", "Pro", "Max", "Team", "Enterprise"]) expect(note, key).toContain(plan);
    }
    const cc = AGENTS["claude-code"].install.map((s) => s.note ?? "").join(" ") + AGENTS["claude-code"].tip;
    for (const plan of ["Pro", "Max", "Team", "Enterprise"]) expect(cc).toContain(plan);
  });

  it("explains MCP in every FAQ", () => {
    for (const a of AGENT_LIST) expect(a.faq.some((f) => /what is mcp/i.test(f.q))).toBe(true);
  });
});

describe("sitemap-pages.xml", () => {
  it("lists every agent page and its markdown brief", () => {
    for (const a of AGENT_LIST) {
      expect(sitemap).toContain(`<loc>https://viewsmax.com${a.slug}</loc>`);
      expect(sitemap).toContain(`<loc>https://viewsmax.com${briefPath(a)}</loc>`);
    }
  });
});

describe("agentListingUrl", () => {
  it("is fixed for Claude and Claude Code now that ViewsMax is in the Claude directory", () => {
    expect(CLAUDE_DIRECTORY_URL).toBe("https://claude.ai/directory/viewsmax");
    expect(agentListingUrl(AGENTS.claude, {})).toBe(CLAUDE_DIRECTORY_URL);
    expect(agentListingUrl(AGENTS["claude-code"], {})).toBe(CLAUDE_DIRECTORY_URL);
  });

  it("is empty for ChatGPT and Codex until the plugin directory URL is configured", () => {
    expect(agentListingUrl(AGENTS.chatgpt, {})).toBeUndefined();
    expect(agentListingUrl(AGENTS.codex, { VITE_CHATGPT_PLUGIN_URL: "  " })).toBeUndefined();
  });

  it("returns the configured https plugin directory URL for ChatGPT and Codex", () => {
    const url = "https://chatgpt.com/plugins/viewsmax";
    expect(agentListingUrl(AGENTS.chatgpt, { VITE_CHATGPT_PLUGIN_URL: url })).toBe(url);
    expect(agentListingUrl(AGENTS.codex, { VITE_CHATGPT_PLUGIN_URL: url })).toBe(url);
  });

  it("ignores anything that is not an https URL", () => {
    expect(agentListingUrl(AGENTS.chatgpt, { VITE_CHATGPT_PLUGIN_URL: "javascript:alert(1)" })).toBeUndefined();
  });

  it("is always empty for agents with no directory listing", () => {
    expect(agentListingUrl(AGENTS.cursor, { VITE_CHATGPT_PLUGIN_URL: "https://x.test" })).toBeUndefined();
  });
});

describe("first prompts", () => {
  it("lead every agent to outliers, which work before any channel is connected", () => {
    expect(CHECK_PROMPT.toLowerCase()).toContain("outlier");
    for (const agent of Object.values(AGENTS)) {
      expect(agent.firstTask.toLowerCase()).toContain("outlier");
      expect(agent.prompts[0].title).toBe("Borrow what's working");
    }
  });
});

describe("buildAgentMarkdown", () => {
  it("contains the setup commands and the check prompt", () => {
    const md = buildAgentMarkdown(AGENTS["claude-code"], {});
    expect(md).toContain("# ");
    expect(md).toContain(`claude mcp add --transport http --scope user viewsmax ${MCP_ENDPOINT}`);
    expect(md).toContain(CHECK_PROMPT);
  });

  it("always links the Claude directory, and the plugin directory only once configured", () => {
    expect(buildAgentMarkdown(AGENTS.claude, {})).toContain(CLAUDE_DIRECTORY_URL);
    const url = "https://chatgpt.com/plugins/viewsmax";
    expect(buildAgentMarkdown(AGENTS.chatgpt, {})).not.toContain(url);
    expect(buildAgentMarkdown(AGENTS.chatgpt, { VITE_CHATGPT_PLUGIN_URL: url })).toContain(url);
  });
});

describe("listing steps", () => {
  it("tells Codex users to install from /plugins once listed", () => {
    const md = buildAgentMarkdown(AGENTS.codex, { VITE_CHATGPT_PLUGIN_URL: "https://chatgpt.com/plugins/viewsmax" });
    expect(md).toContain("/plugins");
    expect(md).toContain(`codex mcp add viewsmax --url ${MCP_ENDPOINT}`);
  });

  it("tells Claude Code users the claude.ai connector carries over", () => {
    const md = buildAgentMarkdown(AGENTS["claude-code"], {});
    expect(md).toContain(CLAUDE_DIRECTORY_URL);
    expect(md).toMatch(/shares connectors with the claude\.ai account/i);
  });
});

describe("buildAgentJsonLd", () => {
  it("emits HowTo, FAQPage and BreadcrumbList structured data", () => {
    const types = buildAgentJsonLd(AGENTS.chatgpt).map((d) => d["@type"]);
    expect(types).toEqual(expect.arrayContaining(["HowTo", "FAQPage", "BreadcrumbList"]));
  });
});
