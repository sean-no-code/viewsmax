// Admin "log in as user". The admin's own session is parked in localStorage
// while the user's one-hour session is active; ImpersonationBar restores it.

export const IMPERSONATOR_KEY = "impersonator_session";

export interface AuthSessionData {
  user: { email?: string | null };
  token: string;
  token_type: string;
}

/** Who is impersonating, or null when this is a normal session. */
export function readImpersonator<T extends AuthSessionData = AuthSessionData>(): T | null {
  try {
    const raw = localStorage.getItem(IMPERSONATOR_KEY);
    return raw ? (JSON.parse(raw) as T) : null;
  } catch {
    return null;
  }
}

/** Park the admin session and switch to the impersonated user's session. */
export function startImpersonation<T extends AuthSessionData>(adminSession: T, userSession: T, setAuthData: (data: T) => void): void {
  try {
    localStorage.setItem(IMPERSONATOR_KEY, JSON.stringify(adminSession));
    // Per-user caches that would otherwise show the admin's data.
    localStorage.removeItem("active_subscription");
    localStorage.removeItem("user_credits");
  } catch {
    // Storage unavailable: still swap the in-memory session.
  }
  setAuthData(userSession);
}

/** Drop the impersonated session and put the admin back. Returns false when there was nothing to restore. */
export function endImpersonation<T extends AuthSessionData>(setAuthData: (data: T) => void): boolean {
  const admin = readImpersonator<T>();
  if (!admin) return false;
  try {
    localStorage.removeItem(IMPERSONATOR_KEY);
    localStorage.removeItem("active_subscription");
    localStorage.removeItem("user_credits");
  } catch {
    // ignore
  }
  setAuthData(admin);
  return true;
}
