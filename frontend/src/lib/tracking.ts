// Central tracking bootstrap (Meta Pixel, GTM, GA4, Clarity, Rewardful, ViewsMax tracker).
//
// Every vendor ID comes from the environment (see .env.example) so the open-source
// tree ships with no tracking baked in. A tracker loads only when its VITE_* value is
// set AND the page is served from VITE_TRACKING_HOSTNAME by a non-admin user.
// `initTracking()` runs once from main.tsx before React mounts.

/* eslint-disable @typescript-eslint/no-explicit-any */

export interface TrackingConfig {
  /** Trackers run only on this hostname (e.g. "viewsmax.com"). Empty = never. */
  hostname: string;
  /** One or more Meta Pixel IDs (comma-separated in the env var). */
  metaPixelIds: string[];
  gtmId: string;
  gaMeasurementId: string;
  clarityId: string;
  rewardfulId: string;
  /** Public user id for the ViewsMax tracker, served from `${apiBaseUrl}/tracker.js`. */
  viewsmaxTrackerUser: string;
  apiBaseUrl: string;
}

const str = (v: unknown): string => (typeof v === 'string' ? v.trim() : '');

export const readTrackingConfig = (): TrackingConfig => {
  const env = import.meta.env;
  return {
    hostname: str(env.VITE_TRACKING_HOSTNAME),
    metaPixelIds: str(env.VITE_META_PIXEL_ID).split(',').map((s) => s.trim()).filter(Boolean),
    gtmId: str(env.VITE_GTM_ID),
    gaMeasurementId: str(env.VITE_GA_MEASUREMENT_ID),
    clarityId: str(env.VITE_CLARITY_ID),
    rewardfulId: str(env.VITE_REWARDFUL_ID),
    viewsmaxTrackerUser: str(env.VITE_VIEWSMAX_TRACKER_USER),
    apiBaseUrl: str(env.VITE_API_BASE_URL).replace(/\/$/, ''),
  };
};

export const isAdminSession = (): boolean => {
  try {
    const raw = localStorage.getItem('auth_session');
    if (!raw) return false;
    return JSON.parse(raw)?.user?.is_admin === true;
  } catch {
    return false;
  }
};

export const isTrackingAllowed = (cfg: Pick<TrackingConfig, 'hostname'> = readTrackingConfig()): boolean =>
  typeof window !== 'undefined' &&
  cfg.hostname !== '' &&
  window.location.hostname === cfg.hostname &&
  !isAdminSession();

const addScript = (src: string, attrs: Record<string, string> = {}, defer = false): HTMLScriptElement => {
  const s = document.createElement('script');
  s.src = src;
  s.async = !defer;
  s.defer = defer;
  for (const [k, v] of Object.entries(attrs)) s.setAttribute(k, v);
  document.head.appendChild(s);
  return s;
};

/** Queue-style stub (`w[name](...args)` buffers until the vendor script replaces it). */
const queueStub = (w: any, name: string) => {
  if (w[name]) return;
  w[name] = function (...args: unknown[]) {
    (w[name].q = w[name].q || []).push(args);
  };
};

const loadMetaPixel = (w: any, ids: string[]) => {
  if (!w.fbq) {
    const n: any = (w.fbq = function (...args: unknown[]) {
      n.callMethod ? n.callMethod.apply(n, args) : n.queue.push(args);
    });
    if (!w._fbq) w._fbq = n;
    n.push = n;
    n.loaded = true;
    n.version = '2.0';
    n.queue = [];
    addScript('https://connect.facebook.net/en_US/fbevents.js');
  }
  for (const id of ids) w.fbq('init', id);
  w.fbq('track', 'PageView');
};

const loadGtm = (w: any, id: string) => {
  w.dataLayer = w.dataLayer || [];
  w.dataLayer.push({ 'gtm.start': Date.now(), event: 'gtm.js' });
  addScript(`https://www.googletagmanager.com/gtm.js?id=${encodeURIComponent(id)}`);
};

const loadGa = (w: any, id: string) => {
  addScript(`https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(id)}`);
  w.dataLayer = w.dataLayer || [];
  w.gtag = w.gtag || function () { w.dataLayer.push(arguments); }; // eslint-disable-line prefer-rest-params
  w.gtag('js', new Date());
  w.gtag('config', id);
};

const loadClarity = (w: any, id: string) => {
  queueStub(w, 'clarity');
  addScript(`https://www.clarity.ms/tag/${encodeURIComponent(id)}`);
};

const loadRewardful = (w: any, id: string) => {
  w._rwq = 'rewardful';
  queueStub(w, 'rewardful');
  addScript('https://r.wdfl.co/rw.js', { 'data-rewardful': id });
};

const loadViewsmaxTracker = (publicId: string, apiBaseUrl: string) => {
  apiBaseUrl = apiBaseUrl.replace(/\/+$/, '');
  if (!apiBaseUrl) return;
  // tracker.js reads the public id from this meta tag on load.
  const meta = document.createElement('meta');
  meta.name = 'viewsmax-user';
  meta.content = publicId;
  document.head.appendChild(meta);
  addScript(`${apiBaseUrl}/tracker.js`, {}, true);
};

/** Loads every configured tracker. No-op when tracking is not allowed or nothing is configured. */
export const initTracking = (cfg: TrackingConfig = readTrackingConfig()): void => {
  if (!isTrackingAllowed(cfg)) return;
  const w = window as any;
  if (cfg.metaPixelIds.length) loadMetaPixel(w, cfg.metaPixelIds);
  if (cfg.gtmId) loadGtm(w, cfg.gtmId);
  if (cfg.gaMeasurementId) loadGa(w, cfg.gaMeasurementId);
  if (cfg.clarityId) loadClarity(w, cfg.clarityId);
  if (cfg.rewardfulId) loadRewardful(w, cfg.rewardfulId);
  if (cfg.viewsmaxTrackerUser) loadViewsmaxTracker(cfg.viewsmaxTrackerUser, cfg.apiBaseUrl);
};

// Stops trackers that already loaded before an admin logged in mid-session
// (initTracking blocks them at page load, but only once is_admin is in localStorage).
export const disableTrackingForAdmin = (cfg: TrackingConfig = readTrackingConfig()): void => {
  if (typeof window === 'undefined') return;
  const w = window as any;
  if (cfg.gaMeasurementId) w[`ga-disable-${cfg.gaMeasurementId}`] = true; // Google Analytics kill switch
  try {
    w.fbq?.('consent', 'revoke');
  } catch {
    // tracker not loaded
  }
  try {
    w.clarity?.('stop');
  } catch {
    // tracker not loaded
  }
};

// Meta Pixel event helpers (no-ops when the pixel isn't loaded).
export const trackEvent = (eventName: string, parameters?: Record<string, unknown>) => {
  const w = window as any;
  if (isTrackingAllowed() && w.fbq) w.fbq('track', eventName, parameters);
};

export const trackCustomEvent = (eventName: string, parameters?: Record<string, unknown>) => {
  const w = window as any;
  if (isTrackingAllowed() && w.fbq) w.fbq('trackCustom', eventName, parameters);
};
