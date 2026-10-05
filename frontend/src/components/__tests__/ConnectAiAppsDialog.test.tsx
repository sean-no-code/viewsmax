import { describe, it, expect, afterEach, beforeEach, vi } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import ConnectAiAppsDialog, { SETUP_PROMPT } from "@/components/ConnectAiAppsDialog";
import { CLAUDE_DIRECTORY_URL, MCP_ENDPOINT } from "@/lib/agent-pages";

const writeText = vi.fn(() => Promise.resolve());

const renderDialog = () =>
  render(
    <MemoryRouter>
      <ConnectAiAppsDialog open onOpenChange={() => {}} />
    </MemoryRouter>,
  );

beforeEach(() => {
  Object.defineProperty(navigator, "clipboard", { value: { writeText }, configurable: true });
  writeText.mockReset();
});
afterEach(() => vi.unstubAllEnvs());

describe("ConnectAiAppsDialog", () => {
  it("shows the MCP address and links Connect to each app's directory listing once listed", () => {
    const claude = CLAUDE_DIRECTORY_URL;
    const chatgpt = "https://chatgpt.com/plugins/viewsmax";
    vi.stubEnv("VITE_CHATGPT_PLUGIN_URL", chatgpt);
    renderDialog();

    expect(screen.getByRole("heading", { name: /connect viewsmax to your ai app/i })).toBeInTheDocument();
    expect(screen.getByText(MCP_ENDPOINT)).toBeInTheDocument();

    const connects = screen.getAllByRole("link", { name: /^connect$/i });
    expect(connects.map((a) => a.getAttribute("href"))).toEqual([claude, chatgpt]);
    connects.forEach((a) => expect(a).toHaveAttribute("target", "_blank"));
    expect(screen.queryByRole("link", { name: /setup guide/i })).toBeNull();
  });

  it("always links the Claude directory, and falls back to the ChatGPT setup guide while unlisted", () => {
    vi.stubEnv("VITE_CHATGPT_PLUGIN_URL", "");
    renderDialog();

    expect(screen.getByRole("link", { name: /^connect$/i })).toHaveAttribute("href", CLAUDE_DIRECTORY_URL);
    expect(screen.getByRole("link", { name: /setup guide/i })).toHaveAttribute("href", "/chatgpt");
  });

  it("copies the MCP address and the setup prompt", () => {
    renderDialog();

    fireEvent.click(screen.getByRole("button", { name: /copy mcp address/i }));
    expect(writeText).toHaveBeenLastCalledWith(MCP_ENDPOINT);

    fireEvent.click(screen.getByRole("button", { name: /copy prompt/i }));
    expect(writeText).toHaveBeenLastCalledWith(SETUP_PROMPT);
    expect(SETUP_PROMPT).toContain(MCP_ENDPOINT);
    expect(SETUP_PROMPT).toContain("https://viewsmax.com/ai.md");
    expect(screen.getByRole("button", { name: /copied/i })).toBeInTheDocument();
  });

  it("points 'Setup instructions' at the AI hub", () => {
    renderDialog();
    expect(screen.getByRole("link", { name: /setup instructions/i })).toHaveAttribute("href", "/ai");
  });
});
