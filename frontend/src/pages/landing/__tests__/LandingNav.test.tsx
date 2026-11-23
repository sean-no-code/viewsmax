import { describe, expect, it } from "vitest";
import { render, screen, within } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import { LandingNav } from "@/pages/landing/LandingChrome";
import { AGENT_GROUPS, AGENT_LIST, AGENTS } from "@/lib/agent-pages";
import { CHANNEL_LIST, CHANNELS_PATH } from "@/lib/channel-pages";
import { hasAgentLogo } from "@/components/agents/agent-icons";
import en from "@/pages/landing/copy/en.json";

const renderNav = () =>
  render(
    <MemoryRouter>
      <LandingNav />
    </MemoryRouter>,
  );

describe("AI Agents mega menu", () => {
  it("shows one column per group, each with its heading", () => {
    renderNav();
    for (const g of AGENT_GROUPS) {
      expect(screen.getByRole("group", { name: en.agentGroups[g] })).toBeInTheDocument();
    }
  });

  it("lists every agent page once, under its own group, with its description", () => {
    renderNav();
    for (const a of AGENT_LIST) {
      const column = screen.getByRole("group", { name: en.agentGroups[a.group] });
      const link = within(column)
        .getAllByRole("link")
        .find((l) => l.getAttribute("href") === a.slug);
      expect(link, a.name).toBeDefined();
      expect(link!).toHaveTextContent(a.name);
      expect(link!).toHaveTextContent((en.agentCards as Record<string, string>)[a.key]);
    }
    const inMenu = AGENT_GROUPS.flatMap((g) => within(screen.getByRole("group", { name: en.agentGroups[g] })).getAllByRole("link"));
    expect(inMenu).toHaveLength(AGENT_LIST.length);
  });

  it("leaves no group empty and links to the full list", () => {
    renderNav();
    for (const g of AGENT_GROUPS) expect(AGENT_LIST.some((a) => a.group === g), g).toBe(true);
    expect(screen.getByRole("link", { name: en.agentGroups.all })).toHaveAttribute("href", "/ai");
  });
});

describe("Channels mega menu", () => {
  it("links every channel page with its description and the hub", () => {
    renderNav();
    for (const c of CHANNEL_LIST) {
      const link = screen.getAllByRole("link").find((l) => l.getAttribute("href") === c.slug);
      expect(link, c.name).toBeDefined();
      expect(link!).toHaveTextContent(c.name);
      expect(link!).toHaveTextContent((en.channelCards as Record<string, string>)[c.key]);
    }
    expect(screen.getByRole("link", { name: en.nav.allChannels })).toHaveAttribute("href", CHANNELS_PATH);
  });

  it("puts a brand icon beside every channel and every agent", () => {
    renderNav();
    for (const item of [...CHANNEL_LIST, ...AGENT_LIST]) {
      const link = screen.getAllByRole("link").find((l) => l.getAttribute("href") === item.slug)!;
      expect(link.querySelector("[aria-hidden]"), item.name).not.toBeNull();
    }
  });
});

describe("agent icons", () => {
  it("has a mark for every agent page, a real logo where the maker publishes one", () => {
    for (const key of Object.keys(AGENTS) as (keyof typeof AGENTS)[]) expect(typeof hasAgentLogo(key), key).toBe("boolean");
    for (const key of ["claude", "claude-code", "gemini", "perplexity", "muse", "deepseek", "cursor"] as const) {
      expect(hasAgentLogo(key), key).toBe(true);
    }
  });
});
