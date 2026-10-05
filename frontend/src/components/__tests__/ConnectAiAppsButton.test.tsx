import { describe, it, expect, vi } from "vitest";
import { render, screen, fireEvent } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import ConnectAiAppsButton from "@/components/ConnectAiAppsButton";

vi.mock("react-i18next", () => ({
  useTranslation: () => ({ t: (_key: string, fallback?: string) => fallback ?? _key }),
}));

describe("ConnectAiAppsButton", () => {
  it("opens the connect-your-AI-app popout", () => {
    render(
      <MemoryRouter>
        <ConnectAiAppsButton />
      </MemoryRouter>,
    );

    expect(screen.queryByRole("dialog")).toBeNull();
    fireEvent.click(screen.getByRole("button", { name: /use viewsmax in ai apps/i }));

    expect(screen.getByRole("dialog")).toBeInTheDocument();
    expect(screen.getByRole("heading", { name: /connect viewsmax to your ai app/i })).toBeInTheDocument();
  });
});
