import { API_BASE_URL } from './api-service';

export type OutlierPlatform = 'youtube' | 'tiktok' | 'instagram';

export interface OutlierVideo {
    id: number;
    platform: OutlierPlatform;
    youtube_video_id: string; // native platform id (yt id / tiktok id / ig shortcode)
    title: string;
    description?: string;
    thumbnail_url: string;
    thumbnail_medium_url?: string;
    views: number | null;            // null for Instagram (no view count)
    like_count: number | null;
    comment_count: number | null;
    outlier_score: number | null;    // null for Instagram (no views-based score)
    engagement_rate: number | null;  // null for Instagram
    published_at: string;
    duration: string | null;
    duration_in_seconds?: number | null;
    featured?: boolean; // admin-curated default-feed flag
    // Shorts vs long-form as YouTube itself defines it (HEAD /shorts/{id}); TikTok/IG
    // always true. null/undefined = not yet classified → UI falls back to duration.
    is_short?: boolean | null;
    // Direct CDN media for native playback (Instagram); signed + short-lived.
    video_url?: string | null;
    video_url_expires_at?: string | null;
    channel?: {
        channel_name: string;
        profile_image_url: string | null;
        subscriber_count: number | null;
        average_views?: number | null;
    };
    channel_id?: number;
}

export interface OutlierFilters {
    query: string;
    platform?: OutlierPlatform;
    channels?: string[];
    /** ISO 3166-1 alpha-2 channel-country codes (YouTube only — TikTok/IG channels have no country). */
    countries?: string[];
    min_score?: number;
    min_subs?: number;
    max_subs?: number;
    min_views?: number;
    max_views?: number;
    published_before?: string;
    published_after?: string;
    keyword_match?: string;
    duration_type?: string;
    sort_by?: string;
    featured?: boolean; // curated default feed only
    page?: number;
    per_page?: number;
}

/** Admin: toggle a video in/out of the curated default feed. Returns the new state. */
export async function toggleOutlierFeature(platform: OutlierPlatform, videoId: string): Promise<boolean> {
    const json = await apiJson<{ data: { featured: boolean } }>(`/api/outliers/${platform}/${encodeURIComponent(videoId)}/feature`, { method: 'POST' });
    return json.data.featured;
}

export interface OutlierResponse {
    data: OutlierVideo[];
    status: string;
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
}

export interface OutlierChannelOption {
    id: string;
    name: string;
    avatar: string | null;
    platform: string;
    subscriber_count: number | null;
}

export interface SavedFilter {
    id: number;
    name: string;
    filters: Partial<OutlierFilters>;
    created_at?: string;
}

export interface OutlierTag {
    id: number;
    name: string;
}

export interface SavedOutlier {
    id: number;
    platform: OutlierPlatform;
    video_id: string;
    snapshot: Record<string, unknown>;
    tags: OutlierTag[];
    created_at?: string;
}

// ---- AI breakdown ----
export type BreakdownStatus = 'none' | 'pending' | 'processing' | 'completed' | 'failed';

export interface BreakdownHookBeat {
    time: string;   // e.g. "0.0s"
    line: string;
    note: string;
}

export interface BreakdownStructureBeat {
    label: string;  // e.g. "HOOK"
    start: string;
    end: string;
    pct: number;    // share of runtime, sums to 100
    title: string;
    note: string;
    highlight: 'red' | 'volt' | null;
}

export interface BreakdownTranscriptLine {
    time: string;   // "m:ss"
    text: string;
    label: string | null; // "hook" | "reframe" | "receipts" | "loop point" | …
}

export interface BreakdownPayload {
    idea: { topic: string; idea_seed: string; unique_angle: string };
    hook: BreakdownHookBeat[];
    structure: { summary: string; beats: BreakdownStructureBeat[] };
    visual: { title: string; note: string }[];
    transcript: BreakdownTranscriptLine[];
}

export interface OutlierBreakdown {
    status: BreakdownStatus;
    payload: BreakdownPayload | null;
    error: string | null;
    updated_at?: string;
}

function authHeaders(): Record<string, string> {
    let token: string | null = null;
    try {
        const session = localStorage.getItem('auth_session');
        if (session) token = JSON.parse(session).token;
    } catch (e) {
        console.error('Error parsing auth session', e);
    }
    const headers: Record<string, string> = {
        'Content-Type': 'application/json',
        Accept: 'application/json',
    };
    if (token) headers['Authorization'] = `Bearer ${token}`;
    return headers;
}

async function apiJson<T>(path: string, init?: RequestInit): Promise<T> {
    const response = await fetch(`${API_BASE_URL}${path}`, { headers: authHeaders(), ...init });
    if (!response.ok) {
        let message = `Request failed (${response.status})`;
        try {
            const body = await response.json();
            message = body.error || body.message || message;
        } catch { /* ignore */ }
        throw new Error(message);
    }
    return response.json();
}

export async function searchOutliers(filters: OutlierFilters): Promise<OutlierResponse> {
    const params = new URLSearchParams();
    if (filters.query) params.append('query', filters.query);
    if (filters.platform) params.append('platform', filters.platform);
    if (filters.channels && filters.channels.length) {
        filters.channels.forEach((c) => params.append('channels[]', c));
    }
    if (filters.countries && filters.countries.length) {
        filters.countries.forEach((c) => params.append('countries[]', c));
    }
    if (filters.min_score !== undefined) params.append('min_score', filters.min_score.toString());
    if (filters.featured) params.append('featured', '1');
    if (filters.min_subs !== undefined) params.append('min_subs', filters.min_subs.toString());
    if (filters.max_subs !== undefined) params.append('max_subs', filters.max_subs.toString());
    if (filters.min_views !== undefined) params.append('min_views', filters.min_views.toString());
    if (filters.max_views !== undefined) params.append('max_views', filters.max_views.toString());
    if (filters.published_before) params.append('published_before', filters.published_before);
    if (filters.published_after) params.append('published_after', filters.published_after);
    if (filters.keyword_match) params.append('keyword_match', filters.keyword_match);
    if (filters.duration_type) params.append('duration_type', filters.duration_type);
    if (filters.sort_by) params.append('sort_by', filters.sort_by);
    if (filters.page !== undefined) params.append('page', filters.page.toString());
    if (filters.per_page !== undefined) params.append('per_page', filters.per_page.toString());

    const json = await apiJson<Partial<OutlierResponse>>(`/api/outliers?${params.toString()}`);
    return {
        data: json.data || [],
        status: json.status || 'unknown',
        current_page: json.current_page ?? 1,
        per_page: json.per_page ?? 20,
        total: json.total ?? 0,
        last_page: json.last_page ?? 1,
    };
}

export async function startSearchOutliers(term: string, exactMatch = false): Promise<{ message: string; status: string }> {
    return apiJson('/api/outliers/search', {
        method: 'POST',
        body: JSON.stringify({ term, exact_match: exactMatch }),
    });
}

/** Distinct already-ingested channels for the picker; omit platform to list all. */
export async function getOutlierChannels(q = '', platform?: OutlierPlatform): Promise<OutlierChannelOption[]> {
    const params = new URLSearchParams();
    if (platform) params.append('platform', platform);
    if (q) params.append('q', q);
    const qs = params.toString();
    const json = await apiJson<{ data: OutlierChannelOption[] }>(`/api/outliers/channels${qs ? `?${qs}` : ''}`);
    return json.data || [];
}

/** A single outlier video (with channel) — the breakdown page header. */
export async function getOutlier(platform: string, videoId: string): Promise<OutlierVideo> {
    const json = await apiJson<{ data: OutlierVideo }>(`/api/outliers/${platform}/${encodeURIComponent(videoId)}`);
    return json.data;
}

export async function getOutlierBreakdown(platform: string, videoId: string): Promise<OutlierBreakdown> {
    const json = await apiJson<{ data: OutlierBreakdown }>(`/api/outliers/${platform}/${encodeURIComponent(videoId)}/breakdown`);
    return json.data;
}

/** Kick off (or retry) breakdown generation; poll getOutlierBreakdown afterwards. */
export async function requestOutlierBreakdown(platform: string, videoId: string): Promise<OutlierBreakdown> {
    const json = await apiJson<{ data: OutlierBreakdown }>(`/api/outliers/${platform}/${encodeURIComponent(videoId)}/breakdown`, {
        method: 'POST',
    });
    return json.data;
}

export interface FetchByUrlResult {
    /** true → ingest is queued; poll getOutlier(platform, video_id) until it lands. */
    queued: boolean;
    video_id: string;
    platform: OutlierPlatform;
    /** Present when the video already exists (or a short link resolved synchronously). */
    data?: OutlierVideo;
}

/** Ingest a single video by URL (YouTube via the YouTube API; TikTok/Instagram via CaptAPI).
 *  URLs with a parseable video id are ingested in the background (Instagram can take
 *  minutes) — the result says whether to poll or the video is already available. */
export async function fetchOutlierByUrl(platform: OutlierPlatform, url: string): Promise<FetchByUrlResult> {
    return apiJson<FetchByUrlResult>('/api/outliers/fetch', {
        method: 'POST',
        body: JSON.stringify({ platform, url }),
    });
}

/** Re-fetch an expiring CDN media URL (Instagram) — no-op server-side while it's still fresh. */
export async function refreshOutlierMedia(platform: OutlierPlatform, videoId: string): Promise<OutlierVideo> {
    const json = await apiJson<{ data: OutlierVideo }>(`/api/outliers/${platform}/${encodeURIComponent(videoId)}/refresh-media`, { method: 'POST' });
    return json.data;
}

// ---- Add a creator channel (by profile URL / @handle) ----
export type ChannelIngestStatus = 'queued' | 'processing' | 'done' | 'failed';

/** Channel picker shape (`id` = platform-native channel id) plus the ingest extras. */
export interface AddedChannel extends OutlierChannelOption {
    channel_id: number;
    handle: string | null;
    average_views: number | null;
    last_ingested_at?: string | null;
}

export interface AddChannelResult {
    status: ChannelIngestStatus;
    /** true → poll getOutlierChannelIngest(ingest_id); false → `channel` is ready now. */
    queued: boolean;
    ingest_id?: number;
    platform: OutlierPlatform;
    handle: string;
    channel?: AddedChannel;
}

export interface ChannelIngest {
    ingest_id: number;
    status: ChannelIngestStatus;
    error: string | null;
    platform: OutlierPlatform;
    handle: string;
    videos_added: number;
    channel: AddedChannel | null;
}

/** Pull a creator's recent videos into the outlier DB. `platform` is required for a bare @handle. */
export async function addOutlierChannel(input: string, platform?: OutlierPlatform, maxVideos?: number): Promise<AddChannelResult> {
    return apiJson<AddChannelResult>('/api/outliers/channels/add', {
        method: 'POST',
        body: JSON.stringify({ input, platform, max_videos: maxVideos }),
    });
}

export async function getOutlierChannelIngest(id: number): Promise<ChannelIngest> {
    const json = await apiJson<{ data: ChannelIngest }>(`/api/outliers/channels/ingests/${id}`);
    return json.data;
}

// ---- Competitor channels ----
export interface CompetitorChannel {
    channel_id: number;
    platform: OutlierPlatform | null;
    channel_name: string | null;
    added_at?: string | null;
}

export async function getOutlierCompetitors(): Promise<CompetitorChannel[]> {
    const json = await apiJson<{ data: CompetitorChannel[] }>('/api/outliers/competitors');
    return json.data;
}

export async function addOutlierCompetitor(channelId: number): Promise<void> {
    await apiJson('/api/outliers/competitors', { method: 'POST', body: JSON.stringify({ channel_id: channelId }) });
}

export async function removeOutlierCompetitor(channelId: number): Promise<void> {
    await apiJson(`/api/outliers/competitors/${channelId}`, { method: 'DELETE' });
}

// ---- Saved filters ----
export async function getSavedFilters(): Promise<SavedFilter[]> {
    const json = await apiJson<{ data: SavedFilter[] }>('/api/outliers/saved-filters');
    return json.data || [];
}

export async function saveFilter(name: string, filters: Partial<OutlierFilters>): Promise<SavedFilter> {
    const json = await apiJson<{ data: SavedFilter }>('/api/outliers/saved-filters', {
        method: 'POST',
        body: JSON.stringify({ name, filters }),
    });
    return json.data;
}

export async function deleteSavedFilter(id: number): Promise<void> {
    await apiJson(`/api/outliers/saved-filters/${id}`, { method: 'DELETE' });
}

// ---- Saved-outliers library + tags ----
export async function getLibrary(opts: { tags?: string[]; q?: string; platforms?: OutlierPlatform[]; creator?: string } = {}): Promise<SavedOutlier[]> {
    const params = new URLSearchParams();
    (opts.tags || []).forEach((t) => params.append('tags[]', t));
    (opts.platforms || []).forEach((p) => params.append('platforms[]', p));
    if (opts.q) params.append('q', opts.q);
    if (opts.creator) params.append('creator', opts.creator);
    const qs = params.toString();
    const json = await apiJson<{ data: SavedOutlier[] }>(`/api/outliers/library${qs ? `?${qs}` : ''}`);
    return json.data || [];
}

export async function saveOutlier(payload: {
    platform: OutlierPlatform;
    video_id: string;
    snapshot: Record<string, unknown>;
    tags?: string[];
}): Promise<SavedOutlier> {
    const json = await apiJson<{ data: SavedOutlier }>('/api/outliers/library', {
        method: 'POST',
        body: JSON.stringify(payload),
    });
    return json.data;
}

export async function updateSavedOutlierTags(id: number, tags: string[]): Promise<SavedOutlier> {
    const json = await apiJson<{ data: SavedOutlier }>(`/api/outliers/library/${id}`, {
        method: 'PATCH',
        body: JSON.stringify({ tags }),
    });
    return json.data;
}

export async function deleteSavedOutlier(id: number): Promise<void> {
    await apiJson(`/api/outliers/library/${id}`, { method: 'DELETE' });
}

export async function getOutlierTags(): Promise<OutlierTag[]> {
    const json = await apiJson<{ data: OutlierTag[] }>('/api/outliers/tags');
    return json.data || [];
}
