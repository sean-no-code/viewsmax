import { useState, useEffect, useRef, useCallback, CSSProperties } from 'react';
import { useNavigate } from 'react-router-dom';
import { toast } from 'sonner';
import {
    Search, Users, Globe, ChevronDown, RotateCcw, Save, FolderOpen,
    TrendingUp, Loader2, Check, Eye, Heart, Plus,
} from 'lucide-react';
import { COUNTRIES, countryFlag, countryName } from '@/components/outliers/countries';
import { parseProfileInput } from '@/components/outliers/profile-input';
import {
    searchOutliers, startSearchOutliers, getOutlierChannels, fetchOutlierByUrl, toggleOutlierFeature,
    getSavedFilters, saveFilter, deleteSavedFilter, getLibrary, saveOutlier, deleteSavedOutlier,
    addOutlierChannel, getOutlierChannelIngest,
    type OutlierVideo, type OutlierFilters, type OutlierChannelOption, type SavedFilter, type AddedChannel, type OutlierPlatform,
} from '@/lib/outlier-service';
import OutlierCard from '@/components/outliers/OutlierCard';
import SaveOutlierModal from '@/components/outliers/SaveOutlierModal';
import { formatCompact, cardVariant } from '@/components/outliers/format';
import { useAuth } from '@/hooks/useAuth';

const CARD: CSSProperties = {
    background: 'var(--paper-0)',
    border: '1px solid var(--line-1)',
    borderRadius: 'var(--r-lg)',
    boxShadow: '0 1px 3px rgba(10,10,12,.05)',
};
const mono = 'var(--font-mono)';
const PER_PAGE = 24;

function videoKey(platform: string, id: string) { return `${platform}:${id}`; }

// The countries filter auto-saves per device and is restored on the next visit
// (unlike other filters, which reset) — no manual "Save filter" step needed.
const COUNTRIES_STORAGE_KEY = 'vm.outliers.countries';

function loadPersistedCountries(): string[] {
    try {
        const raw = localStorage.getItem(COUNTRIES_STORAGE_KEY);
        const parsed = raw ? JSON.parse(raw) : [];
        return Array.isArray(parsed) ? parsed.filter((c): c is string => typeof c === 'string') : [];
    } catch {
        return [];
    }
}

/** Which platform an added-video URL belongs to (browse is blended; the URL decides). */
function detectUrlPlatform(url: string): 'tiktok' | 'instagram' | 'youtube' | null {
    let host = '';
    try { host = new URL(url).hostname.toLowerCase(); } catch { return null; }
    if (host.endsWith('tiktok.com')) return 'tiktok';
    if (host.endsWith('instagram.com') || host.endsWith('instagr.am')) return 'instagram';
    if (host.endsWith('youtube.com') || host.endsWith('youtu.be')) return 'youtube';
    return null;
}

export default function Outliers() {
    const [query, setQuery] = useState('');
    const [exactMatch, setExactMatch] = useState(false);
    const [durationFilter, setDurationFilter] = useState<'long' | 'shorts'>('long');
    const [minScore, setMinScore] = useState<number>(20);
    const [subsMax, setSubsMax] = useState<number | undefined>(undefined);
    const [viewsMax, setViewsMax] = useState<number | undefined>(undefined);
    const [dateRange, setDateRange] = useState<'all' | 'week' | 'month' | 'year'>('all');
    const [sortBy, setSortBy] = useState<'recent' | 'score' | 'views'>('recent');

    // Channels multi-select
    const [selectedChannels, setSelectedChannels] = useState<string[]>([]);
    const [channelsOpen, setChannelsOpen] = useState(false);
    const [channelQuery, setChannelQuery] = useState('');
    const [channelOptions, setChannelOptions] = useState<OutlierChannelOption[]>([]);
    // "Add a creator channel" from the channel picker: a pasted profile URL /
    // @handle pulls the creator's recent videos in the background, then the
    // channel is selected as the filter.
    const [channelAdd, setChannelAdd] = useState<{ status: 'idle' | 'adding' | 'failed'; message?: string; handle?: string }>({ status: 'idle' });
    const addPollRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const profileInput = parseProfileInput(channelQuery);

    // Countries multi-select (auto-saved; restored on the next visit)
    const [selectedCountries, setSelectedCountries] = useState<string[]>(loadPersistedCountries);
    const [countriesOpen, setCountriesOpen] = useState(false);
    const [countryQuery, setCountryQuery] = useState('');

    // Data
    const [videos, setVideos] = useState<OutlierVideo[]>([]);
    const [loading, setLoading] = useState(false);
    const [loadingMore, setLoadingMore] = useState(false);
    const [page, setPage] = useState(1);
    const [hasMore, setHasMore] = useState(false);
    const [totalResults, setTotalResults] = useState(0);

    // Saved filters
    const [savedList, setSavedList] = useState<SavedFilter[]>([]);
    const [savedOpen, setSavedOpen] = useState(false);
    const [saveOpen, setSaveOpen] = useState(false);
    const [saveName, setSaveName] = useState('');

    // Library (bookmark state) + save modal
    const [savedMap, setSavedMap] = useState<Record<string, number>>({});
    const [pendingSave, setPendingSave] = useState<OutlierVideo | null>(null);

    const navigate = useNavigate();
    const { user } = useAuth();
    const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);
    const sentinelRef = useRef<HTMLDivElement | null>(null);

    // The untouched initial screen shows the admin-curated (featured) feed;
    // any search or filter interaction — or an empty curated list — drops to
    // the full browse feed.
    const [defaultFeed, setDefaultFeed] = useState(true);
    const [showingCurated, setShowingCurated] = useState(false);

    const buildFilters = useCallback((pageNum: number): OutlierFilters => {
        const f: OutlierFilters = {
            query,
            channels: selectedChannels.length ? selectedChannels : undefined,
            countries: selectedCountries.length ? selectedCountries : undefined,
            min_score: minScore,
            max_subs: subsMax,
            max_views: viewsMax,
            duration_type: durationFilter,
            sort_by: sortBy,
            page: pageNum,
            per_page: PER_PAGE,
        };
        if (exactMatch && query) f.keyword_match = query;
        if (dateRange !== 'all') {
            const days = dateRange === 'week' ? 7 : dateRange === 'month' ? 30 : 365;
            f.published_after = new Date(Date.now() - days * 86_400_000).toISOString();
        }
        // Pristine initial screen → curated feed (filters at their defaults only —
        // a restored country selection counts as a filter, so it browses instead).
        if (defaultFeed && !query.trim() && !selectedChannels.length && !selectedCountries.length && minScore === 20
            && subsMax === undefined && viewsMax === undefined && dateRange === 'all') {
            f.featured = true;
        }
        return f;
    }, [query, selectedChannels, selectedCountries, minScore, subsMax, viewsMax, durationFilter, sortBy, exactMatch, dateRange, defaultFeed]);

    const applyResults = (data: OutlierVideo[], pageNum: number, total: number, lastPage: number) => {
        setVideos((prev) => (pageNum === 1 ? data : [...prev, ...data]));
        setTotalResults(total);
        setHasMore(pageNum < lastPage);
        setPage(pageNum);
    };

    const runBrowse = useCallback(async (pageNum = 1) => {
        pageNum === 1 ? setLoading(true) : setLoadingMore(true);
        try {
            let f = buildFilters(pageNum);
            let res = await searchOutliers(f);
            // Nothing curated yet → quietly fall back to the full feed.
            if (f.featured && pageNum === 1 && res.total === 0) {
                setDefaultFeed(false);
                f = { ...f, featured: undefined };
                res = await searchOutliers(f);
            }
            setShowingCurated(!!f.featured);
            applyResults(res.data, pageNum, res.total, res.last_page);
        } catch (e) {
            toast.error(e instanceof Error ? e.message : 'Failed to load outliers');
        } finally {
            setLoading(false);
            setLoadingMore(false);
        }
    }, [buildFilters]);

    const browseAll = async () => {
        // Fetch directly — runBrowse's closure still sees the old defaultFeed state.
        setDefaultFeed(false);
        setLoading(true);
        try {
            const res = await searchOutliers({ ...buildFilters(1), featured: undefined });
            setShowingCurated(false);
            applyResults(res.data, 1, res.total, res.last_page);
        } catch (e) {
            toast.error(e instanceof Error ? e.message : 'Failed to load outliers');
        } finally {
            setLoading(false);
        }
    };

    /** Admin: star toggle on cards — adds/removes the video from the curated feed. */
    const toggleFeature = async (video: OutlierVideo) => {
        try {
            const featured = await toggleOutlierFeature(video.platform, video.youtube_video_id);
            setVideos((prev) => prev.map((v) =>
                v.platform === video.platform && v.youtube_video_id === video.youtube_video_id ? { ...v, featured } : v));
            toast.success(featured ? 'Added to the default feed' : 'Removed from the default feed');
        } catch (e) {
            toast.error(e instanceof Error ? e.message : 'Failed to update');
        }
    };

    const stopPoll = () => { if (pollRef.current) { clearInterval(pollRef.current); pollRef.current = null; } };

    const stopAddPoll = () => { if (addPollRef.current) { clearInterval(addPollRef.current); addPollRef.current = null; } };
    useEffect(() => stopAddPoll, []);

    /** A channel just landed (or was already fresh): select it and show its videos. */
    const applyAddedChannel = (channel: AddedChannel) => {
        const option: OutlierChannelOption = { id: channel.id, name: channel.name, avatar: channel.avatar, platform: channel.platform, subscriber_count: channel.subscriber_count };
        setChannelOptions((prev) => [option, ...prev.filter((c) => c.id !== option.id)]);
        setSelectedChannels([channel.id]);
        // Every recent video of the creator is stored, most far below the 20x gate —
        // scoped to one channel the point is to see them all, ranked.
        const nextDuration: 'long' | 'shorts' = channel.platform === 'youtube' ? durationFilter : 'shorts';
        setDurationFilter(nextDuration);
        setMinScore(1);
        setDefaultFeed(false);
        setChannelQuery('');
        setChannelsOpen(false);
        setChannelAdd({ status: 'idle' });
        // Explicit filters: this runs from a poll timer whose closure predates the
        // state above, and the refetch effect doesn't watch channels anyway.
        setLoading(true);
        searchOutliers({ ...buildFilters(1), query: '', channels: [channel.id], min_score: 1, duration_type: nextDuration, featured: undefined, page: 1 })
            .then((res) => { setShowingCurated(false); applyResults(res.data, 1, res.total, res.last_page); })
            .catch((e) => toast.error(e instanceof Error ? e.message : 'Failed to load outliers'))
            .finally(() => setLoading(false));
    };

    /** Kick off "add channel" for the picker's current input; `platform` disambiguates a bare @handle. */
    const startChannelAdd = async (platform?: OutlierPlatform) => {
        const parsed = parseProfileInput(channelQuery);
        const target = parsed?.platform ?? platform;
        if (!parsed || !target) return;
        stopAddPoll();
        setChannelAdd({ status: 'adding', handle: parsed.handle });
        setVideos([]); // the results area shows the pull spinner until the channel lands
        try {
            const res = await addOutlierChannel(channelQuery.trim(), target);
            if (!res.queued && res.channel) {
                toast.success(`${res.channel.name} is already in — showing their recent videos`);
                applyAddedChannel(res.channel);
                return;
            }
            const ingestId = res.ingest_id;
            if (!ingestId) throw new Error('Could not start the channel import');
            let attempts = 0;
            addPollRef.current = setInterval(async () => {
                attempts += 1;
                try {
                    const ingest = await getOutlierChannelIngest(ingestId);
                    if (ingest.status === 'done' && ingest.channel) {
                        stopAddPoll();
                        toast.success(`Pulled in ${ingest.videos_added} recent videos from @${ingest.handle}`);
                        applyAddedChannel(ingest.channel);
                    } else if (ingest.status === 'failed' || attempts > 100) {
                        stopAddPoll();
                        setChannelAdd({ status: 'failed', message: ingest.error || 'That took too long — try again in a minute.' });
                    }
                } catch (e) {
                    stopAddPoll();
                    setChannelAdd({ status: 'failed', message: e instanceof Error ? e.message : 'Could not add that channel' });
                }
            }, 3000);
        } catch (e) {
            setChannelAdd({ status: 'failed', message: e instanceof Error ? e.message : 'Could not add that channel' });
        }
    };

    const handleSearch = useCallback(async () => {
        stopPoll();
        // A creator profile / @handle belongs to the channel picker — hand it over
        // (previously this hit the video endpoint and errored).
        if (parseProfileInput(query)) {
            setChannelQuery(query.trim());
            setQuery('');
            setChannelsOpen(true);
            return;
        }
        // A pasted video URL (any platform) fetches that video and opens its breakdown.
        const trimmed = query.trim();
        const asUrl = trimmed.startsWith('www.') ? `https://${trimmed}` : trimmed;
        if (/^https?:\/\//i.test(asUrl)) {
            const urlPlatform = detectUrlPlatform(asUrl);
            if (!urlPlatform) {
                toast.error("That URL doesn't look like a YouTube, TikTok or Instagram video link.");
                return;
            }
            setLoading(true);
            try {
                // Parseable URLs queue a background download and we land on the
                // breakdown page's spinner right away; existing videos go straight there.
                const res = await fetchOutlierByUrl(urlPlatform, asUrl);
                navigate(
                    `/dashboard/outliers/breakdown/${urlPlatform}/${encodeURIComponent(res.video_id)}`,
                    res.queued ? { state: { ingestUrl: asUrl } } : undefined,
                );
            } catch (e) {
                toast.error(e instanceof Error ? e.message : 'Could not fetch that video');
            } finally {
                setLoading(false);
            }
            return;
        }
        // A keyword search kicks off an async YouTube scrape, then polls; empty query browses.
        if (query.trim()) {
            setLoading(true);
            setVideos([]);
            try {
                await startSearchOutliers(query.trim(), exactMatch);
            } catch (e) {
                toast.error(e instanceof Error ? e.message : 'Failed to start search');
            }
            let attempts = 0;
            const poll = async () => {
                attempts += 1;
                try {
                    const res = await searchOutliers(buildFilters(1));
                    applyResults(res.data, 1, res.total, res.last_page);
                    if (res.status === 'done' || res.status === 'failed' || attempts > 400) {
                        stopPoll();
                        setLoading(false);
                    }
                } catch {
                    stopPoll();
                    setLoading(false);
                }
            };
            poll();
            pollRef.current = setInterval(poll, 3000);
        } else {
            runBrowse(1);
        }
    }, [query, exactMatch, buildFilters, runBrowse, navigate]);

    // Initial load
    useEffect(() => {
        runBrowse(1);
        return stopPoll;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // Re-fetch when sort, the Long/Shorts toggle, or the country selection changes
    // (the toggle used to only swap the card shape — long videos sat in the Shorts
    // tab until Search). Effect deps, not setTimeout(runBrowse) — that closure is stale.
    useEffect(() => {
        if (query.trim() && pollRef.current) return;
        runBrowse(1);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [sortBy, durationFilter, selectedCountries]);

    // Countries auto-save: write through on every change so the next visit restores them.
    useEffect(() => {
        try {
            localStorage.setItem(COUNTRIES_STORAGE_KEY, JSON.stringify(selectedCountries));
        } catch { /* private mode etc. — filter still works for this visit */ }
    }, [selectedCountries]);

    // Load saved filters + library bookmark state once
    useEffect(() => {
        getSavedFilters().then(setSavedList).catch(() => {});
        getLibrary().then((items) => {
            const map: Record<string, number> = {};
            items.forEach((s) => { map[videoKey(s.platform, s.video_id)] = s.id; });
            setSavedMap(map);
        }).catch(() => {});
    }, []);

    // Channel options for the modal (all platforms, debounced). A pasted profile
    // URL / @handle is an "add channel" request, not a name filter.
    useEffect(() => {
        if (!channelsOpen || parseProfileInput(channelQuery)) return;
        const t = setTimeout(() => {
            getOutlierChannels(channelQuery).then(setChannelOptions).catch(() => setChannelOptions([]));
        }, 250);
        return () => clearTimeout(t);
    }, [channelsOpen, channelQuery]);

    // Infinite scroll
    useEffect(() => {
        const el = sentinelRef.current;
        if (!el) return;
        const obs = new IntersectionObserver((entries) => {
            if (entries[0].isIntersecting && hasMore && !loading && !loadingMore) {
                runBrowse(page + 1);
            }
        }, { threshold: 0.1 });
        obs.observe(el);
        return () => obs.disconnect();
    }, [hasMore, loading, loadingMore, page, runBrowse]);

    const toggleChannel = (id: string) => {
        setSelectedChannels((prev) => (prev.includes(id) ? prev.filter((c) => c !== id) : [...prev, id]));
    };

    const toggleCountry = (code: string) => {
        setSelectedCountries((prev) => (prev.includes(code) ? prev.filter((c) => c !== code) : [...prev, code]));
    };

    const resetFilters = () => {
        setQuery('');
        setExactMatch(false);
        setDurationFilter('long');
        setMinScore(20);
        setSubsMax(undefined);
        setViewsMax(undefined);
        setDateRange('all');
        setSortBy('recent');
        setSelectedChannels([]);
        setSelectedCountries([]); // the autosave effect clears the persisted copy too
        setTimeout(() => runBrowse(1), 0);
    };

    const handleSaveFilter = async () => {
        if (!saveName.trim()) return;
        try {
            const f = buildFilters(1);
            delete f.page; delete f.per_page;
            const saved = await saveFilter(saveName.trim(), f);
            setSavedList((prev) => [saved, ...prev]);
            setSaveName('');
            setSaveOpen(false);
            toast.success('Filter saved');
        } catch (e) {
            toast.error(e instanceof Error ? e.message : 'Failed to save filter');
        }
    };

    const loadFilter = (sf: SavedFilter) => {
        const f = sf.filters;
        // f.platform from older saved filters is ignored — browse is blended now.
        setQuery(f.query || '');
        setExactMatch(!!f.keyword_match);
        setDurationFilter((f.duration_type as 'long' | 'shorts') || 'long');
        setMinScore(f.min_score ?? 20);
        setSubsMax(f.max_subs);
        setViewsMax(f.max_views);
        setSortBy((f.sort_by as 'recent' | 'score' | 'views') || 'recent');
        setSelectedChannels(f.channels || []);
        setSelectedCountries(f.countries || []);
        setSavedOpen(false);
        setTimeout(() => runBrowse(1), 0);
    };

    const removeFilter = async (id: number, e: React.MouseEvent) => {
        e.stopPropagation();
        await deleteSavedFilter(id).catch(() => {});
        setSavedList((prev) => prev.filter((s) => s.id !== id));
    };

    /** Bookmark click: saved → remove; unsaved → open the tag/save modal. */
    const toggleSaveVideo = (video: OutlierVideo) => {
        const key = videoKey(video.platform, video.youtube_video_id);
        const existingId = savedMap[key];
        if (existingId) {
            setSavedMap((prev) => { const n = { ...prev }; delete n[key]; return n; });
            deleteSavedOutlier(existingId).catch(() => {
                setSavedMap((prev) => ({ ...prev, [key]: existingId }));
                toast.error('Failed to remove');
            });
            return;
        }
        setPendingSave(video);
    };

    const saveWithTags = async (video: OutlierVideo, tags: string[]) => {
        const key = videoKey(video.platform, video.youtube_video_id);
        try {
            const saved = await saveOutlier({
                platform: video.platform,
                video_id: video.youtube_video_id,
                tags,
                snapshot: {
                    title: video.title,
                    thumbnail_url: video.thumbnail_url,
                    thumbnail_medium_url: video.thumbnail_medium_url,
                    views: video.views,
                    like_count: video.like_count,
                    comment_count: video.comment_count,
                    outlier_score: video.outlier_score,
                    engagement_rate: video.engagement_rate,
                    duration: video.duration,
                    published_at: video.published_at,
                    channel_name: video.channel?.channel_name,
                    channel_avatar: video.channel?.profile_image_url,
                    subscriber_count: video.channel?.subscriber_count,
                    channel_average_views: video.channel?.average_views,
                    platform: video.platform,
                    is_short: video.is_short ?? null,
                },
            });
            setSavedMap((prev) => ({ ...prev, [key]: saved.id }));
            setPendingSave(null);
            toast.success(tags.length ? `Saved with ${tags.length} tag${tags.length > 1 ? 's' : ''}` : 'Saved to library');
        } catch (e) {
            toast.error(e instanceof Error ? e.message : 'Failed to save');
        }
    };

    const variant: 'long' | 'shorts' = durationFilter === 'shorts' ? 'shorts' : 'long';
    const gridCols = variant === 'shorts' ? 'repeat(auto-fill, minmax(220px,1fr))' : 'repeat(auto-fill, minmax(300px,1fr))';
    const pickedChannelNames = channelOptions.filter((c) => selectedChannels.includes(c.id)).map((c) => c.name);

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 20, fontFamily: 'var(--font-body)' }}>
            {/* Filter bar */}
            <div style={{ ...CARD, padding: '14px 16px', display: 'flex', flexDirection: 'column', gap: 10 }}>
                {/* Row 1 */}
                <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
                    <div style={{ flex: '1.5 1 260px', display: 'flex', alignItems: 'center', gap: 8, background: 'var(--paper-1)', border: '1px solid var(--line-2)', borderRadius: 'var(--r-md)', padding: '8px 12px' }}>
                        <Search size={16} stroke="#76767F" />
                        <input
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                            placeholder="Search titles, keywords, URLs…"
                            style={{ flex: 1, border: 'none', outline: 'none', background: 'transparent', fontSize: 14, color: 'var(--ink-on-paper-1)', minWidth: 60 }}
                        />
                        <label style={{ display: 'flex', alignItems: 'center', gap: 5, fontSize: 12.5, color: 'var(--ink-on-paper-3)', whiteSpace: 'nowrap', cursor: 'pointer' }}>
                            <input type="checkbox" checked={exactMatch} onChange={(e) => setExactMatch(e.target.checked)} style={{ accentColor: 'var(--vm-red)' }} />
                            Exact match
                        </label>
                    </div>

                    {/* Channels multi-select */}
                    <div style={{ position: 'relative' }}>
                        <button
                            onClick={() => setChannelsOpen((o) => !o)}
                            style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 12px', borderRadius: 'var(--r-md)', background: 'var(--paper-0)', cursor: 'pointer', minWidth: 160, border: `1px solid ${selectedChannels.length ? 'var(--vm-red)' : 'var(--line-2)'}` }}
                        >
                            <Users size={15} stroke="#76767F" />
                            <span style={{ fontSize: 13, color: 'var(--ink-on-paper-2)', fontWeight: selectedChannels.length ? 700 : 400, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                {selectedChannels.length === 0 ? 'All channels'
                                    : selectedChannels.length === 1 ? (pickedChannelNames[0] || '1 channel')
                                    : `${selectedChannels.length} channels`}
                            </span>
                            <ChevronDown size={14} style={{ marginLeft: 'auto' }} stroke="#76767F" />
                        </button>
                        {channelsOpen && (
                            <div style={{ position: 'absolute', top: 'calc(100% + 6px)', left: 0, zIndex: 30, minWidth: 260, ...CARD, boxShadow: '0 12px 32px -8px rgba(10,10,12,.25)', overflow: 'hidden' }}>
                                <div style={{ padding: 8, borderBottom: '1px solid var(--line-1)' }}>
                                    <input
                                        autoFocus
                                        value={channelQuery}
                                        onChange={(e) => { setChannelQuery(e.target.value); if (channelAdd.status === 'failed') setChannelAdd({ status: 'idle' }); }}
                                        onKeyDown={(e) => { if (e.key === 'Enter' && profileInput?.platform && channelAdd.status !== 'adding') startChannelAdd(); }}
                                        placeholder="Filter channels, or paste a channel URL / @handle"
                                        style={{ width: '100%', border: '1px solid var(--line-2)', borderRadius: 'var(--r-sm)', padding: '6px 8px', fontSize: 13, outline: 'none' }}
                                    />
                                </div>
                                {profileInput ? (
                                    <div style={{ padding: '4px 0' }}>
                                        {channelAdd.status === 'adding' ? (
                                            <div style={{ ...rowBtn(false), cursor: 'default', color: 'var(--ink-on-paper-2)', fontSize: 13 }}>
                                                <Loader2 size={15} className="animate-spin" />
                                                Pulling in @{profileInput.handle}'s last 10 videos…
                                            </div>
                                        ) : profileInput.platform ? (
                                            <button onClick={() => startChannelAdd()} style={rowBtn(false)}>
                                                <Plus size={15} stroke="#D60B27" />
                                                <span style={{ fontSize: 13, color: 'var(--ink-on-paper-1)' }}>
                                                    Add channel <strong>@{profileInput.handle}</strong>
                                                    <span style={{ color: 'var(--ink-on-paper-3)', textTransform: 'capitalize' }}> · {profileInput.platform}</span>
                                                </span>
                                            </button>
                                        ) : (
                                            <div style={{ padding: '6px 12px' }}>
                                                <div style={{ fontSize: 12.5, color: 'var(--ink-on-paper-2)', marginBottom: 6 }}>Add <strong>@{profileInput.handle}</strong> from:</div>
                                                <div style={{ display: 'flex', gap: 6 }}>
                                                    {(['youtube', 'tiktok', 'instagram'] as const).map((p) => (
                                                        <button key={p} onClick={() => startChannelAdd(p)} style={{ ...actionBtn, padding: '5px 10px', fontSize: 12.5, textTransform: 'capitalize' }}>{p}</button>
                                                    ))}
                                                </div>
                                            </div>
                                        )}
                                        {channelAdd.status === 'failed' && (
                                            <div style={{ padding: '6px 12px 8px', fontSize: 12.5, color: 'var(--vm-red-deep)' }}>{channelAdd.message}</div>
                                        )}
                                        <div style={{ padding: '6px 12px 8px', fontSize: 11.5, color: 'var(--ink-on-paper-3)' }}>
                                            Pulls their last 10 videos and scores them against each other.
                                        </div>
                                    </div>
                                ) : (
                                <div style={{ maxHeight: 230, overflowY: 'auto' }}>
                                    <button onClick={() => setSelectedChannels([])} style={rowBtn(false)}>
                                        <span style={{ fontSize: 13, color: 'var(--ink-on-paper-2)' }}>All channels</span>
                                    </button>
                                    {channelOptions.map((c) => {
                                        const picked = selectedChannels.includes(c.id);
                                        return (
                                            <button key={c.id} onClick={() => toggleChannel(c.id)} style={rowBtn(picked)}>
                                                {c.avatar
                                                    ? <img src={c.avatar} alt="" style={{ width: 22, height: 22, borderRadius: '50%', objectFit: 'cover' }} />
                                                    : <span style={{ width: 22, height: 22, borderRadius: '50%', background: 'var(--ink-800)' }} />}
                                                <span style={{ fontSize: 13, color: 'var(--ink-on-paper-1)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{c.name}</span>
                                                {picked && <Check size={15} stroke="#D60B27" style={{ marginLeft: 'auto' }} />}
                                            </button>
                                        );
                                    })}
                                    {channelOptions.length === 0 && (
                                        <div style={{ padding: 12, fontSize: 12.5, color: 'var(--ink-on-paper-3)' }}>No channels match — paste a channel URL or @handle to add one.</div>
                                    )}
                                </div>
                                )}
                            </div>
                        )}
                    </div>

                    {/* Countries multi-select (auto-saved) */}
                    <div style={{ position: 'relative' }}>
                        <button
                            onClick={() => setCountriesOpen((o) => !o)}
                            style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 12px', borderRadius: 'var(--r-md)', background: 'var(--paper-0)', cursor: 'pointer', minWidth: 150, border: `1px solid ${selectedCountries.length ? 'var(--vm-red)' : 'var(--line-2)'}` }}
                        >
                            <Globe size={15} stroke="#76767F" />
                            <span style={{ fontSize: 13, color: 'var(--ink-on-paper-2)', fontWeight: selectedCountries.length ? 700 : 400, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                {selectedCountries.length === 0 ? 'All countries'
                                    : selectedCountries.length === 1 ? `${countryFlag(selectedCountries[0])} ${countryName(selectedCountries[0])}`
                                    : `${selectedCountries.length} countries`}
                            </span>
                            <ChevronDown size={14} style={{ marginLeft: 'auto' }} stroke="#76767F" />
                        </button>
                        {countriesOpen && (
                            <div style={{ position: 'absolute', top: 'calc(100% + 6px)', left: 0, zIndex: 30, minWidth: 250, ...CARD, boxShadow: '0 12px 32px -8px rgba(10,10,12,.25)', overflow: 'hidden' }}>
                                <div style={{ padding: 8, borderBottom: '1px solid var(--line-1)' }}>
                                    <input autoFocus value={countryQuery} onChange={(e) => setCountryQuery(e.target.value)} placeholder="Filter countries…" style={{ width: '100%', border: '1px solid var(--line-2)', borderRadius: 'var(--r-sm)', padding: '6px 8px', fontSize: 13, outline: 'none' }} />
                                </div>
                                <div style={{ maxHeight: 230, overflowY: 'auto' }}>
                                    <button onClick={() => setSelectedCountries([])} style={rowBtn(false)}>
                                        <span style={{ fontSize: 13, color: 'var(--ink-on-paper-2)' }}>All countries</span>
                                    </button>
                                    {COUNTRIES
                                        .filter((c) => !countryQuery.trim()
                                            || c.name.toLowerCase().includes(countryQuery.trim().toLowerCase())
                                            || c.code.toLowerCase() === countryQuery.trim().toLowerCase())
                                        .map((c) => {
                                            const picked = selectedCountries.includes(c.code);
                                            return (
                                                <button key={c.code} onClick={() => toggleCountry(c.code)} style={rowBtn(picked)}>
                                                    <span style={{ fontSize: 16, lineHeight: 1 }}>{countryFlag(c.code)}</span>
                                                    <span style={{ fontSize: 13, color: 'var(--ink-on-paper-1)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{c.name}</span>
                                                    {picked && <Check size={15} stroke="#D60B27" style={{ marginLeft: 'auto' }} />}
                                                </button>
                                            );
                                        })}
                                </div>
                                <div style={{ padding: '8px 12px', borderTop: '1px solid var(--line-1)', fontSize: 11.5, color: 'var(--ink-on-paper-3)' }}>
                                    Channel country (YouTube only) · selection is remembered
                                </div>
                            </div>
                        )}
                    </div>

                    {/* Posted date */}
                    <select value={dateRange} onChange={(e) => { setDateRange(e.target.value as typeof dateRange); setTimeout(() => runBrowse(1), 0); }}
                        style={{ display: 'flex', alignItems: 'center', border: '1px solid var(--line-2)', borderRadius: 'var(--r-md)', padding: '8px 10px', fontSize: 13, color: 'var(--ink-on-paper-2)', background: 'var(--paper-0)', cursor: 'pointer' }}>
                        <option value="all">Posted: Any time</option>
                        <option value="week">Past week</option>
                        <option value="month">Past month</option>
                        <option value="year">Past year</option>
                    </select>

                    {/* Long / Shorts */}
                    <div style={{ display: 'flex', border: '1px solid var(--line-2)', borderRadius: 'var(--r-md)', overflow: 'hidden' }}>
                        {(['long', 'shorts'] as const).map((d) => {
                            const active = durationFilter === d;
                            return (
                                <button key={d} onClick={() => setDurationFilter(d)} /* refetch happens in the [sortBy, durationFilter] effect — a direct runBrowse here used the stale closure and fetched the OLD tab */
                                    style={{ padding: '8px 16px', fontSize: 13, fontWeight: active ? 700 : 400, cursor: 'pointer', border: 'none', textTransform: 'capitalize', background: active ? 'var(--vm-red)' : 'var(--paper-0)', color: active ? '#fff' : 'var(--ink-on-paper-2)' }}>
                                    {d === 'long' ? 'Long' : 'Shorts'}
                                </button>
                            );
                        })}
                    </div>
                </div>

                {/* Row 2 — metric chips */}
                <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'center' }}>
                    <MetricChip label="Max subs" value={subsMax != null ? `${formatCompact(subsMax)}` : 'Any'} onChange={setSubsMax} current={subsMax} />
                    <MetricChip label="Max views" value={viewsMax != null ? `${formatCompact(viewsMax)}` : 'Any'} onChange={setViewsMax} current={viewsMax} />
                    <div style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '6px 12px', borderRadius: 'var(--r-pill)', border: '1px solid var(--vm-red)', background: 'var(--vm-red-tint-l)' }}>
                        <span style={{ fontSize: 12, color: 'var(--vm-red-deep)', fontWeight: 700 }}>Score ≥</span>
                        <input type="number" value={minScore} min={0} onChange={(e) => setMinScore(Number(e.target.value) || 0)}
                            style={{ width: 46, border: 'none', background: 'transparent', fontFamily: mono, fontWeight: 700, color: 'var(--vm-red-deep)', outline: 'none' }} />
                    </div>
                </div>

                {/* Row 3 — actions */}
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, borderTop: '1px solid var(--line-1)', paddingTop: 10, flexWrap: 'wrap' }}>
                    {/* Saved filters loader */}
                    <div style={{ position: 'relative', marginLeft: 'auto' }}>
                        <button onClick={() => { setSavedOpen((o) => !o); setSaveOpen(false); }} style={actionBtn}>
                            <FolderOpen size={14} /> Saved filters <ChevronDown size={13} />
                        </button>
                        {savedOpen && (
                            <div style={{ position: 'absolute', top: 'calc(100% + 6px)', right: 0, zIndex: 30, width: 240, ...CARD, boxShadow: '0 12px 32px -8px rgba(10,10,12,.25)', overflow: 'hidden' }}>
                                {savedList.length === 0 ? (
                                    <div style={{ padding: 14, fontSize: 12.5, color: 'var(--ink-on-paper-3)' }}>Nothing saved yet — set filters and hit Save.</div>
                                ) : savedList.map((sf) => (
                                    <button key={sf.id} onClick={() => loadFilter(sf)} style={{ ...rowBtn(false), justifyContent: 'space-between' }}>
                                        <span style={{ display: 'flex', alignItems: 'center', gap: 8, overflow: 'hidden' }}>
                                            <TrendingUp size={14} stroke="var(--vm-red)" />
                                            <span style={{ fontSize: 13, color: 'var(--ink-on-paper-1)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{sf.name}</span>
                                        </span>
                                        <span onClick={(e) => removeFilter(sf.id, e)} style={{ fontSize: 16, color: 'var(--ink-on-paper-3)', cursor: 'pointer', lineHeight: 1 }}>×</span>
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>

                    {/* Save */}
                    <div style={{ position: 'relative' }}>
                        <button onClick={() => { setSaveOpen((o) => !o); setSavedOpen(false); }} style={actionBtn}>
                            <Save size={14} /> Save
                        </button>
                        {saveOpen && (
                            <div style={{ position: 'absolute', top: 'calc(100% + 6px)', right: 0, zIndex: 30, width: 250, ...CARD, boxShadow: '0 12px 32px -8px rgba(10,10,12,.25)', padding: 12 }}>
                                <div style={{ fontSize: 9, letterSpacing: '.12em', textTransform: 'uppercase', color: 'var(--ink-on-paper-3)', fontWeight: 700, marginBottom: 6 }}>Name this filter</div>
                                <input autoFocus value={saveName} onChange={(e) => setSaveName(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && handleSaveFilter()}
                                    style={{ width: '100%', border: '1px solid var(--line-2)', borderRadius: 'var(--r-sm)', padding: '7px 9px', fontSize: 13, outline: 'none', marginBottom: 8 }} />
                                <button onClick={handleSaveFilter} style={{ width: '100%', padding: '8px', borderRadius: 'var(--r-md)', border: 'none', background: 'var(--vm-red)', color: '#fff', fontWeight: 700, cursor: 'pointer' }}>Save filter</button>
                            </div>
                        )}
                    </div>

                    <div style={{ width: 1, height: 22, background: 'var(--line-1)' }} />
                    <button onClick={resetFilters} style={actionBtn}><RotateCcw size={14} /> Reset</button>
                    <button onClick={handleSearch} style={{ padding: '10px 22px', borderRadius: 'var(--r-pill)', border: 'none', background: 'var(--vm-red)', color: '#fff', fontWeight: 700, fontSize: 14, cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 6 }}>
                        {loading ? <Loader2 size={15} className="animate-spin" /> : <Search size={15} />} Search
                    </button>
                </div>
            </div>

            {/* Results header */}
            <div style={{ display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
                <span style={{ fontSize: 13, color: 'var(--ink-on-paper-2)' }}>
                    Showing <span style={{ fontFamily: mono, fontWeight: 700 }}>{videos.length}</span> of <span style={{ fontFamily: mono, fontWeight: 700 }}>{totalResults}</span> results
                </span>
                <div style={{ display: 'flex', alignItems: 'center', gap: 14, paddingLeft: 12, borderLeft: '1px solid var(--line-1)', fontSize: 12, color: 'var(--ink-on-paper-3)' }}>
                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}><TrendingUp size={13} stroke="#0FB67E" /> Score</span>
                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}><Eye size={13} /> Views</span>
                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}><Heart size={13} /> Engagement</span>
                </div>
                <div style={{ marginLeft: 'auto', display: 'flex', alignItems: 'center', gap: 8 }}>
                    <span style={{ fontSize: 12.5, color: 'var(--ink-on-paper-3)' }}>Sort by</span>
                    <select value={sortBy} onChange={(e) => setSortBy(e.target.value as typeof sortBy)}
                        style={{ border: '1px solid var(--line-2)', borderRadius: 'var(--r-md)', padding: '6px 10px', fontSize: 13, background: 'var(--paper-0)', cursor: 'pointer' }}>
                        <option value="recent">Recent</option>
                        <option value="score">Outlier score</option>
                        <option value="views">Views</option>
                    </select>
                </div>
            </div>

            {/* Curated-feed banner: the initial screen shows hand-picked videos */}
            {showingCurated && videos.length > 0 && (
                <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                    <span style={{ fontFamily: mono, fontSize: 11, letterSpacing: '.08em', textTransform: 'uppercase', color: 'var(--ink-on-paper-3)' }}>
                        Curated picks
                    </span>
                    <button onClick={browseAll} style={{ border: 'none', background: 'none', cursor: 'pointer', padding: 0, fontFamily: 'var(--font-body)', fontWeight: 700, fontSize: 12.5, color: 'var(--vm-red)' }}>
                        Browse all outliers →
                    </button>
                </div>
            )}

            {/* Grid */}
            {channelAdd.status === 'adding' && videos.length === 0 ? (
                <div style={{ ...CARD, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 12, padding: 60, color: 'var(--ink-on-paper-3)' }}>
                    <Loader2 size={28} className="animate-spin" />
                    <div style={{ fontSize: 14, color: 'var(--ink-on-paper-2)' }}>
                        Pulling in <strong>@{channelAdd.handle}</strong>'s last 10 videos…
                    </div>
                    <div style={{ fontSize: 12.5 }}>Instagram profiles can take a minute or two to scrape.</div>
                </div>
            ) : loading && videos.length === 0 ? (
                <div style={{ display: 'flex', justifyContent: 'center', padding: 60, color: 'var(--ink-on-paper-3)' }}><Loader2 size={28} className="animate-spin" /></div>
            ) : videos.length === 0 ? (
                <div style={{ ...CARD, padding: 48, textAlign: 'center', color: 'var(--ink-on-paper-3)' }}>
                    No outliers yet — search a topic to discover breakout videos, paste a YouTube/TikTok/Instagram video URL into the search bar, or add a creator's channel from the Channels menu.
                </div>
            ) : (
                <div style={{ display: 'grid', gridTemplateColumns: gridCols, gap: 20 }}>
                    {videos.map((v) => (
                        <OutlierCard
                            key={`${v.platform}-${v.youtube_video_id}`}
                            video={v}
                            variant={cardVariant(v)}
                            saved={!!savedMap[videoKey(v.platform, v.youtube_video_id)]}
                            onToggleSave={toggleSaveVideo}
                            onToggleFeature={user?.is_admin ? toggleFeature : undefined}
                        />
                    ))}
                </div>
            )}

            {/* Infinite-scroll sentinel */}
            <div ref={sentinelRef} style={{ height: 1 }} />
            {loadingMore && <div style={{ display: 'flex', justifyContent: 'center', padding: 20, color: 'var(--ink-on-paper-3)' }}><Loader2 size={20} className="animate-spin" /></div>}

            {/* Save-with-tags modal */}
            {pendingSave && (
                <SaveOutlierModal
                    video={pendingSave}
                    onSave={(tags) => saveWithTags(pendingSave, tags)}
                    onClose={() => setPendingSave(null)}
                />
            )}
        </div>
    );
}

const actionBtn: CSSProperties = {
    display: 'flex', alignItems: 'center', gap: 6, padding: '8px 12px', borderRadius: 'var(--r-pill)',
    border: '1px solid var(--line-2)', background: 'var(--paper-0)', color: 'var(--ink-on-paper-2)',
    fontSize: 13, cursor: 'pointer',
};

function rowBtn(active: boolean): CSSProperties {
    return {
        width: '100%', display: 'flex', alignItems: 'center', gap: 8, padding: '8px 12px',
        border: 'none', cursor: 'pointer', textAlign: 'left',
        background: active ? 'var(--vm-red-tint-l)' : 'transparent',
    };
}

function MetricChip({ label, value, onChange, current }: { label: string; value: string; onChange: (v: number | undefined) => void; current: number | undefined }) {
    const [open, setOpen] = useState(false);
    const [draft, setDraft] = useState(current?.toString() ?? '');
    return (
        <div style={{ position: 'relative' }}>
            <button onClick={() => setOpen((o) => !o)}
                style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '6px 12px', borderRadius: 'var(--r-pill)', border: '1px solid var(--line-2)', background: 'var(--paper-0)', cursor: 'pointer', fontFamily: mono }}>
                <span style={{ fontSize: 12, color: 'var(--ink-on-paper-3)', fontFamily: 'var(--font-body)' }}>{label}</span>
                <span style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--ink-on-paper-1)' }}>{value}</span>
                <ChevronDown size={13} stroke="#76767F" />
            </button>
            {open && (
                <div style={{ position: 'absolute', top: 'calc(100% + 6px)', left: 0, zIndex: 30, width: 190, ...CARD, boxShadow: '0 12px 32px -8px rgba(10,10,12,.25)', padding: 12 }}>
                    <div style={{ fontSize: 12, color: 'var(--ink-on-paper-3)', marginBottom: 6 }}>{label} (leave blank for any)</div>
                    <input type="number" value={draft} min={0} placeholder="e.g. 1000000" onChange={(e) => setDraft(e.target.value)}
                        style={{ width: '100%', border: '1px solid var(--line-2)', borderRadius: 'var(--r-sm)', padding: '7px 9px', fontSize: 13, outline: 'none', marginBottom: 8 }} />
                    <button onClick={() => { onChange(draft.trim() === '' ? undefined : Number(draft)); setOpen(false); }}
                        style={{ width: '100%', padding: '7px', borderRadius: 'var(--r-md)', border: 'none', background: 'var(--vm-red)', color: '#fff', fontWeight: 700, cursor: 'pointer', fontSize: 13 }}>Apply</button>
                </div>
            )}
        </div>
    );
}
