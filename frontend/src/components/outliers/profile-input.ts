import type { OutlierPlatform } from '@/lib/outlier-service';

export interface ProfileInput {
    /** null for a bare @handle — the user has to say which platform. */
    platform: OutlierPlatform | null;
    handle: string;
}

/** Instagram path segments that are app pages, never usernames. */
const IG_RESERVED = new Set(['p', 'reel', 'reels', 'tv', 'explore', 'stories', 'accounts', 'direct']);
const HANDLE_RE = /^[\w.-]{1,100}$/;

function handleOf(platform: OutlierPlatform, raw: string): ProfileInput | null {
    let decoded = raw;
    try { decoded = decodeURIComponent(raw); } catch { /* keep raw */ }
    const handle = decoded.replace(/^@/, '').toLowerCase();
    return HANDLE_RE.test(handle) ? { platform, handle } : null;
}

/**
 * Is this a creator profile (URL or @handle) rather than a video link or a
 * keyword? Mirrors the backend's OutlierProfileInput so the UI can route the
 * value to "add channel" before the video / keyword paths see it. Video links
 * return null on purpose: they go through the existing paste-a-URL flow.
 */
export function parseProfileInput(raw: string): ProfileInput | null {
    const value = raw.trim();
    if (!value) return null;

    if (value.startsWith('@')) {
        const handle = value.slice(1).toLowerCase();
        return HANDLE_RE.test(handle) ? { platform: null, handle } : null;
    }

    const asUrl = value.startsWith('www.') ? `https://${value}` : value;
    if (!/^https?:\/\//i.test(asUrl)) return null;
    let url: URL;
    try { url = new URL(asUrl); } catch { return null; }

    const host = url.hostname.toLowerCase();
    const segs = url.pathname.split('/').filter(Boolean);

    if (host.endsWith('youtube.com')) {
        if (url.searchParams.has('v')) return null;
        if (segs[0]?.startsWith('@')) return handleOf('youtube', segs[0]);
        if (segs[0] === 'channel' && /^UC[\w-]+$/.test(segs[1] ?? '')) return { platform: 'youtube', handle: segs[1] };
        if (segs[0] === 'user' && segs[1]) return handleOf('youtube', segs[1]);
        return null;
    }
    if (host.endsWith('tiktok.com')) {
        if (segs[0]?.startsWith('@') && segs[1] !== 'video') return handleOf('tiktok', segs[0]);
        return null;
    }
    if (host.endsWith('instagram.com') || host.endsWith('instagr.am')) {
        if (!segs[0] || IG_RESERVED.has(segs[0].toLowerCase())) return null;
        return handleOf('instagram', segs[0]);
    }
    return null;
}
