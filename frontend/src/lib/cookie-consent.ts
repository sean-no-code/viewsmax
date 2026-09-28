import { API_BASE_URL } from "@/lib/api-service";

// Cookie-consent law applies in the EU/EEA, UK and Switzerland. The backend
// resolves the visitor's country (`GET /api/geo`) so the banner only shows
// where it is required. Any failure to find out fails safe: show the banner.

export const CONSENT_STORAGE_KEY = "cookie-consent";
const REGION_STORAGE_KEY = "cookie-consent-required";

const readStored = (storage: Storage, key: string): string | null => {
  try {
    return storage.getItem(key);
  } catch {
    return null;
  }
};

const writeStored = (storage: Storage, key: string, value: string): void => {
  try {
    storage.setItem(key, value);
  } catch {
    // Storage can be unavailable (private mode, blocked site data) — fine to skip.
  }
};

/** Has the visitor already accepted or declined? */
export const hasCookieDecision = (): boolean => readStored(localStorage, CONSENT_STORAGE_KEY) !== null;

/**
 * Whether the visitor must be shown the cookie banner. The answer is cached
 * for the browser session so navigating around never repeats the lookup.
 */
export async function cookieConsentRequired(fetchImpl: typeof fetch = fetch): Promise<boolean> {
  const cached = readStored(sessionStorage, REGION_STORAGE_KEY);
  if (cached === "true" || cached === "false") return cached === "true";

  let required = true;
  if (API_BASE_URL) {
    try {
      const res = await fetchImpl(`${API_BASE_URL}/api/geo`, { headers: { Accept: "application/json" } });
      if (res.ok) {
        const body = (await res.json()) as { cookie_consent_required?: unknown };
        if (typeof body.cookie_consent_required === "boolean") required = body.cookie_consent_required;
      }
    } catch {
      // Network failure → keep the safe default.
    }
  }

  writeStored(sessionStorage, REGION_STORAGE_KEY, String(required));
  return required;
}
