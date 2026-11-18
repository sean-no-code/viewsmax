import { afterEach, describe, expect, it, vi } from "vitest";
import { render } from "@testing-library/react";
import { MemoryRouter } from "react-router-dom";
import OAuthCallback from "@/pages/OAuthCallback";
import { viewsMaxApi } from "@/lib/api-service";

// VK ID redirects back with code, state and device_id; the device_id must
// travel from the popup to POST /api/social/vk/exchange.
describe("OAuth callback device_id", () => {
  const originalOpener = window.opener;

  afterEach(() => {
    Object.defineProperty(window, "opener", { value: originalOpener, configurable: true, writable: true });
    window.location.search = "";
    vi.restoreAllMocks();
  });

  const openAsPopup = (search: string) => {
    const postMessage = vi.fn();
    Object.defineProperty(window, "opener", { value: { postMessage }, configurable: true, writable: true });
    vi.spyOn(window, "close").mockImplementation(() => {});
    // The test setup replaces window.location with a plain object.
    window.location.search = search;
    render(<MemoryRouter><OAuthCallback /></MemoryRouter>);
    return postMessage;
  };

  it("passes VK's device_id to the opener with the code", () => {
    const postMessage = openAsPopup("?code=abc&state=st4te&device_id=dev-1");
    expect(postMessage).toHaveBeenCalledWith(
      expect.objectContaining({ type: "OAUTH_SUCCESS", code: "abc", state: "st4te", deviceId: "dev-1" }),
      window.location.origin,
    );
  });

  it("sends no deviceId when the provider returns none", () => {
    const postMessage = openAsPopup("?code=abc&state=st4te");
    const message = postMessage.mock.calls[0][0];
    expect(message.type).toBe("OAUTH_SUCCESS");
    expect(message).not.toHaveProperty("deviceId");
  });
});

describe("exchangeSocialCode", () => {
  afterEach(() => vi.restoreAllMocks());

  it("sends device_id to the exchange endpoint when given", async () => {
    const fetchMock = vi.spyOn(globalThis, "fetch").mockResolvedValue(
      new Response(JSON.stringify({ data: [] }), { status: 200, headers: { "Content-Type": "application/json" } }),
    );

    await viewsMaxApi.exchangeSocialCode("vk", { code: "abc", state: "st4te", deviceId: "dev-1" });

    const [url, init] = fetchMock.mock.calls[0];
    expect(String(url)).toContain("/api/social/vk/exchange");
    expect(JSON.parse(String((init as RequestInit).body))).toEqual({ code: "abc", state: "st4te", device_id: "dev-1" });
  });

  it("leaves device_id out for other platforms", async () => {
    const fetchMock = vi.spyOn(globalThis, "fetch").mockResolvedValue(
      new Response(JSON.stringify({ data: [] }), { status: 200, headers: { "Content-Type": "application/json" } }),
    );

    await viewsMaxApi.exchangeSocialCode("x", { code: "abc", state: "st4te" });

    expect(JSON.parse(String((fetchMock.mock.calls[0][1] as RequestInit).body))).not.toHaveProperty("device_id");
  });
});
