import { beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("@/lib/api-service", () => ({ API_BASE_URL: "http://api.test" }));

import { cookieConsentRequired, hasCookieDecision, CONSENT_STORAGE_KEY } from "@/lib/cookie-consent";

const jsonResponse = (body: unknown, ok = true) =>
  ({ ok, json: async () => body }) as unknown as Response;

// The global test setup replaces localStorage with bare vi.fn() stubs (getItem
// returns undefined). Use a real in-memory store here so decisions round-trip.
const memoryStorage = (): Storage => {
  const m = new Map<string, string>();
  return {
    getItem: (k) => (m.has(k) ? m.get(k)! : null),
    setItem: (k, v) => void m.set(k, String(v)),
    removeItem: (k) => void m.delete(k),
    clear: () => m.clear(),
    key: (i) => [...m.keys()][i] ?? null,
    get length() { return m.size; },
  };
};

describe("cookieConsentRequired", () => {
  beforeEach(() => {
    Object.defineProperty(window, "localStorage", { value: memoryStorage(), configurable: true });
    Object.defineProperty(window, "sessionStorage", { value: memoryStorage(), configurable: true });
  });

  it("is false for visitors outside a consent jurisdiction", async () => {
    const fetchImpl = vi.fn(async () => jsonResponse({ country_code: "US", cookie_consent_required: false }));
    expect(await cookieConsentRequired(fetchImpl)).toBe(false);
    expect(fetchImpl).toHaveBeenCalledWith("http://api.test/api/geo", expect.anything());
  });

  it("is true for EU visitors", async () => {
    const fetchImpl = vi.fn(async () => jsonResponse({ country_code: "DE", cookie_consent_required: true }));
    expect(await cookieConsentRequired(fetchImpl)).toBe(true);
  });

  it("fails safe to true when the lookup errors or is malformed", async () => {
    expect(await cookieConsentRequired(vi.fn(async () => { throw new Error("offline"); }))).toBe(true);
    sessionStorage.clear();
    expect(await cookieConsentRequired(vi.fn(async () => jsonResponse({}, false)))).toBe(true);
    sessionStorage.clear();
    expect(await cookieConsentRequired(vi.fn(async () => jsonResponse({ cookie_consent_required: "yes" })))).toBe(true);
  });

  it("caches the answer for the session so the API is hit once", async () => {
    const fetchImpl = vi.fn(async () => jsonResponse({ cookie_consent_required: false }));
    await cookieConsentRequired(fetchImpl);
    await cookieConsentRequired(fetchImpl);
    expect(fetchImpl).toHaveBeenCalledTimes(1);
  });

  it("reports an existing decision", () => {
    expect(hasCookieDecision()).toBe(false);
    localStorage.setItem(CONSENT_STORAGE_KEY, "declined");
    expect(hasCookieDecision()).toBe(true);
  });
});
