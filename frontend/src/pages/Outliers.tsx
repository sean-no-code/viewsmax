import { useState, useMemo, useEffect, useRef, useCallback } from "react";
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Slider } from "@/components/ui/slider";
import { Checkbox } from "@/components/ui/checkbox";
import { Zap, Filter, Search, RotateCcw, ArrowUpDown, Loader2 } from "lucide-react";
import OutlierVideoCard, { OutlierVideoData } from "@/components/OutlierVideoCard";
import { searchOutliers } from "@/lib/outlier-service";
import { toast } from "sonner";

const PER_PAGE = 20;

// Helper to format date as YYYY/MM/DD
const formatDate = (date: Date): string => {
    const yyyy = date.getFullYear();
    const mm = String(date.getMonth() + 1).padStart(2, '0');
    const dd = String(date.getDate()).padStart(2, '0');
    return `${yyyy}/${mm}/${dd}`;
};

// Helper to format big numbers
const formatMetric = (num: number): string => {
    if (num >= 1000000) return (num / 1000000).toFixed(1) + "M";
    if (num >= 1000) return (num / 1000).toFixed(1) + "K";
    return num.toString();
};

const Outliers = () => {
    const [searchQuery, setSearchQuery] = useState("");
    const [videos, setVideos] = useState<OutlierVideoData[]>([]);
    const [loading, setLoading] = useState(false);
    const [hasSearched, setHasSearched] = useState(false);
    const [filtersChanged, setFiltersChanged] = useState(false);
    const [browsing, setBrowsing] = useState(true);

    // Pagination
    const [page, setPage] = useState(1);
    const [hasMore, setHasMore] = useState(false);
    const [totalResults, setTotalResults] = useState(0);
    const [loadingMore, setLoadingMore] = useState(false);
    const sentinelRef = useRef<HTMLDivElement | null>(null);

    // Filters
    const [minScore, setMinScore] = useState([20]); // Slider returns array (min score)
    const [subsRange, setSubsRange] = useState([0, 100]); // 0-10M mapped
    const [viewsRange, setViewsRange] = useState([0, 100]); // 0-10M mapped

    const [dateRange, setDateRange] = useState("all");
    const [sortBy, setSortBy] = useState("recent"); // 'score' | 'recent'
    const [exactMatch, setExactMatch] = useState(false);
    const [durationFilter, setDurationFilter] = useState("long"); // 'long' | 'shorts'

    const parseMetric = (val: string) => {
        if (!val) return 0;
        const num = parseFloat(val.replace(/[^0-9.]/g, ''));
        if (val.includes('M')) return num * 1000000;
        if (val.includes('K')) return num * 1000;
        return num;
    };

    const getPublishedAfter = useCallback(() => {
        if (dateRange === "today") return new Date(Date.now() - 24 * 60 * 60 * 1000).toISOString();
        if (dateRange === "week") return new Date(Date.now() - 7 * 24 * 60 * 60 * 1000).toISOString();
        if (dateRange === "month") return new Date(Date.now() - 30 * 24 * 60 * 60 * 1000).toISOString();
        return "";
    }, [dateRange]);

    const mapSortBy = useCallback((sort: string) => {
        if (sort === 'score') return 'score';
        if (sort === 'recent') return 'recent';
        return 'score';
    }, []);

    const buildFilters = useCallback((pageNum: number, queryOverride?: string, overrides?: Record<string, any>) => ({
        query: queryOverride ?? searchQuery,
        min_score: overrides?.min_score ?? minScore[0],
        min_subs: subsRange[0] * 100000,
        max_subs: subsRange[1] === 100 ? undefined : subsRange[1] * 100000,
        min_views: viewsRange[0] * 100000,
        max_views: viewsRange[1] === 100 ? undefined : viewsRange[1] * 100000,
        published_after: getPublishedAfter() || undefined,
        keyword_match: exactMatch ? searchQuery : undefined,
        duration_type: overrides?.duration_type ?? durationFilter,
        sort_by: overrides?.sort_by ?? mapSortBy(sortBy),
        page: pageNum,
        per_page: PER_PAGE,
    }), [searchQuery, minScore, subsRange, viewsRange, getPublishedAfter, exactMatch, durationFilter, sortBy, mapSortBy]);

    const mapVideo = (v: any): OutlierVideoData => ({
        id: v.youtube_video_id,
        title: v.title,
        thumbnail: v.thumbnail_medium_url || v.thumbnail_url,
        subscribers: v.channel?.subscriber_count ? formatMetric(v.channel.subscriber_count) : 'N/A',
        views: v.views ?? v.view_count ?? 0,
        publishedDate: v.published_at,
        outlierScore: typeof v.outlier_score === 'number' ? v.outlier_score : parseFloat(v.outlier_score as unknown as string) || 0,
        duration: v.duration || "0:00",
        channelName: v.channel?.channel_name || "Unknown Channel",
        channelAvatar: v.channel?.profile_image_url || "",
    });

    // Explicit search handler — triggered by Search button or Enter key
    const pollIntervalRef = useRef<NodeJS.Timeout | null>(null);
    const suppressFilterChangeRef = useRef(false);

    const handleSearch = useCallback(() => {
        // Clear any existing poll interval
        if (pollIntervalRef.current) { clearInterval(pollIntervalRef.current); pollIntervalRef.current = null; }

        if (!searchQuery.trim()) {
            // If browsing with empty query, refetch with current filters
            if (browsing) {
                setLoading(true);
                setPage(1);
                setFiltersChanged(false);
                searchOutliers(buildFilters(1, '')).then(response => {
                    setVideos(response.data.map(mapVideo));
                    setTotalResults(response.total);
                    setHasMore(response.current_page < response.last_page);
                }).catch(err => {
                    console.error(err);
                }).finally(() => {
                    setLoading(false);
                });
                return;
            }
            setVideos([]); setLoading(false); setTotalResults(0); setHasMore(false); setHasSearched(false); return;
        }
        if (searchQuery.length < 2 || !/[a-zA-Z0-9]/.test(searchQuery)) {
            if (searchQuery.length > 0) toast.error("Search must be at least 2 characters and contain letters or numbers.");
            setVideos([]); setLoading(false); setTotalResults(0); setHasMore(false); return;
        }

        const isFirstSearch = browsing;
        setBrowsing(false);
        if (isFirstSearch) {
            setSortBy('score');
        }
        setLoading(true);
        setPage(1);
        setHasMore(false);
        setTotalResults(0);
        setHasSearched(true);
        setFiltersChanged(false);

        const searchOverrides = isFirstSearch ? { sort_by: 'score' } : undefined;
        let attempts = 0;

        const pollResults = async () => {
            try {
                const response = await searchOutliers(buildFilters(1, undefined, searchOverrides));

                const mapped = response.data.map(mapVideo);

                setVideos(mapped);
                setPage(1);
                setTotalResults(response.total);
                setHasMore(response.current_page < response.last_page);

                if (response.status === 'done' || response.status === 'failed') {
                    setLoading(false);
                    if (pollIntervalRef.current) { clearInterval(pollIntervalRef.current); pollIntervalRef.current = null; }
                    if (response.status === 'failed' && mapped.length === 0) {
                        toast.error("Search job failed.");
                    }
                }
            } catch (err) {
                console.error(err);
            }
        };

        const startSearch = async () => {
            try {
                const { startSearchOutliers } = await import("@/lib/outlier-service");
                await startSearchOutliers(searchQuery, exactMatch);
            } catch (err) {
                console.error("Failed to start search", err);
            }

            pollResults();
            pollIntervalRef.current = setInterval(() => {
                attempts++;
                if (attempts > 1200) { setLoading(false); if (pollIntervalRef.current) clearInterval(pollIntervalRef.current); return; }
                pollResults();
            }, 3000);
        };

        startSearch();
    }, [searchQuery, exactMatch, buildFilters]); // eslint-disable-line react-hooks/exhaustive-deps

    // Cleanup poll interval on unmount
    useEffect(() => {
        return () => { if (pollIntervalRef.current) clearInterval(pollIntervalRef.current); };
    }, []);

    // Load recent outliers on mount
    useEffect(() => {
        let isMounted = true;
        const loadInitial = async () => {
            setLoading(true);
            try {
                const response = await searchOutliers(buildFilters(1, ''));
                if (!isMounted) return;
                setVideos(response.data.map(mapVideo));
                setTotalResults(response.total);
                setHasMore(response.current_page < response.last_page);
                setPage(1);
            } catch (err) {
                console.error('Failed to load outliers', err);
            } finally {
                if (isMounted) setLoading(false);
            }
        };
        loadInitial();
        return () => { isMounted = false; };
    }, []); // eslint-disable-line react-hooks/exhaustive-deps

    // Detect if filters have changed since the last search/browse
    const hasMountedRef = useRef(false);
    useEffect(() => {
        if (!hasMountedRef.current) {
            hasMountedRef.current = true;
            return;
        }
        if (suppressFilterChangeRef.current) {
            return;
        }
        setFiltersChanged(true);
    }, [minScore, subsRange, viewsRange, dateRange, exactMatch, durationFilter]); // eslint-disable-line react-hooks/exhaustive-deps

    // Search query changes mark filters as changed
    const queryMountedRef = useRef(false);
    useEffect(() => {
        if (!queryMountedRef.current) {
            queryMountedRef.current = true;
            return;
        }
        if (suppressFilterChangeRef.current) {
            return;
        }
        setFiltersChanged(true);
    }, [searchQuery]); // eslint-disable-line react-hooks/exhaustive-deps

    // Clear suppress flag after all effects have run
    useEffect(() => {
        if (suppressFilterChangeRef.current) {
            suppressFilterChangeRef.current = false;
        }
    });

    // Auto-refetch when sortBy changes (no need to click Update)
    useEffect(() => {
        if (browsing && !loading) {
            // Re-fetch browse results with the new sort
            const refetchBrowse = async () => {
                setLoading(true);
                setPage(1);
                try {
                    const response = await searchOutliers(buildFilters(1, ''));
                    setVideos(response.data.map(mapVideo));
                    setTotalResults(response.total);
                    setHasMore(response.current_page < response.last_page);
                } catch (err) {
                    console.error(err);
                } finally {
                    setLoading(false);
                }
            };
            refetchBrowse();
        } else if (hasSearched && !loading) {
            handleSearch();
        }
    }, [sortBy]); // eslint-disable-line react-hooks/exhaustive-deps


    // Effect 3: Load next page (infinite scroll trigger)
    useEffect(() => {
        if (page <= 1) return;
        if (!browsing && !searchQuery.trim()) return;

        let isMounted = true;

        const loadNextPage = async () => {
            setLoadingMore(true);
            try {
                const response = browsing
                    ? await searchOutliers(buildFilters(page, ''))
                    : await searchOutliers(buildFilters(page));

                if (!isMounted) return;

                const mapped = response.data.map(mapVideo);

                setVideos(prev => [...prev, ...mapped]);
                setTotalResults(response.total);
                setHasMore(response.current_page < response.last_page);
            } catch (err) {
                console.error(err);
            } finally {
                if (isMounted) setLoadingMore(false);
            }
        };

        loadNextPage();

        return () => { isMounted = false; };
    }, [page]); // eslint-disable-line react-hooks/exhaustive-deps

    // IntersectionObserver for infinite scroll sentinel
    useEffect(() => {
        const sentinel = sentinelRef.current;
        if (!sentinel) return;

        const observer = new IntersectionObserver(
            (entries) => {
                if (entries[0].isIntersecting && hasMore && !loadingMore && !loading) {
                    setPage(prev => prev + 1);
                }
            },
            { threshold: 0.1 }
        );

        observer.observe(sentinel);

        return () => { observer.disconnect(); };
    }, [hasMore, loadingMore, loading]);

    const filteredAndSortedVideos = useMemo(() => {
        return videos;
    }, [videos]);

    const resetFilters = async () => {
        if (pollIntervalRef.current) { clearInterval(pollIntervalRef.current); pollIntervalRef.current = null; }
        suppressFilterChangeRef.current = true;
        setFiltersChanged(false);
        setSearchQuery("");
        setMinScore([20]);
        setSubsRange([0, 100]);
        setViewsRange([0, 100]);
        setDateRange("all");
        setSortBy("recent");
        setExactMatch(false);
        setDurationFilter("long");
        setPage(1);
        setHasMore(false);
        setTotalResults(0);
        setHasSearched(false);
        setBrowsing(true);
        setLoading(true);
        try {
            const defaultFilters = {
                query: '',
                min_score: 20,
                min_subs: 0,
                min_views: 0,
                duration_type: 'long',
                sort_by: 'recent',
                page: 1,
                per_page: PER_PAGE,
            };
            const response = await searchOutliers(defaultFilters);
            setVideos(response.data.map(mapVideo));
            setTotalResults(response.total);
            setHasMore(response.current_page < response.last_page);
        } catch (err) {
            console.error('Failed to load outliers', err);
            setVideos([]);
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="space-y-6 animate-in fade-in duration-500">
            <Card>
                <CardHeader className="pb-3">
                    <div className="flex items-center justify-between">
                        <div>
                            <CardTitle>Filters</CardTitle>
                        </div>
                        <Button variant="outline" size="sm" onClick={resetFilters} className="h-8 gap-1">
                            <RotateCcw className="w-3.5 h-3.5" />
                            Reset
                        </Button>
                    </div>
                </CardHeader>
                <CardContent className="grid gap-6">
                    {/* Row 1: Search, Date, Video Type */}
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        {/* Search */}
                        <div className="space-y-2">
                            <label className="text-sm font-medium">Search</label>
                            <div className="relative">
                                <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    placeholder="Search terms ..."
                                    className="pl-8"
                                    value={searchQuery}
                                    onChange={(e) => setSearchQuery(e.target.value)}
                                    onKeyDown={(e) => { if (e.key === 'Enter') handleSearch(); }}
                                />
                                {loading && (
                                    <div className="absolute right-3 top-2.5">
                                        <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
                                    </div>
                                )}
                            </div>
                            <div className="flex items-center space-x-2 pt-1">
                                <Checkbox
                                    id="exact-match"
                                    checked={exactMatch}
                                    onCheckedChange={(checked) => setExactMatch(checked as boolean)}
                                />
                                <label
                                    htmlFor="exact-match"
                                    className="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70"
                                >
                                    Exact keyword match
                                </label>
                            </div>
                        </div>

                        {/* Date Filter */}
                        <div className="space-y-2">
                            <label className="text-sm font-medium">Published Date</label>
                            <Select value={dateRange} onValueChange={setDateRange}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Any time" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Any time</SelectItem>
                                    <SelectItem value="today">Last 24 hours</SelectItem>
                                    <SelectItem value="week">Last 7 days</SelectItem>
                                    <SelectItem value="month">Last 30 days</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        {/* Video Type Toggle */}
                        <div className="space-y-2">
                            <label className="text-sm font-medium">Video Type</label>
                            <div className="flex rounded-md border overflow-hidden">
                                <Button
                                    type="button"
                                    variant={durationFilter === 'long' ? 'default' : 'ghost'}
                                    size="sm"
                                    className={`flex-1 rounded-none ${durationFilter === 'long' ? '' : 'text-muted-foreground'}`}
                                    onClick={() => setDurationFilter('long')}
                                >
                                    Long
                                </Button>
                                <Button
                                    type="button"
                                    variant={durationFilter === 'shorts' ? 'default' : 'ghost'}
                                    size="sm"
                                    className={`flex-1 rounded-none ${durationFilter === 'shorts' ? '' : 'text-muted-foreground'}`}
                                    onClick={() => setDurationFilter('shorts')}
                                >
                                    Shorts
                                </Button>
                            </div>
                        </div>
                    </div>

                    {/* Row 2: Range Sliders */}
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6 pt-2 border-t">
                        {/* Subscribers Slider (Range) */}
                        <div className="space-y-4 pt-2">
                            <div className="flex items-center justify-between">
                                <label className="text-sm font-medium">Subscribers Range:
                                    <span className="text-primary font-bold ml-1">
                                        {formatMetric(subsRange[0] * 100000)} - {subsRange[1] === 100 ? '10M+' : formatMetric(subsRange[1] * 100000)}
                                    </span>
                                </label>
                            </div>
                            <Slider
                                value={subsRange}
                                onValueChange={setSubsRange}
                                max={100}
                                step={1}
                                minStepsBetweenThumbs={1}
                                className="w-full"
                            />
                        </div>

                        {/* Views Slider (Range) */}
                        <div className="space-y-4 pt-2">
                            <div className="flex items-center justify-between">
                                <label className="text-sm font-medium">Views Range:
                                    <span className="text-primary font-bold ml-1">
                                        {formatMetric(viewsRange[0] * 100000)} - {viewsRange[1] === 100 ? '10M+' : formatMetric(viewsRange[1] * 100000)}
                                    </span>
                                </label>
                            </div>
                            <Slider
                                value={viewsRange}
                                onValueChange={setViewsRange}
                                max={100}
                                step={1}
                                minStepsBetweenThumbs={1}
                                className="w-full"
                            />
                        </div>
                    </div>

                    {/* Outlier Score Slider */}
                    <div className="space-y-4 pt-2 border-t pt-4">
                        <div className="flex items-center justify-between">
                            <label className="text-sm font-medium">Min Outlier Score: <span className="text-primary font-bold">{minScore[0]}</span></label>
                        </div>
                        <Slider
                            value={minScore}
                            onValueChange={setMinScore}
                            min={20}
                            max={100}
                            step={1}
                            className="w-full"
                        />
                    </div>

                    {/* Search Button */}
                    <div className="flex justify-end pt-4 border-t">
                        <div className="flex flex-col items-center gap-1">
                            <Button
                                onClick={handleSearch}
                                disabled={loading}
                                className={`gap-2 transition-all duration-300 ${filtersChanged
                                    ? 'ring-2 ring-amber-400 ring-offset-2 ring-offset-background shadow-lg shadow-amber-400/20'
                                    : ''
                                    }`}
                            >
                                {loading ? <Loader2 className="h-4 w-4 animate-spin" /> : <Search className="h-4 w-4" />}
                                Search
                            </Button>
                            {filtersChanged && (
                                <span className="text-xs text-amber-500 font-medium animate-in fade-in duration-300">
                                    Filters updated
                                </span>
                            )}
                        </div>
                    </div>
                </CardContent>
            </Card>

            {/* Results Grid */}
            <div className="space-y-4">
                <div className="flex justify-between items-end">
                    <div className="text-sm text-muted-foreground pb-2">
                        {loading
                            ? (videos.length === 0 ? (browsing ? 'Loading outliers...' : 'Searching YouTube...') : 'Updating results...')
                            : `Showing ${filteredAndSortedVideos.length} of ${totalResults} results`
                        }
                    </div>

                    <div className="flex items-center gap-2">
                        <span className="text-sm font-medium">Sort by:</span>
                        <Select value={sortBy} onValueChange={setSortBy}>
                            <SelectTrigger className="w-[180px]">
                                <SelectValue placeholder="Sort order" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="recent">Recent</SelectItem>
                                <SelectItem value="score">Outlier Score</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                {loading && videos.length === 0 ? (
                    <div className="flex justify-center items-center py-20">
                        <Loader2 className="h-8 w-8 animate-spin text-primary" />
                    </div>
                ) : filteredAndSortedVideos.length > 0 ? (
                    <>
                        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
                            {filteredAndSortedVideos.map((video) => (
                                <OutlierVideoCard key={video.id} video={video} />
                            ))}
                        </div>

                        {/* Infinite scroll sentinel */}
                        <div ref={sentinelRef} className="flex justify-center py-6">
                            {loadingMore && (
                                <Loader2 className="h-6 w-6 animate-spin text-primary" />
                            )}
                            {!hasMore && filteredAndSortedVideos.length > 0 && totalResults > PER_PAGE && (
                                <p className="text-sm text-muted-foreground">All results loaded</p>
                            )}
                        </div>
                    </>
                ) : (
                    <div className="text-center py-20 bg-muted/20 rounded-lg border border-dashed">
                        <Filter className="w-10 h-10 mx-auto text-muted-foreground mb-3 opacity-50" />
                        <h3 className="text-lg font-medium">{hasSearched ? "No outliers found" : "No outliers available yet"}</h3>
                        <p className="text-muted-foreground">{hasSearched ? "Try adjusting your filters or search query." : "Search for a topic to discover outlier videos."}</p>
                        {hasSearched && (
                            <Button variant="link" onClick={resetFilters} className="mt-2">
                                Clear all filters
                            </Button>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
};

export default Outliers;
