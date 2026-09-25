import { useState, useEffect, useRef, useCallback, CSSProperties } from 'react';
import { useParams, useLocation } from 'react-router-dom';
import { RotateCcw, ExternalLink, Bookmark, Play, Loader2 } from 'lucide-react';
import { toast } from 'sonner';
import {
    getOutlier, getOutlierBreakdown, requestOutlierBreakdown, getLibrary, saveOutlier, deleteSavedOutlier, fetchOutlierByUrl,
    getOutlierCompetitors, addOutlierCompetitor, removeOutlierCompetitor, refreshOutlierMedia,
    type OutlierVideo, type OutlierBreakdown as Breakdown, type BreakdownPayload, type BreakdownStructureBeat, type OutlierPlatform,
} from '@/lib/outlier-service';
import SaveOutlierModal from '@/components/outliers/SaveOutlierModal';
import { formatCompact, formatDuration, formatScore, formatBeatTime, timeAgo, isShortVideo, outlierUrl, bestThumbnail, fallbackThumbnail } from '@/components/outliers/format';

const TABS = ['Transcript', 'Idea analysis', 'Hook', 'Storytelling format', 'Visual layout'] as const;
type Tab = typeof TABS[number];

const eyebrow: CSSProperties = {
    fontFamily: 'var(--font-body)', fontWeight: 700, fontSize: 12, lineHeight: 1,
    letterSpacing: '.14em', textTransform: 'uppercase',
};
const mono = 'var(--font-mono)';
const panelCard: CSSProperties = {
    background: 'var(--paper-0)', border: '1px solid var(--line-1)', borderRadius: 'var(--r-lg)', padding: '18px 20px',
};

const POLL_MS = 3000;

/** Loader checklist from the Shorts Breakdown design. Steps are advanced on a
 *  timer (the queued job reports no granular progress) and hold on the last
 *  step until the poll reports completed. */
const GEN_STEPS = [
    'Pulling the transcript',
    'Breaking down the hook',
    'Analysing the idea',
    'Mapping the story beats',
    'Scoring the visual layout',
];
const STEP_MS = 3500;

export default function OutlierBreakdown() {
    const { platform = 'youtube', videoId = '' } = useParams();
    // Set when the search bar queued a background download for this video —
    // the page then polls the video in and can retry the download on failure.
    const ingestUrl = (useLocation().state as { ingestUrl?: string } | null)?.ingestUrl;
    const [ingestNonce, setIngestNonce] = useState(0);
    const [video, setVideo] = useState<OutlierVideo | null>(null);
    const [videoError, setVideoError] = useState<string | null>(null);
    const [breakdown, setBreakdown] = useState<Breakdown | null>(null);
    const [tab, setTab] = useState<Tab>('Hook');
    const [copied, setCopied] = useState(false);
    const [stepIdx, setStepIdx] = useState(0);
    const [savedId, setSavedId] = useState<number | null>(null);
    const [saveOpen, setSaveOpen] = useState(false);
    const pollRef = useRef<ReturnType<typeof setInterval> | null>(null);

    const stopPoll = () => { if (pollRef.current) { clearInterval(pollRef.current); pollRef.current = null; } };

    const poll = useCallback(async () => {
        try {
            const b = await getOutlierBreakdown(platform, videoId);
            setBreakdown(b);
            if (b.status === 'completed' || b.status === 'failed') stopPoll();
        } catch {
            stopPoll();
        }
    }, [platform, videoId]);

    const startGeneration = useCallback(async () => {
        setStepIdx(0);
        // No video yet + we came from a queued download → the download itself
        // failed; retry it and resume polling rather than requesting a breakdown.
        if (!video && ingestUrl) {
            setBreakdown(null);
            try {
                await fetchOutlierByUrl(platform as OutlierPlatform, ingestUrl);
                setIngestNonce((n) => n + 1);
            } catch (e) {
                setBreakdown({ status: 'failed', payload: null, error: e instanceof Error ? e.message : 'Could not start the download' });
            }
            return;
        }
        try {
            const b = await requestOutlierBreakdown(platform, videoId);
            setBreakdown(b);
            stopPoll();
            pollRef.current = setInterval(poll, POLL_MS);
        } catch (e) {
            setBreakdown({ status: 'failed', payload: null, error: e instanceof Error ? e.message : 'Could not start generation' });
        }
    }, [platform, videoId, poll, video, ingestUrl]);

    // Video meta. Mid-download the video 404s: keep polling until it lands, the
    // ingest job records a failure on the breakdown row, or we give up (5 min).
    useEffect(() => {
        let cancelled = false;
        let timer: ReturnType<typeof setTimeout> | null = null;
        const startedAt = Date.now();
        const load = async () => {
            try {
                const v = await getOutlier(platform, videoId);
                if (!cancelled) setVideo(v);
            } catch (e) {
                if (cancelled) return;
                if (ingestUrl && Date.now() - startedAt < 5 * 60_000) {
                    try {
                        const b = await getOutlierBreakdown(platform, videoId);
                        if (b.status === 'failed') { setBreakdown(b); return; }
                    } catch { /* transient — keep polling */ }
                    timer = setTimeout(load, POLL_MS);
                    return;
                }
                setVideoError(e instanceof Error ? e.message : 'Video not found');
            }
        };
        load();
        return () => { cancelled = true; if (timer) clearTimeout(timer); };
    }, [platform, videoId, ingestUrl, ingestNonce]);

    // Instagram rows ingested from a sparse provider response have no views/score —
    // backfill silently on load (refresh-media only hits the provider when needed).
    useEffect(() => {
        if (!video || video.platform !== 'instagram' || video.views != null) return;
        let cancelled = false;
        refreshOutlierMedia('instagram', videoId)
            .then((fresh) => { if (!cancelled && fresh.views != null) setVideo(fresh); })
            .catch(() => {});
        return () => { cancelled = true; };
    }, [video?.id, video?.platform, video?.views, videoId]);

    // Library bookmark state for this video
    useEffect(() => {
        getLibrary().then((items) => {
            const hit = items.find((s) => s.platform === platform && s.video_id === videoId);
            setSavedId(hit ? hit.id : null);
        }).catch(() => {});
    }, [platform, videoId]);

    // Competitor state for this video's channel
    const [isCompetitor, setIsCompetitor] = useState(false);
    useEffect(() => {
        if (!video?.channel_id) return;
        getOutlierCompetitors()
            .then((list) => setIsCompetitor(list.some((c) => c.channel_id === video.channel_id)))
            .catch(() => {});
    }, [video?.channel_id]);

    const toggleCompetitor = () => {
        if (!video?.channel_id) return;
        const id = video.channel_id;
        if (isCompetitor) {
            setIsCompetitor(false);
            removeOutlierCompetitor(id).catch(() => { setIsCompetitor(true); toast.error('Failed to remove competitor'); });
        } else {
            setIsCompetitor(true);
            addOutlierCompetitor(id)
                .then(() => toast.success(`${video.channel?.channel_name ?? 'Channel'} added as competitor`))
                .catch(() => { setIsCompetitor(false); toast.error('Failed to add competitor'); });
        }
    };

    /** Bookmark click: saved → remove; unsaved → open the tag/save modal. */
    const toggleSave = () => {
        if (savedId != null) {
            const id = savedId;
            setSavedId(null);
            deleteSavedOutlier(id).catch(() => {
                setSavedId(id);
                toast.error('Failed to remove');
            });
            return;
        }
        setSaveOpen(true);
    };

    const saveWithTags = async (tags: string[]) => {
        if (!video) return;
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
                },
            });
            setSavedId(saved.id);
            setSaveOpen(false);
            toast.success(tags.length ? `Saved with ${tags.length} tag${tags.length > 1 ? 's' : ''}` : 'Saved to library');
        } catch (e) {
            toast.error(e instanceof Error ? e.message : 'Failed to save');
        }
    };

    // Breakdown: load, auto-generate when missing, poll while running.
    // Waits for the video — mid-download there is nothing to generate against.
    useEffect(() => {
        if (!video) return;
        let cancelled = false;
        getOutlierBreakdown(platform, videoId).then((b) => {
            if (cancelled) return;
            setBreakdown(b);
            if (b.status === 'none') startGeneration();
            else if (b.status === 'pending' || b.status === 'processing') pollRef.current = setInterval(poll, POLL_MS);
        }).catch(() => { if (!cancelled) setBreakdown({ status: 'failed', payload: null, error: 'Failed to load breakdown' }); });
        return () => { cancelled = true; stopPoll(); };
    }, [video, platform, videoId, poll, startGeneration]);

    const payload = breakdown?.status === 'completed' ? breakdown.payload : null;
    const generating = !videoError && !payload && breakdown?.status !== 'failed';
    const genSteps = ingestUrl ? ['Downloading the video', ...GEN_STEPS] : GEN_STEPS;

    // Advance the loader checklist while the job runs (holds on the last step);
    // holds on "Downloading the video" until the video actually lands.
    useEffect(() => {
        if (!generating || (ingestUrl && !video)) return;
        const t = setInterval(() => setStepIdx((i) => Math.min(i + 1, genSteps.length - 1)), STEP_MS);
        return () => clearInterval(t);
    }, [generating, ingestUrl, video, genSteps.length]);

    const isIG = platform === 'instagram';
    // One rule for the whole UI (backend classification → platform → duration fallback).
    // While the video is still loading: non-YouTube is short-form; a pasted /shorts/ URL is too.
    const isShorts = video ? isShortVideo(video) : (platform !== 'youtube' || /\/shorts\//.test(ingestUrl ?? ''));
    const handle = video?.channel?.channel_name || '';
    const avg = video?.channel?.average_views ?? null;
    const mult = avg && avg > 0 && video?.views ? video.views / avg : null;
    const durationLabel = video ? formatDuration(video.duration) : null;

    const copyTranscript = () => {
        if (!payload) return;
        const text = payload.transcript.map((l) => `${l.time} ${l.text}`).join('\n');
        navigator.clipboard.writeText(text);
        setCopied(true);
        setTimeout(() => setCopied(false), 1600);
    };

    if (videoError) {
        return (
            <div style={{ ...panelCard, margin: '40px auto', maxWidth: 480, textAlign: 'center', color: 'var(--ink-on-paper-3)', fontFamily: 'var(--font-body)' }}>
                {videoError}
            </div>
        );
    }

    // The design's loading sequence takes over the page until the breakdown lands.
    if (generating) {
        return <GeneratingScreen stepIdx={stepIdx} steps={genSteps} />;
    }

    return (
        <div style={{ maxWidth: 1200, margin: '0 auto', fontFamily: 'var(--font-body)', animation: 'vm-page-in .45s var(--ease-out) both' }}>
            {/* Header */}
            <div style={{ display: 'flex', alignItems: 'flex-start', gap: 16, marginBottom: 22 }}>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 5 }}>
                    <span style={{ ...eyebrow, color: 'var(--vm-red)' }}>Breakdown</span>
                    <span style={{ fontFamily: 'var(--font-display)', fontWeight: 900, fontSize: 34, letterSpacing: '-0.03em', lineHeight: 1, color: 'var(--ink-on-paper-1)' }}>
                        {video?.title || '…'}
                    </span>
                    <span style={{ fontSize: 13, fontWeight: 600, color: 'var(--ink-on-paper-3)' }}>
                        {handle}{video ? ` · ${isShorts ? 'Short' : 'Long form'}` : ''}{durationLabel ? ` · ${durationLabel}` : ''}{video?.published_at ? ` · ${timeAgo(video.published_at)}` : ''}
                    </span>
                </div>
                <div style={{ marginLeft: 'auto', display: 'flex', gap: 8, paddingTop: 22, flexShrink: 0 }}>
                    {mult != null && (
                        <span style={pill('var(--vm-volt-tint-l)', 'var(--vm-volt-deep)')}>▲ {mult > 100 ? '>100' : mult.toFixed(1)}x avg</span>
                    )}
                    {video?.views != null && (
                        <span style={pill('var(--paper-2)', 'var(--ink-on-paper-2)')}>{formatCompact(video.views)} views</span>
                    )}
                    {video?.views == null && video?.comment_count != null && (
                        <span style={pill('var(--paper-2)', 'var(--ink-on-paper-2)')}>{formatCompact(video.comment_count)} comments</span>
                    )}
                    {video?.outlier_score != null && (
                        <span style={pill('var(--vm-red-tint-l)', 'var(--vm-red-deep)')}>⚡ {formatScore(video.outlier_score)} viral</span>
                    )}
                </div>
            </div>

            {/* Tab bar */}
            <div style={{ display: 'flex', gap: 28, borderBottom: '1px solid var(--line-1)', marginBottom: 28 }}>
                {TABS.map((t) => {
                    const active = t === tab;
                    return (
                        <button
                            key={t}
                            onClick={() => setTab(t)}
                            style={{
                                appearance: 'none', background: 'none', border: 'none', cursor: 'pointer', padding: '13px 2px',
                                fontFamily: 'var(--font-body)', fontWeight: 700, fontSize: 14,
                                color: active ? 'var(--ink-on-paper-1)' : 'var(--ink-on-paper-3)',
                                borderBottom: `2px solid ${active ? 'var(--vm-red)' : 'transparent'}`,
                            }}
                        >
                            {t}
                        </button>
                    );
                })}
                {/* Action buttons (design: Shorts Breakdown Page) */}
                <div style={{ marginLeft: 'auto', display: 'flex', gap: 8, alignSelf: 'center', marginBottom: 6 }}>
                    <button
                        onClick={toggleSave}
                        disabled={!video}
                        style={{
                            appearance: 'none', cursor: video ? 'pointer' : 'default',
                            background: 'var(--vm-red)', border: 'none', borderRadius: 'var(--r-pill)',
                            padding: '8px 16px', fontFamily: 'var(--font-body)', fontWeight: 700, fontSize: 13,
                            color: '#fff', opacity: video ? 1 : 0.5, whiteSpace: 'nowrap',
                        }}
                    >
                        <Bookmark size={13} fill={savedId != null ? '#fff' : 'none'} stroke="#fff" style={{ display: 'inline-block', verticalAlign: '-2px', marginRight: 6 }} />
                        {savedId != null ? 'Saved to library ✓' : 'Save to library'}
                    </button>
                    <button
                        onClick={copyTranscript}
                        disabled={!payload}
                        style={{
                            appearance: 'none', cursor: payload ? 'pointer' : 'default',
                            background: 'var(--paper-0)', border: '1px solid var(--line-1)', borderRadius: 'var(--r-pill)',
                            padding: '8px 16px', fontFamily: 'var(--font-body)', fontWeight: 700, fontSize: 13,
                            color: 'var(--ink-on-paper-1)', opacity: payload ? 1 : 0.5, whiteSpace: 'nowrap',
                        }}
                    >
                        {copied ? 'Copied ✓' : 'Copy transcript'}
                    </button>
                    <button
                        onClick={toggleCompetitor}
                        disabled={!video?.channel_id}
                        style={{
                            appearance: 'none', cursor: video?.channel_id ? 'pointer' : 'default',
                            background: 'var(--paper-0)', border: '1px solid var(--line-1)', borderRadius: 'var(--r-pill)',
                            padding: '8px 16px', fontFamily: 'var(--font-body)', fontWeight: 700, fontSize: 13,
                            color: 'var(--ink-on-paper-1)', opacity: video?.channel_id ? 1 : 0.5, whiteSpace: 'nowrap',
                        }}
                    >
                        {isCompetitor ? 'Added as competitor ✓' : 'Add as competitor'}
                    </button>
                </div>
            </div>

            {/* Content grid */}
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 300px', gap: 40, alignItems: 'start' }}>
                <div>
                    {payload ? (
                        <>
                            {tab === 'Idea analysis' && <IdeaTab payload={payload} />}
                            {tab === 'Hook' && <HookTab payload={payload} />}
                            {tab === 'Storytelling format' && <StructureTab payload={payload} />}
                            {tab === 'Visual layout' && <VisualTab payload={payload} />}
                            {tab === 'Transcript' && <TranscriptTab payload={payload} />}
                        </>
                    ) : (
                        <div style={{ ...panelCard, textAlign: 'center', padding: 40 }}>
                            <div style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 17, color: 'var(--ink-on-paper-1)', marginBottom: 6 }}>Breakdown failed</div>
                            <div style={{ fontSize: 13.5, color: 'var(--ink-on-paper-3)', marginBottom: 16 }}>{breakdown?.error || 'Something went wrong generating this breakdown.'}</div>
                            <button onClick={startGeneration} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 18px', borderRadius: 'var(--r-pill)', border: 'none', background: 'var(--vm-red)', color: '#fff', fontWeight: 700, fontSize: 13.5, cursor: 'pointer' }}>
                                <RotateCcw size={14} /> Try again
                            </button>
                        </div>
                    )}
                </div>

                {/* Sticky video rail */}
                <div style={{ display: 'flex', flexDirection: 'column', gap: 14, position: 'sticky', top: 24 }}>
                    <VideoEmbed video={video} platform={platform} videoId={videoId} isShorts={isShorts} />
                    <div style={{ display: 'flex', flexDirection: 'column', gap: 3 }}>
                        <span style={{ fontFamily: 'var(--font-display)', fontWeight: 800, fontSize: 16, letterSpacing: '-0.02em', color: 'var(--ink-on-paper-1)' }}>{video?.title || ''}</span>
                        <span style={{ fontSize: 13, fontWeight: 600, color: 'var(--ink-on-paper-3)' }}>{handle}{durationLabel ? ` · ${durationLabel}` : ''}</span>
                        <a
                            href={outlierUrl(platform, videoId, video?.channel?.handle || handle)}
                            target="_blank" rel="noopener noreferrer"
                            style={{ display: 'inline-flex', alignItems: 'center', gap: 4, marginTop: 4, fontSize: 12.5, fontWeight: 700, color: 'var(--vm-red)', textDecoration: 'none' }}
                        >
                            Watch on {platform === 'youtube' ? 'YouTube' : platform === 'tiktok' ? 'TikTok' : 'Instagram'} <ExternalLink size={12} />
                        </a>
                    </div>
                </div>
            </div>

            {saveOpen && video && (
                <SaveOutlierModal
                    video={video}
                    onSave={saveWithTags}
                    onClose={() => setSaveOpen(false)}
                />
            )}
        </div>
    );
}

/** Full-page generation loader from the Shorts Breakdown design: ring spinner
 *  with a pulsing play glyph, current-step title + percent, and a checklist. */
function GeneratingScreen({ stepIdx, steps = GEN_STEPS }: { stepIdx: number; steps?: string[] }) {
    return (
        <div style={{ minHeight: 'calc(100vh - 160px)', display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: 'var(--font-body)' }}>
            <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 28, width: 340 }}>
                {/* Spinner: ring + pulsing play triangle */}
                <div style={{ position: 'relative', width: 72, height: 72 }}>
                    <div style={{ position: 'absolute', inset: 0, borderRadius: '50%', border: '3px solid var(--line-1)', borderTopColor: 'var(--vm-red)', animation: 'vm-spin .8s linear infinite' }} />
                    <div style={{ position: 'absolute', inset: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', animation: 'vm-pulse 1.6s ease-in-out infinite' }}>
                        <span style={{ width: 0, height: 0, borderLeft: '16px solid var(--vm-red)', borderTop: '10px solid transparent', borderBottom: '10px solid transparent', marginLeft: 4 }} />
                    </div>
                </div>

                {/* Current step */}
                <span style={{ fontFamily: 'var(--font-display)', fontWeight: 800, fontSize: 20, letterSpacing: '-0.02em', color: 'var(--ink-on-paper-1)' }}>
                    {steps[stepIdx]}
                </span>

                {/* Checklist */}
                <div style={{ display: 'flex', flexDirection: 'column', gap: 10, width: '100%' }}>
                    {steps.map((label, i) => {
                        const done = i < stepIdx;
                        const current = i === stepIdx;
                        return (
                            <div key={label} style={{ display: 'flex', alignItems: 'center', gap: 10, opacity: done || current ? 1 : 0.45, animation: 'vm-step-in .3s var(--ease-out) both' }}>
                                <span style={{ fontFamily: mono, fontSize: 13, fontWeight: 700, width: 16, textAlign: 'center', color: done ? 'var(--vm-volt-deep)' : current ? 'var(--vm-red)' : 'var(--ink-on-paper-3)' }}>
                                    {done ? '✓' : current ? '●' : '○'}
                                </span>
                                <span style={{ fontSize: 14, fontWeight: 600, color: done || current ? 'var(--ink-on-paper-1)' : 'var(--ink-on-paper-3)' }}>
                                    {label}
                                </span>
                            </div>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}

function pill(bg: string, color: string): CSSProperties {
    return { fontFamily: mono, fontSize: 12, fontWeight: 700, padding: '6px 12px', borderRadius: 'var(--r-pill)', background: bg, color, whiteSpace: 'nowrap' };
}

function labelChip(label: string): CSSProperties {
    const base: CSSProperties = { fontSize: 12, fontWeight: 700, padding: '2px 9px', borderRadius: 'var(--r-pill)', whiteSpace: 'nowrap', marginLeft: 6 };
    if (label === 'hook') return { ...base, background: 'var(--vm-red-tint-l)', color: 'var(--vm-red-deep)' };
    if (label === 'loop point') return { ...base, background: 'var(--vm-volt-tint-l)', color: 'var(--vm-volt-deep)' };
    return { ...base, background: 'var(--paper-2)', color: 'var(--ink-on-paper-2)' };
}

function IdeaTab({ payload }: { payload: BreakdownPayload }) {
    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
            <span style={{ ...eyebrow, color: 'var(--ink-on-paper-3)', marginBottom: 2 }}>Why this idea works</span>
            <div style={{ ...panelCard, padding: '22px 24px' }}>
                <span style={{ ...eyebrow, color: 'var(--ink-on-paper-3)', display: 'block', marginBottom: 8 }}>Topic</span>
                <span style={{ fontFamily: 'var(--font-display)', fontWeight: 800, fontSize: 22, letterSpacing: '-0.02em', color: 'var(--ink-on-paper-1)' }}>{payload.idea.topic}</span>
            </div>
            <div style={{ ...panelCard, padding: '22px 24px' }}>
                <span style={{ ...eyebrow, color: 'var(--ink-on-paper-3)', display: 'block', marginBottom: 8 }}>Idea seed</span>
                <span style={{ display: 'block', fontSize: 15, lineHeight: 1.55, color: 'var(--ink-on-paper-2)' }}>{payload.idea.idea_seed}</span>
            </div>
            <div style={{ ...panelCard, padding: '22px 24px', border: '1px solid var(--vm-red)', boxShadow: 'var(--hard-red)' }}>
                <span style={{ ...eyebrow, color: 'var(--vm-red)', display: 'block', marginBottom: 8 }}>Unique angle</span>
                <span style={{ display: 'block', fontSize: 15, lineHeight: 1.55, color: 'var(--ink-on-paper-2)' }}>{payload.idea.unique_angle}</span>
            </div>
        </div>
    );
}

function HookTab({ payload }: { payload: BreakdownPayload }) {
    return (
        <div>
            <span style={{ ...eyebrow, color: 'var(--ink-on-paper-3)', display: 'block', marginBottom: 18 }}>First 3 seconds, beat by beat</span>
            <div style={{ display: 'grid', gridTemplateColumns: '64px 1fr', columnGap: 20 }}>
                {payload.hook.map((beat, i) => {
                    const last = i === payload.hook.length - 1;
                    return (
                        <div key={i} style={{ display: 'contents' }}>
                            <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center' }}>
                                <span style={{ fontFamily: mono, fontSize: 12, fontWeight: 700, color: i === 0 ? 'var(--vm-red)' : 'var(--ink-on-paper-3)' }}>{beat.time}</span>
                                <div style={{ width: 1, flex: 1, background: last ? 'transparent' : 'var(--line-2)', margin: '6px 0' }} />
                            </div>
                            <div style={{ border: '1px solid var(--line-1)', background: 'var(--paper-0)', borderRadius: 'var(--r-md)', padding: '16px 18px', marginBottom: last ? 0 : 14 }}>
                                <span style={{ display: 'block', fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 16, color: 'var(--ink-on-paper-1)', marginBottom: 4 }}>{beat.line}</span>
                                <span style={{ display: 'block', fontSize: 14, lineHeight: 1.5, color: 'var(--ink-on-paper-2)' }}>{beat.note}</span>
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

function beatBarColors(beat: BreakdownStructureBeat, index: number): { bg: string; fg: string } {
    if (beat.highlight === 'red') return { bg: 'var(--vm-red)', fg: '#fff' };
    if (beat.highlight === 'volt') return { bg: 'var(--vm-volt)', fg: 'var(--fg-on-volt)' };
    return { bg: index % 2 === 0 ? 'var(--ink-500)' : 'var(--ink-on-paper-1)', fg: '#fff' };
}

function StructureTab({ payload }: { payload: BreakdownPayload }) {
    const beats = payload.structure.beats;
    return (
        <div>
            <span style={{ ...eyebrow, color: 'var(--ink-on-paper-3)', display: 'block', marginBottom: 14 }}>Structure · {payload.structure.summary}</span>
            {/* Timeline bar — segments stay roughly duration-proportional but never
                shrink below their label, and click-jump to their section below. */}
            <div style={{ display: 'flex', gap: 4, height: 44, borderRadius: 'var(--r-md)', overflow: 'hidden', marginBottom: 24 }}>
                {beats.map((b, i) => {
                    const { bg, fg } = beatBarColors(b, i);
                    return (
                        <button
                            key={i}
                            onClick={() => document.getElementById(`beat-${i}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' })}
                            title={`Jump to ${b.title}`}
                            style={{
                                flex: `${Math.max(6, b.pct)} 1 auto`, minWidth: 'fit-content', border: 'none', cursor: 'pointer',
                                background: bg, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: '0 10px',
                            }}
                        >
                            <span style={{ fontFamily: mono, fontSize: 11, fontWeight: 700, color: fg, whiteSpace: 'nowrap' }}>{b.label}</span>
                        </button>
                    );
                })}
            </div>
            {/* Beat sections, one under another */}
            <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
                {beats.map((b, i) => {
                    const isVolt = b.highlight === 'volt';
                    const timeColor = b.highlight === 'red' ? 'var(--vm-red)' : isVolt ? 'var(--vm-volt-deep)' : 'var(--ink-on-paper-3)';
                    return (
                        <div key={i} id={`beat-${i}`} style={{
                            ...panelCard,
                            scrollMarginTop: 84,
                            ...(isVolt ? { background: 'var(--vm-volt-tint-l)', border: '1px solid var(--vm-volt-deep)' } : {}),
                        }}>
                            <span style={{ display: 'flex', alignItems: 'baseline', gap: 10, marginBottom: 6 }}>
                                <span style={{ fontFamily: mono, fontSize: 12, fontWeight: 700, color: timeColor }}>{formatBeatTime(b.start)}–{formatBeatTime(b.end)}</span>
                                <span style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 16, color: 'var(--ink-on-paper-1)' }}>{b.title}</span>
                            </span>
                            <span style={{ display: 'block', fontSize: 14, lineHeight: 1.5, color: 'var(--ink-on-paper-2)' }}>{b.note}</span>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}

function VisualTab({ payload }: { payload: BreakdownPayload }) {
    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
            <span style={{ ...eyebrow, color: 'var(--ink-on-paper-3)' }}>How the frame is built</span>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 14 }}>
                {payload.visual.map((v, i) => (
                    <div key={i} style={panelCard}>
                        <span style={{ display: 'block', fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 16, color: 'var(--ink-on-paper-1)', marginBottom: 5 }}>{v.title}</span>
                        <span style={{ display: 'block', fontSize: 14, lineHeight: 1.5, color: 'var(--ink-on-paper-2)' }}>{v.note}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}

function TranscriptTab({ payload }: { payload: BreakdownPayload }) {
    return (
        <div>
            <span style={{ ...eyebrow, color: 'var(--ink-on-paper-3)', display: 'block', marginBottom: 18 }}>Full transcript · annotated</span>
            <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                {payload.transcript.map((line, i) => (
                    <div key={i} style={{ display: 'grid', gridTemplateColumns: '56px 1fr', gap: 16, alignItems: 'baseline' }}>
                        <span style={{ fontFamily: mono, fontSize: 12, fontWeight: 700, color: line.label === 'hook' ? 'var(--vm-red)' : 'var(--ink-on-paper-3)' }}>{line.time}</span>
                        <span style={{ fontSize: 16, lineHeight: 1.6, color: 'var(--ink-on-paper-1)' }}>
                            "{line.text}"
                            {line.label && <span style={labelChip(line.label)}>{line.label}</span>}
                        </span>
                    </div>
                ))}
            </div>
        </div>
    );
}

function VideoEmbed({ video, platform, videoId, isShorts }: { video: OutlierVideo | null; platform: string; videoId: string; isShorts: boolean }) {
    // Click-to-play: show a full-res poster (the embed players serve a soft,
    // low-res poster at rail width) and only mount the iframe on demand.
    const [playing, setPlaying] = useState(false);
    // Instagram plays natively from CaptAPI's CDN URL (its iframe embed is refused
    // for most creator accounts). URLs are signed + short-lived → refresh on play.
    const isIG = platform === 'instagram';
    const [nativeSrc, setNativeSrc] = useState<string | null>(null);
    const [nativeFailed, setNativeFailed] = useState(false);
    const [refreshing, setRefreshing] = useState(false);
    const urlFresh = (v: OutlierVideo | null) => !!(v?.video_url && v.video_url_expires_at && new Date(v.video_url_expires_at).getTime() > Date.now() + 60_000);

    const play = async () => {
        if (isIG && !nativeFailed) {
            if (urlFresh(video)) { setNativeSrc(video!.video_url!); setPlaying(true); return; }
            setRefreshing(true);
            try {
                const fresh = await refreshOutlierMedia('instagram', videoId);
                if (fresh.video_url) { setNativeSrc(fresh.video_url); setPlaying(true); return; }
            } catch { /* fall through to the iframe embed */ }
            finally { setRefreshing(false); }
            setNativeFailed(true);
        }
        setPlaying(true);
    };
    const frame: CSSProperties = {
        width: 300,
        aspectRatio: isShorts ? '9 / 16' : '16 / 9',
        borderRadius: 18,
        overflow: 'hidden',
        background: 'var(--ink-800)',
        border: '1px solid var(--line-1)',
        position: 'relative',
    };
    const thumb = video ? bestThumbnail(video) : undefined;
    const poster = (
        <>
            {thumb && (
                <img
                    src={thumb} alt=""
                    onError={(e) => { const fb = video ? fallbackThumbnail(video) : undefined; if (fb && e.currentTarget.src !== fb) e.currentTarget.src = fb; }}
                    style={{ position: 'absolute', inset: 0, width: '100%', height: '100%', objectFit: 'cover', display: 'block' }}
                />
            )}
            <div style={{ position: 'absolute', inset: 0, background: 'rgba(0,0,0,.18)' }} />
        </>
    );

    // All three platforms embed in-page (Instagram via its official /embed/
    // endpoint — works for posts and reels alike), so nobody navigates out.
    const embedSrc = platform === 'youtube'
        ? `https://www.youtube.com/embed/${videoId}?autoplay=1`
        : platform === 'tiktok'
            ? `https://www.tiktok.com/embed/v2/${videoId}`
            : `https://www.instagram.com/p/${videoId}/embed/`;

    {
        return (
            <div style={frame}>
                {playing && isIG && nativeSrc && !nativeFailed ? (
                    <video
                        src={nativeSrc} poster={thumb} autoPlay controls playsInline
                        onError={() => { setNativeFailed(true); setNativeSrc(null); }}
                        style={{ width: '100%', height: '100%', objectFit: 'cover', display: 'block', background: '#000' }}
                    />
                ) : playing ? (
                    <iframe
                        src={embedSrc}
                        title={video?.title || 'Video'}
                        style={{ width: '100%', height: '100%', border: 'none', display: 'block' }}
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                        allowFullScreen
                        scrolling="no"
                    />
                ) : (
                    <button
                        onClick={play}
                        disabled={refreshing}
                        aria-label="Play video"
                        style={{ position: 'absolute', inset: 0, width: '100%', height: '100%', border: 'none', background: 'transparent', cursor: 'pointer', display: 'grid', placeItems: 'center', padding: 0 }}
                    >
                        {poster}
                        <span style={{ position: 'relative', width: 56, height: 56, borderRadius: '50%', background: 'var(--vm-red)', display: 'grid', placeItems: 'center', boxShadow: '0 10px 30px -8px rgba(255,31,61,.6)' }}>
                            {refreshing ? <Loader2 size={24} stroke="#fff" className="animate-spin" /> : <Play size={24} fill="#fff" stroke="#fff" style={{ marginLeft: 3 }} />}
                        </span>
                    </button>
                )}
            </div>
        );
    }

}
