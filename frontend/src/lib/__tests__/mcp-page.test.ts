import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";
import { AGENT_LIST, MCP_ENDPOINT, MCP_TOOLS } from "@/lib/agent-pages";
import { STATIC_PAGE_SEO } from "@/lib/static-page-seo";
import {
  CAPABILITIES,
  CLIENT_MATRIX,
  MCP_FAQ,
  MCP_MD_PATH,
  MCP_PAGE_SEO,
  MCP_PATH,
  TOOL_GROUPS,
  buildMcpJsonLd,
  buildMcpMarkdown,
  clientSnippet,
} from "@/lib/mcp-page";

const pub = (name: string) => readFileSync(join(process.cwd(), "public", name), "utf8");

describe("TOOL_GROUPS", () => {
  it("lists every MCP tool exactly once", () => {
    const grouped = TOOL_GROUPS.flatMap((g) => g.tools);
    expect([...grouped].sort()).toEqual([...MCP_TOOLS].sort());
  });

  it("only names real tools in the capability table", () => {
    for (const c of CAPABILITIES) for (const t of c.tools) expect(MCP_TOOLS, c.task).toContain(t);
  });
});

describe("CLIENT_MATRIX", () => {
  it("has a row and a snippet for every agent guide", () => {
    for (const a of AGENT_LIST) {
      expect(CLIENT_MATRIX[a.key], a.name).toBeDefined();
      expect(clientSnippet(a), a.name).toBeDefined();
    }
  });

  it("points every MCP client's snippet at the production endpoint", () => {
    for (const a of AGENT_LIST.filter((x) => x.transport === "mcp")) {
      expect(clientSnippet(a)!.text, a.name).toContain(MCP_ENDPOINT);
    }
  });
});

describe("MCP_FAQ", () => {
  it("is long enough to be worth a FAQPage", () => {
    expect(MCP_FAQ.length).toBeGreaterThanOrEqual(12);
  });

  it("never answers an open question with a bare yes or no", () => {
    for (const f of MCP_FAQ.filter((x) => /^(what|how|which|why)\b/i.test(x.q))) {
      expect(f.a, f.q).not.toMatch(/^(yes|no)\b/i);
    }
  });

  it("is honest about what the server does not do", () => {
    expect(MCP_FAQ.find((f) => /generate videos or images/i.test(f.q))!.a).toMatch(/^no\b/i);
    expect(MCP_FAQ.find((f) => /sse or stdio/i.test(f.q))!.a).toMatch(/^no\b/i);
    expect(MCP_FAQ.find((f) => /resources or prompts/i.test(f.q))!.a).toMatch(/^no\b/i);
  });
});

describe("MCP_PAGE_SEO", () => {
  it("fits a search snippet", () => {
    expect(MCP_PAGE_SEO.title.length).toBeLessThanOrEqual(60);
    expect(MCP_PAGE_SEO.description.length).toBeLessThanOrEqual(155);
    expect(MCP_PAGE_SEO.keywords).toContain("mcp");
  });

  it("is prerendered once, by prerender-agents.ts, not twice", () => {
    expect(STATIC_PAGE_SEO.map((p) => p.path)).not.toContain(MCP_PATH);
    expect(AGENT_LIST.map((a) => a.slug)).not.toContain(MCP_PATH);
  });
});

describe("discoverability files", () => {
  it("sitemap lists the page and its markdown twin", () => {
    const sitemap = pub("sitemap-pages.xml");
    expect(sitemap).toContain(`<loc>https://viewsmax.com${MCP_PATH}</loc>`);
    expect(sitemap).toContain(`<loc>https://viewsmax.com${MCP_MD_PATH}</loc>`);
  });

  it("llms.txt points AI crawlers at the markdown twin and every agent guide", () => {
    for (const name of ["llms.txt", "llms-full.txt"]) {
      const text = pub(name);
      expect(text, name).toContain(`https://viewsmax.com${MCP_MD_PATH}`);
      for (const a of AGENT_LIST) expect(text, `${name}: ${a.name}`).toContain(`https://viewsmax.com${a.slug}.md`);
    }
  });
});

describe("buildMcpJsonLd", () => {
  it("emits the software, FAQ, how-to and breadcrumb objects", () => {
    const types = buildMcpJsonLd().map((d) => d["@type"]);
    expect(types).toEqual(["SoftwareApplication", "FAQPage", "HowTo", "BreadcrumbList"]);
    const faq = buildMcpJsonLd().find((d) => d["@type"] === "FAQPage") as { mainEntity: unknown[] };
    expect(faq.mainEntity).toHaveLength(MCP_FAQ.length);
  });
});

describe("buildMcpMarkdown", () => {
  it("carries the endpoint, every tool and every agent guide", () => {
    const md = buildMcpMarkdown();
    expect(md).toContain(MCP_ENDPOINT);
    for (const t of MCP_TOOLS) expect(md).toContain(`\`${t}\``);
    for (const a of AGENT_LIST) expect(md).toContain(`https://viewsmax.com${a.slug}`);
    for (const f of MCP_FAQ) expect(md).toContain(f.q);
  });
});
