import { describe, it, expect, afterEach, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import AgentPage from "@/components/agents/AgentPage";

const renderPage = (agent: Parameters<typeof AgentPage>[0]["agent"]) =>
  render(
    <MemoryRouter>
      <AgentPage agent={agent} />
    </MemoryRouter>,
  );

afterEach(() => vi.unstubAllEnvs());

describe("AgentPage", () => {
  it("shows the manual connector steps and no directory button before ViewsMax is listed", () => {
    vi.stubEnv("VITE_CLAUDE_CONNECTOR_URL", "");
    renderPage("claude");

    expect(screen.getByRole("heading", { level: 1 })).toHaveTextContent("Claude");
    expect(screen.queryByRole("link", { name: /add to claude/i })).toBeNull();
    expect(screen.getAllByText(/add custom connector/i).length).toBeGreaterThan(0);
    expect(screen.queryByText(/set it up by hand/i)).toBeNull();
  });

  it("leads with the directory button and tucks the manual steps away once listed", () => {
    const url = "https://claude.ai/directory/connectors/viewsmax";
    vi.stubEnv("VITE_CLAUDE_CONNECTOR_URL", url);
    renderPage("claude");

    const buttons = screen.getAllByRole("link", { name: /add to claude/i });
    expect(buttons[0]).toHaveAttribute("href", url);
    expect(screen.getByText(/set it up by hand/i)).toBeInTheDocument();
  });

  it("leads Codex with the plugin directory once the ChatGPT/Codex listing is set", () => {
    const url = "https://chatgpt.com/plugins/viewsmax";
    vi.stubEnv("VITE_CHATGPT_PLUGIN_URL", url);
    renderPage("codex");

    expect(screen.getAllByRole("link", { name: /plugin directory/i })[0]).toHaveAttribute("href", url);
    expect(screen.getAllByText("/plugins").length).toBeGreaterThan(0);
    expect(screen.getByText(/set it up by hand/i)).toBeInTheDocument();
  });

  it("tells Claude Code users a claude.ai connector already carries over", () => {
    vi.stubEnv("VITE_CLAUDE_CONNECTOR_URL", "");
    renderPage("claude-code");
    expect(screen.getByText(/already connected ViewsMax in Claude/i)).toBeInTheDocument();
  });

  it("explains what happens after the first task and links back to the AI agents hub", () => {
    renderPage("claude");
    expect(screen.getByText(/what happens next/i)).toBeInTheDocument();
    expect(screen.getByText(/example reply/i)).toBeInTheDocument();
    const crumbs = screen.getByRole("navigation", { name: /breadcrumb/i });
    expect(within(crumbs).getByRole("link", { name: /ai agents/i })).toHaveAttribute("href", "/ai");
  });

  it("links to the other agent pages", () => {
    renderPage("cursor");
    const section = screen.getByRole("heading", { name: /using a different ai agent/i }).closest("section")!;
    expect(within(section).getByRole("link", { name: /claude code/i })).toHaveAttribute("href", "/claude-code");
    expect(within(section).queryByRole("link", { name: /^cursor/i })).toBeNull();
  });
});
