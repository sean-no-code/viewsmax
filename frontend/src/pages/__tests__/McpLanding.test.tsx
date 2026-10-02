import { describe, expect, it } from "vitest";
import { render, screen, within } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import McpLanding from "@/pages/McpLanding";
import { AGENT_LIST, MCP_ENDPOINT, MCP_TOOLS } from "@/lib/agent-pages";

const renderPage = () =>
  render(
    <MemoryRouter>
      <McpLanding />
    </MemoryRouter>,
  );

const sectionOf = (heading: RegExp) => screen.getByRole("heading", { name: heading }).closest("section")!;

describe("McpLanding", () => {
  it("leads with the MCP server and its address", () => {
    renderPage();
    expect(screen.getByRole("heading", { level: 1 })).toHaveTextContent(/MCP server/i);
    expect(screen.getAllByText(MCP_ENDPOINT).length).toBeGreaterThan(0);
  });

  it("links every agent guide and the install hub", () => {
    renderPage();
    const hrefs = within(sectionOf(/setup guides for each ai agent/i))
      .getAllByRole("link")
      .map((l) => l.getAttribute("href"));
    for (const a of AGENT_LIST) expect(hrefs).toContain(a.slug);
    expect(hrefs).toContain("/ai");
    expect(within(screen.getByRole("navigation", { name: /breadcrumb/i })).getByRole("link", { name: /ai agents/i })).toHaveAttribute("href", "/ai");
  });

  it("names every MCP tool in the tools section", () => {
    renderPage();
    const tools = sectionOf(/which viewsmax mcp tools exist/i);
    for (const t of MCP_TOOLS) expect(within(tools).getByText(t)).toBeInTheDocument();
  });

  it("shows a copyable snippet per client", () => {
    renderPage();
    const connect = sectionOf(/how do i connect an ai agent/i);
    for (const a of AGENT_LIST) expect(within(connect).getByRole("heading", { level: 3, name: a.name })).toBeInTheDocument();
  });
});
