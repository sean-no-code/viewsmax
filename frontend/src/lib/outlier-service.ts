import { API_BASE_URL } from './api-service';

export interface OutlierVideo {
    id: number;
    youtube_video_id: string;
    title: string;
    description: string;
    thumbnail_url: string;
    thumbnail_medium_url?: string;
    thumbnail_high_url?: string;
    view_count: number;
    like_count: number;
    comment_count: number;
    outlier_score: number;
    published_at: string;
    duration: string;
    channel?: {
        channel_name: string;
        profile_image_url: string;
        subscriber_count: number;
        custom_url?: string;
    };
    channel_id?: number;
}

export interface OutlierFilters {
    query: string;
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
    page?: number;
    per_page?: number;
}

export interface OutlierResponse {
    data: OutlierVideo[];
    status: string;
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
}

export async function searchOutliers(filters: OutlierFilters): Promise<OutlierResponse> {
    let token = null;
    try {
        const session = localStorage.getItem('auth_session');
        if (session) {
            token = JSON.parse(session).token;
        }
    } catch (e) {
        console.error("Error parsing auth session", e);
    }

    const headers: Record<string, string> = {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
    };

    if (token) {
        headers['Authorization'] = `Bearer ${token}`;
    }

    const params = new URLSearchParams();
    if (filters.query) {
        params.append('query', filters.query);
    }

    if (filters.min_score !== undefined) params.append('min_score', filters.min_score.toString());
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

    const response = await fetch(`${API_BASE_URL}/api/outliers?${params.toString()}`, {
        method: 'GET',
        headers
    });

    if (!response.ok) {
        throw new Error('Failed to fetch outliers');
    }

    const json = await response.json();
    return {
        data: json.data || [],
        status: json.status || 'unknown',
        current_page: json.current_page ?? 1,
        per_page: json.per_page ?? 20,
        total: json.total ?? 0,
        last_page: json.last_page ?? 1,
    };
}

export async function startSearchOutliers(term: string, exactMatch: boolean = false): Promise<{ message: string, status: string }> {
    let token = null;
    try {
        const session = localStorage.getItem('auth_session');
        if (session) {
            token = JSON.parse(session).token;
        }
    } catch (e) {
        console.error("Error parsing auth session", e);
    }

    const headers: Record<string, string> = {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
    };

    if (token) {
        headers['Authorization'] = `Bearer ${token}`;
    }

    const response = await fetch(`${API_BASE_URL}/api/outliers/search`, {
        method: 'POST',
        headers,
        body: JSON.stringify({ term, exact_match: exactMatch })
    });

    if (!response.ok) {
        throw new Error('Failed to start search');
    }

    return await response.json();
}
