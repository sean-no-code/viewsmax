import { useCallback, useEffect, useMemo, useRef, useState, CSSProperties } from 'react';
import { toast } from 'sonner';
import { Bookmark, Check, FileText, Loader2, Search, Sparkles, X } from 'lucide-react';
import { useAuth } from '@/hooks/useAuth';
import {
    getLibrary, getOutlierTags, getOutlierBreakdown, requestOutlierBreakdown,
    type SavedOutlier, type OutlierTag, type OutlierPlatform, type OutlierBreakdown,
} from '@/lib/outlier-service';
import { FilterDropdown } from '@/pages/OutliersLibrary';
import { BrandIcon } from '@/components/post/brand-icons';
import { formatCompact } from '@/components/outliers/format';

/**
 * Admin: pick up to 3 tagged outliers from the saved library and generate the
 * "winning formats" lead-magnet PDF (rendered by LeadMagnetPrint). Every
 * selected outlier needs a completed AI breakdown — it IS the format content.
 */

const MAX_FORMATS = 3;

const CARD: CSSProperties = {
    background: 'var(--paper-0)',
    border: '1px solid var(--line-1)',
    borderRadius: 'var(--r-lg)',
    boxShadow: '0 1px 3px rgba(10,10,12,.05)',
};

const PLATFORMS: OutlierPlatform[] = ['youtube', 'tiktok', 'instagram'];

const keyOf = (s: SavedOutlier) => `${s.platform}:${s.video_id}`;
const snap = (s: SavedOutlier, k: string) => (s.snapshot as Record<string, unknown>)[k];

type BreakdownState = { status: 'loading' } | OutlierBreakdown;

export default function AdminLeadMagnet() {
    const { user } = useAuth();
    const [items, setItems] = useState<SavedOutlier[]>([]);
    const [tags, setTags] = useState<OutlierTag[]>([]);
    const [activeTags, setActiveTags] = useState<string[]>([]);
    const [activePlatforms, setActivePlatforms] = useState<OutlierPlatform[]>([]);
    const [q, setQ] = useState('');
    const [loading, setLoading] = useState(true);
    const [selected, setSelected] = useState<SavedOutlier[]>([]);
    const [niche, setNiche] = useState('');
    const [breakdowns, setBreakdowns] = useState<Record<string, BreakdownState>>({});
    const pollTimers = useRef<Record<string, ReturnType<typeof setTimeout>>>({});

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const data = await getLibrary({
                tags: activeTags,
                q: q.trim() || undefined,
                platforms: activePlatforms.length ? activePlatforms : undefined,
            });
            setItems(data);
        } catch (e) {
            toast.error(e instanceof Error ? e.message : 'Failed to load library');
        } finally {
            setLoading(false);
        }
    }, [activeTags, activePlatforms, q]);

    useEffect(() => { getOutlierTags().then(setTags).catch(() => {}); }, []);
    useEffect(() => {
        const t = setTimeout(load, 250);
        return () => clearTimeout(t);
    }, [load]);
    useEffect(() => () => { Object.values(pollTimers.current).forEach(clearTimeout); }, []);

    const setBreakdown = (key: string, b: BreakdownState) =>
        setBreakdowns((prev) => ({ ...prev, [key]: b }));

    const pollBreakdown = useCallback((item: SavedOutlier) => {
        const key = keyOf(item);
        clearTimeout(pollTimers.current[key]);
        pollTimers.current[key] = setTimeout(async () => {
            try {
                const b = await getOutlierBreakdown(item.platform, item.video_id);
                setBreakdown(key, b);
                if (b.status === 'pending' || b.status === 'processing') pollBreakdown(item);
            } catch {
                pollBreakdown(item);
            }
        }, 5000);
    }, []);

    const fetchStatus = useCallback(async (item: SavedOutlier) => {
        const key = keyOf(item);
        setBreakdown(key, { status: 'loading' });
        try {
            const b = await getOutlierBreakdown(item.platform, item.video_id);
            setBreakdown(key, b);
            if (b.status === 'pending' || b.status === 'processing') pollBreakdown(item);
        } catch {
            setBreakdown(key, { status: 'none', payload: null, error: null });
        }
    }, [pollBreakdown]);

    const toggleSelect = (item: SavedOutlier) => {
        if (selected.some((s) => s.id === item.id)) {
            clearTimeout(pollTimers.current[keyOf(item)]);
            setSelected((prev) => prev.filter((s) => s.id !== item.id));
            return;
        }
        if (selected.length >= MAX_FORMATS) {
            toast.error(`Pick at most ${MAX_FORMATS} outliers — one per format page.`);
            return;
        }
        if (!breakdowns[keyOf(item)]) fetchStatus(item);
        setSelected((prev) => [...prev, item]);
    };

    const generateBreakdown = async (item: SavedOutlier) => {
        const key = keyOf(item);
        setBreakdown(key, { status: 'loading' });
        try {
            const b = await requestOutlierBreakdown(item.platform, item.video_id);
            setBreakdown(key, b);
            if (b.status !== 'completed' && b.status !== 'failed') pollBreakdown(item);
        } catch (e) {
            toast.error(e instanceof Error ? e.message : 'Failed to start breakdown');
            fetchStatus(item);
        }
    };

    const allReady = selected.length > 0 && selected.every((s) => {
        const b = breakdowns[keyOf(s)];
        return b && b.status === 'completed';
    });

    const nicheValue = useMemo(() => niche.trim() || activeTags[0] || '', [niche, activeTags]);

    const openPdf = () => {
        if (!allReady) return;
        if (!nicheValue) { toast.error('Give the niche a name — it goes in the PDF title.'); return; }
        const params = new URLSearchParams({ ids: selected.map((s) => s.id).join(','), niche: nicheValue });
        window.open(`/admin/lead-magnet/print?${params.toString()}`, '_blank');
    };

    if (!user?.is_admin) {
        return (
            <div style={{ ...CARD, padding: 48, textAlign: 'center', color: 'var(--ink-on-paper-3)', fontFamily: 'var(--font-body)', fontSize: 14 }}>
                Admins only.
            </div>
        );
    }

    const hasFilters = q.trim() !== '' || activeTags.length > 0 || activePlatforms.length > 0;

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 20, fontFamily: 'var(--font-body)', paddingBottom: 140 }}>
            <div>
                <h1 style={{ fontFamily: 'var(--font-display)', fontWeight: 800, fontSize: 24, letterSpacing: '-.03em', color: 'var(--ink-on-paper-1)', margin: 0 }}>Lead magnet</h1>
                <p style={{ margin: '4px 0 0', fontSize: 13.5, color: 'var(--ink-on-paper-3)' }}>
                    Filter your saved outliers by tag, pick up to {MAX_FORMATS}, and generate the “winning formats in your niche” PDF for cold outreach.
                </p>
            </div>

            {/* Filters */}
            <div style={{ ...CARD, padding: '12px 16px', display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'center' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, background: 'var(--paper-1)', border: '1px solid var(--line-2)', borderRadius: 'var(--r-md)', padding: '8px 12px', flex: '1 1 220px', maxWidth: 340 }}>
                    <Search size={16} stroke="#76767F" />
                    <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search saved videos…"
                        style={{ flex: 1, border: 'none', outline: 'none', background: 'transparent', fontSize: 14, color: 'var(--ink-on-paper-1)', minWidth: 60 }} />
                </div>

                <FilterDropdown
                    icon={<Bookmark size={15} stroke="#76767F" />}
                    label={activeTags.length === 0 ? 'All tags' : activeTags.length === 1 ? activeTags[0] : `${activeTags.length} tags`}
                    active={activeTags.length > 0}
                    options={tags.map((t) => t.name)}
                    isSelected={(name) => activeTags.includes(name)}
                    onPick={(name) => setActiveTags((prev) => (prev.includes(name) ? prev.filter((t) => t !== name) : [...prev, name]))}
                    onClear={() => setActiveTags([])}
                    clearLabel="All tags"
                    placeholder="Search tags…"
                    keepOpenOnPick
                />

                <div style={{ display: 'flex', gap: 6 }}>
                    {PLATFORMS.map((p) => {
                        const active = activePlatforms.includes(p);
                        return (
                            <button key={p} onClick={() => setActivePlatforms((prev) => (prev.includes(p) ? prev.filter((x) => x !== p) : [...prev, p]))}
                                title={p} aria-pressed={active}
                                style={{
                                    width: 36, height: 36, borderRadius: 'var(--r-md)', cursor: 'pointer',
                                    display: 'flex', alignItems: 'center', justifyContent: 'center',
                                    border: `1px solid ${active ? 'var(--vm-red)' : 'var(--line-2)'}`,
                                    background: active ? 'var(--vm-red-tint-l)' : 'var(--paper-0)',
                                }}>
                                <BrandIcon platform={p} size={17} color={active ? 'var(--vm-red-deep)' : '#76767F'} />
                            </button>
                        );
                    })}
                </div>

                {hasFilters && (
                    <button onClick={() => { setQ(''); setActiveTags([]); setActivePlatforms([]); }}
                        style={{ display: 'flex', alignItems: 'center', gap: 4, padding: '7px 12px', borderRadius: 'var(--r-pill)', border: '1px solid var(--line-2)', background: 'var(--paper-0)', color: 'var(--ink-on-paper-3)', fontSize: 12.5, cursor: 'pointer' }}>
                        <X size={13} /> Clear
                    </button>
                )}
            </div>

            {/* Library grid */}
            {loading ? (
                <div style={{ display: 'flex', justifyContent: 'center', padding: 60, color: 'var(--ink-on-paper-3)' }}><Loader2 size={28} className="animate-spin" /></div>
            ) : items.length === 0 ? (
                <div style={{ ...CARD, padding: 48, textAlign: 'center', color: 'var(--ink-on-paper-3)' }}>
                    <Bookmark size={26} style={{ opacity: .5 }} />
                    <p style={{ marginTop: 10 }}>
                        {hasFilters ? 'Nothing matches these filters.' : 'Nothing saved yet. Bookmark outliers from the Browse tab first.'}
                    </p>
                </div>
            ) : (
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(240px,1fr))', gap: 16 }}>
                    {items.map((item) => {
                        const pickIndex = selected.findIndex((s) => s.id === item.id);
                        const picked = pickIndex !== -1;
                        const thumb = (snap(item, 'thumbnail_medium_url') || snap(item, 'thumbnail_url')) as string | undefined;
                        const views = snap(item, 'views') as number | null;
                        const score = snap(item, 'outlier_score') as number | null;
                        return (
                            <button key={item.id} onClick={() => toggleSelect(item)}
                                style={{
                                    ...CARD, padding: 0, overflow: 'hidden', textAlign: 'left', cursor: 'pointer', position: 'relative',
                                    border: picked ? '2px solid var(--vm-red)' : '1px solid var(--line-1)',
                                    boxShadow: picked ? '0 0 0 3px var(--vm-red-tint-l)' : CARD.boxShadow,
                                }}>
                                <div style={{ aspectRatio: '16/9', background: 'var(--paper-2)', overflow: 'hidden' }}>
                                    {thumb && <img src={thumb} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover', display: 'block' }} />}
                                </div>
                                {picked && (
                                    <span style={{ position: 'absolute', top: 8, left: 8, width: 26, height: 26, borderRadius: '50%', background: 'var(--vm-red)', color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: 'var(--font-display)', fontWeight: 800, fontSize: 13 }}>
                                        {pickIndex + 1}
                                    </span>
                                )}
                                <div style={{ padding: '10px 12px 12px' }}>
                                    <div style={{ fontSize: 13.5, fontWeight: 600, color: 'var(--ink-on-paper-1)', lineHeight: 1.35, display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical', overflow: 'hidden' }}>
                                        {(snap(item, 'title') as string) || 'Untitled'}
                                    </div>
                                    <div style={{ display: 'flex', gap: 8, alignItems: 'center', marginTop: 6, fontSize: 12, color: 'var(--ink-on-paper-3)', flexWrap: 'wrap' }}>
                                        <BrandIcon platform={item.platform} size={13} color="#76767F" />
                                        <span>{(snap(item, 'channel_name') as string) || 'Unknown'}</span>
                                        {views != null && <span style={{ fontFamily: 'var(--font-mono)', fontSize: 11 }}>{formatCompact(views)} views</span>}
                                        {score != null && <span style={{ fontFamily: 'var(--font-mono)', fontSize: 11, color: 'var(--vm-red-deep)', fontWeight: 700 }}>▲ {score >= 10 ? Math.round(score) : Math.round(score * 10) / 10}x</span>}
                                    </div>
                                    {item.tags.length > 0 && (
                                        <div style={{ display: 'flex', gap: 4, flexWrap: 'wrap', marginTop: 8 }}>
                                            {item.tags.map((t) => (
                                                <span key={t.id} style={{ padding: '2px 8px', borderRadius: 'var(--r-pill)', background: 'var(--vm-red-tint-l)', color: 'var(--vm-red-deep)', fontSize: 11, fontWeight: 700 }}>{t.name}</span>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            </button>
                        );
                    })}
                </div>
            )}

            {/* Selection tray */}
            {selected.length > 0 && (
                <div style={{ position: 'fixed', left: 0, right: 0, bottom: 0, zIndex: 30, background: 'var(--paper-0)', borderTop: '1px solid var(--line-2)', boxShadow: '0 -8px 30px rgba(10,10,12,.10)', padding: '14px 24px' }}>
                    <div style={{ maxWidth: 1200, margin: '0 auto', display: 'flex', gap: 16, alignItems: 'center', flexWrap: 'wrap' }}>
                        <div style={{ display: 'flex', gap: 10, flex: '1 1 420px', flexWrap: 'wrap' }}>
                            {selected.map((item, i) => {
                                const b = breakdowns[keyOf(item)];
                                return (
                                    <div key={item.id} style={{ display: 'flex', gap: 8, alignItems: 'center', border: '1px solid var(--line-1)', borderRadius: 'var(--r-md)', padding: '6px 10px', background: 'var(--paper-1)', maxWidth: 320 }}>
                                        <span style={{ fontFamily: 'var(--font-display)', fontWeight: 800, fontSize: 12, color: 'var(--vm-red)' }}>0{i + 1}</span>
                                        <span style={{ fontSize: 12.5, color: 'var(--ink-on-paper-1)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', maxWidth: 160 }}>
                                            {(snap(item, 'title') as string) || 'Untitled'}
                                        </span>
                                        {!b || b.status === 'loading' ? (
                                            <Loader2 size={13} className="animate-spin" style={{ color: 'var(--ink-on-paper-3)', flexShrink: 0 }} />
                                        ) : b.status === 'completed' ? (
                                            <span title="Breakdown ready" style={{ display: 'flex', alignItems: 'center', gap: 3, color: 'var(--up)', fontSize: 11.5, fontWeight: 700, flexShrink: 0 }}><Check size={13} /> ready</span>
                                        ) : b.status === 'pending' || b.status === 'processing' ? (
                                            <span style={{ display: 'flex', alignItems: 'center', gap: 4, color: 'var(--warn)', fontSize: 11.5, fontWeight: 700, flexShrink: 0 }}><Loader2 size={12} className="animate-spin" /> analyzing…</span>
                                        ) : (
                                            <button onClick={(e) => { e.stopPropagation(); generateBreakdown(item); }}
                                                style={{ display: 'flex', alignItems: 'center', gap: 4, border: '1px solid var(--vm-red)', background: 'var(--vm-red-tint-l)', color: 'var(--vm-red-deep)', borderRadius: 'var(--r-pill)', padding: '3px 10px', fontSize: 11.5, fontWeight: 700, cursor: 'pointer', flexShrink: 0 }}>
                                                <Sparkles size={12} /> {b.status === 'failed' ? 'Retry breakdown' : 'Generate breakdown'}
                                            </button>
                                        )}
                                        <X size={13} style={{ cursor: 'pointer', color: 'var(--ink-on-paper-3)', flexShrink: 0 }} onClick={(e) => { e.stopPropagation(); toggleSelect(item); }} />
                                    </div>
                                );
                            })}
                        </div>
                        <input
                            value={niche} onChange={(e) => setNiche(e.target.value)}
                            placeholder={activeTags[0] ? `Niche: ${activeTags[0]}` : 'Niche (goes in the title)…'}
                            style={{ border: '1px solid var(--line-2)', borderRadius: 'var(--r-md)', padding: '9px 12px', fontSize: 13.5, outline: 'none', width: 220, background: 'var(--paper-1)', color: 'var(--ink-on-paper-1)' }}
                        />
                        <button onClick={openPdf} disabled={!allReady}
                            title={allReady ? undefined : 'Every selected outlier needs a completed breakdown first'}
                            style={{
                                display: 'flex', alignItems: 'center', gap: 8, border: 'none', borderRadius: 'var(--r-pill)', padding: '11px 22px',
                                fontSize: 14, fontWeight: 700, fontFamily: 'var(--font-body)',
                                background: allReady ? 'var(--vm-red)' : 'var(--line-2)',
                                color: allReady ? '#fff' : 'var(--ink-on-paper-3)',
                                cursor: allReady ? 'pointer' : 'not-allowed',
                            }}>
                            <FileText size={16} /> Generate PDF ({selected.length})
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}
