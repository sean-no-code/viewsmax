import { beforeEach, describe, expect, it, vi } from 'vitest';
import { initTracking, isTrackingAllowed, type TrackingConfig } from './tracking';

const full: TrackingConfig = {
  hostname: 'localhost', // jsdom's default location
  metaPixelIds: ['111', '222'],
  gtmId: 'GTM-TEST',
  gaMeasurementId: 'G-TEST',
  clarityId: 'clar1ty',
  rewardfulId: 'rw123',
  viewsmaxTrackerUser: 'user-uuid',
  apiBaseUrl: 'https://api.example.test/',
};
const none: TrackingConfig = { ...full, metaPixelIds: [], gtmId: '', gaMeasurementId: '', clarityId: '', rewardfulId: '', viewsmaxTrackerUser: '' };

const scriptSrcs = () => Array.from(document.head.querySelectorAll('script')).map((s) => s.src);

describe('tracking', () => {
  beforeEach(() => {
    document.head.innerHTML = '';
    // src/test/setup.ts replaces window.location with a bare object and
    // localStorage with spies, so pin the bits the gate reads.
    (window.location as any).hostname = 'localhost';
    vi.mocked(localStorage.getItem).mockReturnValue(null);
    const w = window as any;
    delete w.fbq; delete w._fbq; delete w.dataLayer; delete w.gtag; delete w.clarity; delete w.rewardful; delete w._rwq;
  });

  it('is off when no hostname is configured', () => {
    expect(isTrackingAllowed({ hostname: '' })).toBe(false);
    initTracking({ ...full, hostname: '' });
    expect(scriptSrcs()).toEqual([]);
  });

  it('is off on any other hostname', () => {
    initTracking({ ...full, hostname: 'viewsmax.com' });
    expect(scriptSrcs()).toEqual([]);
  });

  it('is off for admin sessions', () => {
    vi.mocked(localStorage.getItem).mockReturnValue(JSON.stringify({ user: { is_admin: true } }));
    initTracking(full);
    expect(scriptSrcs()).toEqual([]);
  });

  it('loads nothing when no vendor IDs are set', () => {
    initTracking(none);
    expect(scriptSrcs()).toEqual([]);
    expect(document.querySelector('meta[name="viewsmax-user"]')).toBeNull();
  });

  it('loads each configured tracker with its ID', () => {
    initTracking({ ...full, apiBaseUrl: 'https://api.example.test' });
    const srcs = scriptSrcs();
    expect(srcs).toContain('https://connect.facebook.net/en_US/fbevents.js');
    expect(srcs).toContain('https://www.googletagmanager.com/gtm.js?id=GTM-TEST');
    expect(srcs).toContain('https://www.googletagmanager.com/gtag/js?id=G-TEST');
    expect(srcs).toContain('https://www.clarity.ms/tag/clar1ty');
    expect(srcs).toContain('https://r.wdfl.co/rw.js');
    expect(srcs).toContain('https://api.example.test/tracker.js');

    expect(document.querySelector('script[data-rewardful]')?.getAttribute('data-rewardful')).toBe('rw123');
    expect(document.querySelector('meta[name="viewsmax-user"]')?.getAttribute('content')).toBe('user-uuid');

    const w = window as any;
    expect(w.fbq.queue).toEqual([['init', '111'], ['init', '222'], ['track', 'PageView']]);
    expect(w.dataLayer.some((e: any) => e?.event === 'gtm.js')).toBe(true);
    expect(w.dataLayer.some((e: IArguments) => e[0] === 'config' && e[1] === 'G-TEST')).toBe(true);
  });

  it('strips a trailing slash from the API base when building the tracker URL', () => {
    initTracking({ ...none, viewsmaxTrackerUser: 'u', apiBaseUrl: 'https://api.example.test/' });
    expect(scriptSrcs()).toEqual(['https://api.example.test/tracker.js']);
  });
});
