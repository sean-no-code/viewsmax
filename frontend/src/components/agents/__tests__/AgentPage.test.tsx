import { describe, it, expect } from "vitest";
import { render, screen, within } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import AgentPage from "@/components/agents/AgentPage";
import { CHATGPT_PLUGIN_URL, CLAUDE_DIRECTORY_URL } from "@/lib/agent-pages";

const renderPage = (agent: Parameters<typeof AgentPage>[0]["agent"]) =>
  render(
    <MemoryRouter>
      <AgentPage agent={agent} />
    </MemoryRouter>,
  );

describe("AgentPage", () => {
  it("leads ChatGPT with an Add to ChatGPT button that opens the plugin page, then the custom connector steps", () => {
    renderPage("chatgpt");

    expect(screen.getByRole("heading", { level: 1 })).toHaveTextContent("ChatGPT");
    const buttons = screen.getAllByRole("link", { name: /add to chatgpt/i });
    expect(buttons[0]).toHaveAttribute("href", CHATGPT_PLUGIN_URL);
    expect(buttons[0]).toHaveAttribute("target", "_blank");
    // The hero no longer points at the manual steps.
    expect(screen.queryByRole("link", { name: /see the setup steps/i })).toBeNull();

    const plugin = screen.getByRole("heading", { name: /plugin \(easiest\)/i });
    const connector = screen.getByRole("heading", { name: /custom connector/i });
    expect(plugin.compareDocumentPosition(connector) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(screen.getByText(/nothing to paste/i)).toBeInTheDocument();
    // Developer mode is still there, as the fallback route only.
    expect(screen.getAllByText(/developer mode/i).length).toBeGreaterThan(0);
  });

  it("shows the plugin route first, with nothing to paste, then the custom connector steps once listed", () => {
    const url = CLAUDE_DIRECTORY_URL;
    renderPage("claude");

    const buttons = screen.getAllByRole("link", { name: /add to claude/i });
    expect(buttons[0]).toHaveAttribute("href", url);

    const plugin = screen.getByRole("heading", { name: /plugin \(easiest\)/i });
    const connector = screen.getByRole("heading", { name: /custom connector/i });
    expect(plugin.compareDocumentPosition(connector) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(screen.getByText(/create an account or sign in/i)).toBeInTheDocument();
    expect(screen.getByText(/nothing to paste/i)).toBeInTheDocument();
    // The address lives only in the connector section, which stays visible (no collapsed details).
    expect(screen.getAllByText(/api\.viewsmax\.com\/api\/mcp/).length).toBeGreaterThan(0);
    expect(screen.queryByText(/set it up by hand\?/i)).toBeNull();
  });

  it("leads Codex with the plugin directory it shares with ChatGPT", () => {
    renderPage("codex");

    expect(screen.getAllByRole("link", { name: /plugin directory/i })[0]).toHaveAttribute("href", CHATGPT_PLUGIN_URL);
    expect(screen.getAllByText("/plugins").length).toBeGreaterThan(0);
    expect(screen.getByRole("heading", { name: /plugin \(easiest\)/i })).toBeInTheDocument();
  });

  it("leads Claude Code with the Claude directory and says the connector carries over", () => {
    renderPage("claude-code");
    expect(screen.getAllByRole("link", { name: /add to claude/i })[0]).toHaveAttribute("href", CLAUDE_DIRECTORY_URL);
    expect(screen.getByText(/shares connectors with the claude\.ai account/i)).toBeInTheDocument();
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
