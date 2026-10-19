import { describe, it, expect, vi, beforeEach } from "vitest";

const init = vi.fn();
vi.mock("@sentry/react", () => ({ init, setUser: vi.fn() }));
vi.mock("@sentry/react/react-router", () => ({ reactRouterBrowserTracingIntegration: () => ({ name: "router" }) }));

const { initSentry, isSentryEnabled, stripQuery } = await import("@/lib/sentry");

const cfg = { dsn: "https://key@o1.ingest.sentry.io/1", hostname: "viewsmax.com" };

describe("isSentryEnabled", () => {
  it("runs only with a DSN, on the production hostname", () => {
    expect(isSentryEnabled(cfg, "viewsmax.com")).toBe(true);
    expect(isSentryEnabled(cfg, "localhost")).toBe(false);
    expect(isSentryEnabled({ ...cfg, dsn: "" }, "viewsmax.com")).toBe(false);
    expect(isSentryEnabled({ ...cfg, hostname: "" }, "")).toBe(false);
  });
});

describe("initSentry", () => {
  beforeEach(() => init.mockClear());

  it("does nothing off the production hostname (local dev, forks)", () => {
    initSentry(cfg); // tests run on localhost
    expect(init).not.toHaveBeenCalled();
  });

  it("turns off collection of cookies, headers, bodies and query strings", () => {
    initSentry({ ...cfg, hostname: window.location.hostname });
    expect(init).toHaveBeenCalledOnce();
    expect(init.mock.calls[0][0].dataCollection).toEqual({
      userInfo: false, cookies: false, httpHeaders: false, httpBodies: [], urlQueryParams: false,
    });
  });

  it("strips query strings from the page URL and breadcrumbs", () => {
    initSentry({ ...cfg, hostname: window.location.hostname });
    const opts = init.mock.calls[0][0];
    expect(opts.beforeSend({ request: { url: "https://viewsmax.com/reset-password?token=secret" } }).request.url)
      .toBe("https://viewsmax.com/reset-password");
    const crumb = opts.beforeBreadcrumb({ category: "navigation", data: { from: "/verify-email?token=a", to: "/oauth/callback?code=b" } });
    expect(crumb.data).toEqual({ from: "/verify-email", to: "/oauth/callback" });
    expect(opts.beforeBreadcrumb({ category: "ui.click" }).data).toBeUndefined();
  });
});

describe("stripQuery", () => {
  it("drops the query string and fragment, and leaves non-strings alone", () => {
    expect(stripQuery("/a/b?x=1#y")).toBe("/a/b");
    expect(stripQuery("/a/b")).toBe("/a/b");
    expect(stripQuery(undefined)).toBeUndefined();
  });
});
