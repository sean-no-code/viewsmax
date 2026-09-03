// Shared formatting helpers for the Outliers cards + pages.

export function formatCompact(n: number | null | undefined): string {
    if (n === null || n === undefined) return '—';
    if (n >= 1_000_000_000) return (n / 1_000_000_000).toFixed(n >= 10_000_000_000 ? 0 : 1).replace(/\.0$/, '') + 'B';
    if (n >= 1_000_000) return (n / 1_000_000).toFixed(n >= 10_000_000 ? 0 : 1).replace(/\.0$/, '') + 'M';
    if (n >= 1_000) return (n / 1_000).toFixed(n >= 10_000 ? 0 : 1).replace(/\.0$/, '') + 'K';
    return String(n);
}

/** Outlier score for display: scores over 100 read as ">100x" everywhere. */
export function formatScore(score: number | null | undefined): string {
    const s = score ?? 0;
    return s > 100 ? '>100x' : String(Math.round(s));
}

/**
 * Best-resolution thumbnail for a video. YouTube: the DB stores only the tiny
 * default/medium sizes, so derive maxresdefault (1280px) from the id — pair
 * with fallbackThumbnail() on <img onError> since not every video has it.
 */
export function bestThumbnail(video: { platform: string; youtube_video_id: string; thumbnail_url?: string | null; thumbnail_medium_url?: string | null }): string | undefined {
    if (video.platform === 'youtube' && video.youtube_video_id) {
        return `https://i.ytimg.com/vi/${video.youtube_video_id}/maxresdefault.jpg`;
    }
    return video.thumbnail_medium_url || video.thumbnail_url || undefined;
}

/** Next-best source when bestThumbnail() 404s (YouTube hqdefault, else stored). */
export function fallbackThumbnail(video: { platform: string; youtube_video_id: string; thumbnail_url?: string | null; thumbnail_medium_url?: string | null }): string | undefined {
    if (video.platform === 'youtube' && video.youtube_video_id) {
        return `https://i.ytimg.com/vi/${video.youtube_video_id}/hqdefault.jpg`;
    }
    return video.thumbnail_url || video.thumbnail_medium_url || undefined;
}

export function formatDuration(iso: string | null | undefined): string | null {
    if (!iso) return null;
    const m = iso.match(/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/);
    if (!m) return null;
    const h = parseInt(m[1] || '0', 10);
    const min = parseInt(m[2] || '0', 10);
    const s = parseInt(m[3] || '0', 10);
    if (h > 0) return `${h}:${String(min).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
    return `${min}:${String(s).padStart(2, '0')}`;
}

/**
 * THE shorts/long rule for the UI. Backend classification (`is_short`, from
 * YouTube's own /shorts/ URL) wins; TikTok/Instagram are short-form by
 * definition; otherwise fall back to the legacy ≤180s duration rule for rows
 * not yet classified (P0D live streams / null durations count as long).
 */
export function isShortVideo(video: { platform: string; is_short?: boolean | null; duration?: string | null }): boolean {
    if (typeof video.is_short === 'boolean') return video.is_short;
    if (video.platform === 'tiktok' || video.platform === 'instagram') return true;
    const secs = durationSeconds(video.duration);
    return secs > 0 && secs <= 180;
}

export function cardVariant(video: Parameters<typeof isShortVideo>[0]): 'long' | 'shorts' {
    return isShortVideo(video) ? 'shorts' : 'long';
}

/**
 * Beat boundaries from the AI breakdown arrive as raw seconds ("1320s", "3.5s")
 * or already-clocked ("0:05"). Render everything as m:ss (h:mm:ss past an hour);
 * anything unparseable passes through untouched.
 */
export function formatBeatTime(value: string | number | null | undefined): string {
    if (value == null) return '';
    const raw = String(value).trim();
    if (/^\d+:\d{2}(:\d{2})?$/.test(raw)) return raw;
    const m = raw.match(/^(\d+(?:\.\d+)?)\s*s?$/i);
    if (!m) return raw;
    const total = Math.round(parseFloat(m[1]));
    const h = Math.floor(total / 3600), mm = Math.floor((total % 3600) / 60), ss = total % 60;
    return h > 0
        ? `${h}:${String(mm).padStart(2, '0')}:${String(ss).padStart(2, '0')}`
        : `${mm}:${String(ss).padStart(2, '0')}`;
}

export function durationSeconds(iso: string | null | undefined): number {
    if (!iso) return 0;
    const m = iso.match(/PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?/);
    if (!m) return 0;
    return parseInt(m[1] || '0', 10) * 3600 + parseInt(m[2] || '0', 10) * 60 + parseInt(m[3] || '0', 10);
}

export function timeAgo(dateStr: string | null | undefined): string {
    if (!dateStr) return '';
    const then = new Date(dateStr).getTime();
    if (Number.isNaN(then)) return '';
    const days = Math.max(0, Math.floor((Date.now() - then) / 86_400_000));
    if (days === 0) return 'today';
    if (days < 7) return `${days}d ago`;
    if (days < 30) return `${Math.floor(days / 7)}w ago`;
    if (days < 365) return `${Math.floor(days / 30)}mo ago`;
    return `${Math.floor(days / 365)}y ago`;
}

export function formatDate(dateStr: string | null | undefined): string {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    if (Number.isNaN(d.getTime())) return '';
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

export interface Tier {
    scoreColor: string;
    barColor: string;
    emoji: string | null;
}

export function scoreTier(score: number | null | undefined, emojiTiers = true): Tier {
    const s = score ?? 0;
    if (s >= 70) {
        return { scoreColor: 'var(--up)', barColor: 'linear-gradient(90deg, var(--vm-red), #FF7A1F)', emoji: emojiTiers ? '🔥' : null };
    }
    if (s >= 40) {
        return { scoreColor: 'var(--warn)', barColor: 'linear-gradient(90deg, var(--warn), #FFCB5C)', emoji: emojiTiers ? '⚡' : null };
    }
    return { scoreColor: 'var(--ink-on-paper-2)', barColor: 'var(--line-2)', emoji: null };
}

/** The canonical native watch/permalink URL for an outlier video. */
export function outlierUrl(platform: string, videoId: string, handle?: string | null): string {
    switch (platform) {
        case 'tiktok':
            return `https://www.tiktok.com/@${(handle || '').replace(/^@/, '')}/video/${videoId}`;
        case 'instagram':
            return `https://www.instagram.com/p/${videoId}/`;
        default:
            return `https://www.youtube.com/watch?v=${videoId}`;
    }
}
