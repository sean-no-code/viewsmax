// Frontend error reporting (Sentry). `initSentry()` runs once from main.tsx
// before React mounts.
//
// Like the trackers in tracking.ts, nothing is baked into the open-source tree:
// it runs only when VITE_SENTRY_DSN is set AND the page is served from
// VITE_TRACKING_HOSTNAME, so local dev and forks report nothing. Admin sessions
// are reported too (unlike analytics): their errors are just as real.
//
// What it sends: uncaught errors and React render crashes (with the logged-in
// user's id only, see useAuth), plus a 10% sample of page loads and navigations
// named by route pattern (/dashboard/post/:id). No session replay; no cookies,
// headers, request/response bodies or URL query strings (see dataCollection).
// Tracing headers stay on same-origin requests: the API is another origin, and
// adding headers there would need its CORS config to allow sentry-trace/baggage.
//
// Source maps are uploaded at build time by @sentry/vite-plugin (vite.config.ts)
// when SENTRY_AUTH_TOKEN is set, then deleted from dist/ so they're never public.
import * as Sentry from "@sentry/react";
import { reactRouterBrowserTracingIntegration } from "@sentry/react/react-router";

export interface SentryConfig {
  dsn: string;
  /** Sentry runs only on this hostname (the production site). Empty = never. */
  hostname: string;
}

const str = (v: unknown): string => (typeof v === "string" ? v.trim() : "");

export const readSentryConfig = (): SentryConfig => ({
  dsn: str(import.meta.env.VITE_SENTRY_DSN),
  hostname: str(import.meta.env.VITE_TRACKING_HOSTNAME),
});

export const isSentryEnabled = (cfg: SentryConfig, currentHostname: string): boolean =>
  cfg.dsn !== "" && cfg.hostname !== "" && currentHostname === cfg.hostname;

/** "https://x/verify-email?token=abc#f" -> "https://x/verify-email". Leaves non-URLs alone. */
export const stripQuery = (url: unknown): unknown => (typeof url === "string" ? url.replace(/[?#].*$/, "") : url);

// Belt and braces for dataCollection.urlQueryParams: the page URL on every event
// and the URLs in navigation/fetch breadcrumbs lose their query string.
const scrubEvent = <E extends { request?: { url?: string } }>(event: E): E => {
  if (event.request?.url) event.request.url = stripQuery(event.request.url) as string;
  return event;
};

export function initSentry(cfg: SentryConfig = readSentryConfig()): void {
  if (typeof window === "undefined" || !isSentryEnabled(cfg, window.location.hostname)) return;

  Sentry.init({
    dsn: cfg.dsn,
    environment: "production",
    // v11 collects all of these by default. Off here: query strings carry email
    // verification, password reset and OAuth codes; bodies carry user content.
    // The user is identified by id only, via setSentryUser().
    dataCollection: {
      userInfo: false,
      cookies: false,
      httpHeaders: false,
      httpBodies: [],
      urlQueryParams: false,
    },
    beforeSend: scrubEvent,
    beforeSendTransaction: scrubEvent,
    beforeBreadcrumb(crumb) {
      for (const key of ["url", "from", "to"]) if (crumb.data?.[key]) crumb.data[key] = stripQuery(crumb.data[key]);
      return crumb;
    },
    integrations: [reactRouterBrowserTracingIntegration()],
    tracesSampleRate: 0.1,
    // Noise that isn't ours to fix: browser extensions and a benign resize warning.
    denyUrls: [/^chrome-extension:\/\//, /^moz-extension:\/\//, /^safari-web-extension:\/\//],
    ignoreErrors: ["ResizeObserver loop limit exceeded", "ResizeObserver loop completed with undelivered notifications"],
  });
}

/** Tag events with the logged-in user (id only), or clear it on sign-out. No-op when Sentry isn't running. */
export const setSentryUser = (id: string | number | null | undefined): void => {
  Sentry.setUser(id == null ? null : { id: String(id) });
};
