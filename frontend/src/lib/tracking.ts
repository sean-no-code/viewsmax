// Central gate for tracking (Meta Pixel, GTM/GA, Clarity, Rewardful, ViewsMax tracker).
// Mirrors the inline __vmxTrackingAllowed check in index.html — keep the two in sync.

export const isAdminSession = (): boolean => {
  try {
    const raw = localStorage.getItem('auth_session');
    if (!raw) return false;
    return JSON.parse(raw)?.user?.is_admin === true;
  } catch {
    return false;
  }
};

export const isTrackingAllowed = (): boolean =>
  typeof window !== 'undefined' &&
  window.location.hostname === 'viewsmax.com' &&
  !isAdminSession();

// Stops trackers that already loaded before an admin logged in mid-session
// (index.html blocks them at page load, but only once is_admin is in localStorage).
export const disableTrackingForAdmin = (): void => {
  if (typeof window === 'undefined') return;
  const w = window as any;
  w['ga-disable-G-4PSTL2CRK5'] = true; // Google Analytics kill switch
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
