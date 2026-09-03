import { useState, useEffect, useMemo, useCallback, useRef, CSSProperties, ReactNode } from 'react';
import { toast } from 'sonner';
import { Search, Loader2, X, Plus, Bookmark, ChevronDown, Check, User } from 'lucide-react';
import {
    getLibrary, getOutlierTags, deleteSavedOutlier, updateSavedOutlierTags,
    type SavedOutlier, type OutlierTag, type OutlierVideo, type OutlierPlatform,
} from '@/lib/outlier-service';
import OutlierCard from '@/components/outliers/OutlierCard';
import { BrandIcon } from '@/components/post/brand-icons';
import { cardVariant } from '@/components/outliers/format';

const CARD: CSSProperties = {
    background: 'var(--paper-0)',
    border: '1px solid var(--line-1)',
    borderRadius: 'var(--r-lg)',
    boxShadow: '0 1px 3px rgba(10,10,12,.05)',
};

const PLATFORMS: OutlierPlatform[] = ['youtube', 'tiktok', 'instagram'];

function toVideo(s: SavedOutlier): OutlierVideo {
    const snap = s.snapshot as Record<string, unknown>;
    return {
        id: s.id,
        platform: s.platform,
        youtube_video_id: s.video_id,
        title: (snap.title as string) || 'Untitled',
        thumbnail_url: (snap.thumbnail_url as string) || '',
        thumbnail_medium_url: snap.thumbnail_medium_url as string | undefined,
        views: (snap.views as number) ?? null,
        like_count: (snap.like_count as number) ?? null,
        comment_count: (snap.comment_count as number) ?? null,
        outlier_score: (snap.outlier_score as number) ?? null,
        engagement_rate: (snap.engagement_rate as number) ?? null,
        published_at: (snap.published_at as string) || '',
        duration: (snap.duration as string) ?? null,
        is_short: typeof snap.is_short === 'boolean' ? snap.is_short : null,
        channel: {
            channel_name: (snap.channel_name as string) || 'Unknown',
            profile_image_url: (snap.channel_avatar as string) ?? null,
            subscriber_count: (snap.subscriber_count as number) ?? null,
            average_views: (snap.channel_average_views as number) ?? null,
        },
    };
}

export default function OutliersLibrary() {
    const [items, setItems] = useState<SavedOutlier[]>([]);
    const [tags, setTags] = useState<OutlierTag[]>([]);
    const [creators, setCreators] = useState<string[]>([]);
    const [activeTags, setActiveTags] = useState<string[]>([]);
    const [activePlatforms, setActivePlatforms] = useState<OutlierPlatform[]>([]);
    const [creator, setCreator] = useState<string | null>(null);
    const [q, setQ] = useState('');
    const [loading, setLoading] = useState(true);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const data = await getLibrary({
                tags: activeTags,
                q: q.trim() || undefined,
                platforms: activePlatforms.length ? activePlatforms : undefined,
                creator: creator || undefined,
            });
            setItems(data);
        } catch (e) {
            toast.error(e instanceof Error ? e.message : 'Failed to load library');
        } finally {
            setLoading(false);
        }
    }, [activeTags, activePlatforms, creator, q]);

    // Filter options: tags from the tags endpoint; creators derived from the full library once.
    useEffect(() => {
        getOutlierTags().then(setTags).catch(() => {});
        getLibrary().then((all) => {
            const names = new Set<string>();
            all.forEach((s) => {
                const name = (s.snapshot as Record<string, unknown>).channel_name as string | undefined;
                if (name) names.add(name);
            });
            setCreators([...names].sort((a, b) => a.localeCompare(b)));
        }).catch(() => {});
    }, []);

    useEffect(() => {
        const t = setTimeout(load, 250);
        return () => clearTimeout(t);
    }, [load]);

    const toggleTagFilter = (name: string) => {
        setActiveTags((prev) => (prev.includes(name) ? prev.filter((t) => t !== name) : [...prev, name]));
    };
    const togglePlatform = (p: OutlierPlatform) => {
        setActivePlatforms((prev) => (prev.includes(p) ? prev.filter((x) => x !== p) : [...prev, p]));
    };

    const unsave = async (video: OutlierVideo) => {
        const item = items.find((i) => i.platform === video.platform && i.video_id === video.youtube_video_id);
        if (!item) return;
        setItems((prev) => prev.filter((i) => i.id !== item.id));
        try { await deleteSavedOutlier(item.id); } catch { toast.error('Failed to remove'); load(); }
    };

    const addTag = async (item: SavedOutlier, name: string) => {
        const clean = name.trim();
        if (!clean || item.tags.some((t) => t.name === clean)) return;
        const next = [...item.tags.map((t) => t.name), clean];
        try {
            const updated = await updateSavedOutlierTags(item.id, next);
            setItems((prev) => prev.map((i) => (i.id === item.id ? updated : i)));
            getOutlierTags().then(setTags).catch(() => {});
        } catch (e) {
            toast.error(e instanceof Error ? e.message : 'Failed to add tag');
        }
    };

    const removeTag = async (item: SavedOutlier, name: string) => {
        const next = item.tags.filter((t) => t.name !== name).map((t) => t.name);
        try {
            const updated = await updateSavedOutlierTags(item.id, next);
            setItems((prev) => prev.map((i) => (i.id === item.id ? updated : i)));
        } catch (e) {
            toast.error(e instanceof Error ? e.message : 'Failed to remove tag');
        }
    };

    const hasFilters = q.trim() !== '' || activeTags.length > 0 || activePlatforms.length > 0 || !!creator;

    return (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 20, fontFamily: 'var(--font-body)' }}>
            <div>
                <h1 style={{ fontFamily: 'var(--font-display)', fontWeight: 800, fontSize: 24, letterSpacing: '-.03em', color: 'var(--ink-on-paper-1)', margin: 0 }}>Outliers library</h1>
                <p style={{ margin: '4px 0 0', fontSize: 13.5, color: 'var(--ink-on-paper-3)' }}>Videos you've saved for inspiration, organised by tag.</p>
            </div>

            {/* Filters */}
            <div style={{ ...CARD, padding: '12px 16px', display: 'flex', gap: 10, flexWrap: 'wrap', alignItems: 'center' }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, background: 'var(--paper-1)', border: '1px solid var(--line-2)', borderRadius: 'var(--r-md)', padding: '8px 12px', flex: '1 1 220px', maxWidth: 340 }}>
                    <Search size={16} stroke="#76767F" />
                    <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Search saved videos…"
                        style={{ flex: 1, border: 'none', outline: 'none', background: 'transparent', fontSize: 14, color: 'var(--ink-on-paper-1)', minWidth: 60 }} />
                </div>

                {/* Creator typeahead (single-select) */}
                <FilterDropdown
                    icon={<User size={15} stroke="#76767F" />}
                    label={creator || 'All creators'}
                    active={!!creator}
                    options={creators}
                    isSelected={(name) => creator === name}
                    onPick={(name) => setCreator((prev) => (prev === name ? null : name))}
                    onClear={() => setCreator(null)}
                    clearLabel="All creators"
                    placeholder="Search creators…"
                />

                {/* Tag typeahead (multi-select) */}
                <FilterDropdown
                    icon={<Bookmark size={15} stroke="#76767F" />}
                    label={activeTags.length === 0 ? 'All tags' : activeTags.length === 1 ? activeTags[0] : `${activeTags.length} tags`}
                    active={activeTags.length > 0}
                    options={tags.map((t) => t.name)}
                    isSelected={(name) => activeTags.includes(name)}
                    onPick={toggleTagFilter}
                    onClear={() => setActiveTags([])}
                    clearLabel="All tags"
                    placeholder="Search tags…"
                    keepOpenOnPick
                />

                {/* Platform icons */}
                <div style={{ display: 'flex', gap: 6 }}>
                    {PLATFORMS.map((p) => {
                        const active = activePlatforms.includes(p);
                        return (
                            <button
                                key={p}
                                onClick={() => togglePlatform(p)}
                                title={p}
                                aria-pressed={active}
                                style={{
                                    width: 36, height: 36, borderRadius: 'var(--r-md)', cursor: 'pointer',
                                    display: 'flex', alignItems: 'center', justifyContent: 'center',
                                    border: `1px solid ${active ? 'var(--vm-red)' : 'var(--line-2)'}`,
                                    background: active ? 'var(--vm-red-tint-l)' : 'var(--paper-0)',
                                }}
                            >
                                <BrandIcon platform={p} size={17} color={active ? 'var(--vm-red-deep)' : '#76767F'} />
                            </button>
                        );
                    })}
                </div>

                {hasFilters && (
                    <button
                        onClick={() => { setQ(''); setActiveTags([]); setActivePlatforms([]); setCreator(null); }}
                        style={{ display: 'flex', alignItems: 'center', gap: 4, padding: '7px 12px', borderRadius: 'var(--r-pill)', border: '1px solid var(--line-2)', background: 'var(--paper-0)', color: 'var(--ink-on-paper-3)', fontSize: 12.5, cursor: 'pointer' }}
                    >
                        <X size={13} /> Clear
                    </button>
                )}
            </div>

            {loading ? (
                <div style={{ display: 'flex', justifyContent: 'center', padding: 60, color: 'var(--ink-on-paper-3)' }}><Loader2 size={28} className="animate-spin" /></div>
            ) : items.length === 0 ? (
                <div style={{ ...CARD, padding: 48, textAlign: 'center', color: 'var(--ink-on-paper-3)' }}>
                    <Bookmark size={26} style={{ opacity: .5 }} />
                    <p style={{ marginTop: 10 }}>
                        {hasFilters ? 'Nothing matches these filters.' : 'Nothing saved yet. Bookmark outliers from the Browse tab to build your library.'}
                    </p>
                </div>
            ) : (
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(260px,1fr))', gap: 20 }}>
                    {items.map((item) => {
                        const video = toVideo(item);
                        const variant = cardVariant(video);
                        return (
                            <div key={item.id} style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                                <OutlierCard video={video} variant={variant} saved onToggleSave={unsave} />
                                <TagEditor item={item} onAdd={addTag} onRemove={removeTag} />
                            </div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

/** Searchable dropdown filter shared by the creator + tag filters (also reused by the admin lead-magnet picker). */
export function FilterDropdown({ icon, label, active, options, isSelected, onPick, onClear, clearLabel, placeholder, keepOpenOnPick = false }: {
    icon: ReactNode;
    label: string;
    active: boolean;
    options: string[];
    isSelected: (name: string) => boolean;
    onPick: (name: string) => void;
    onClear: () => void;
    clearLabel: string;
    placeholder: string;
    keepOpenOnPick?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const wrapRef = useRef<HTMLDivElement | null>(null);

    useEffect(() => {
        if (!open) return;
        const onDown = (e: globalThis.MouseEvent) => {
            if (wrapRef.current && !wrapRef.current.contains(e.target as Node)) setOpen(false);
        };
        document.addEventListener('mousedown', onDown);
        return () => document.removeEventListener('mousedown', onDown);
    }, [open]);

    const filtered = useMemo(() => {
        const needle = query.trim().toLowerCase();
        return needle ? options.filter((o) => o.toLowerCase().includes(needle)) : options;
    }, [options, query]);

    return (
        <div ref={wrapRef} style={{ position: 'relative' }}>
            <button
                onClick={() => { setOpen((o) => !o); setQuery(''); }}
                style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '8px 12px', borderRadius: 'var(--r-md)', background: 'var(--paper-0)', cursor: 'pointer', minWidth: 150, border: `1px solid ${active ? 'var(--vm-red)' : 'var(--line-2)'}` }}
            >
                {icon}
                <span style={{ fontSize: 13, color: 'var(--ink-on-paper-2)', fontWeight: active ? 700 : 400, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', maxWidth: 160 }}>{label}</span>
                <ChevronDown size={14} style={{ marginLeft: 'auto' }} stroke="#76767F" />
            </button>
            {open && (
                <div style={{ position: 'absolute', top: 'calc(100% + 6px)', left: 0, zIndex: 30, minWidth: 230, ...CARD, boxShadow: '0 12px 32px -8px rgba(10,10,12,.25)', overflow: 'hidden' }}>
                    <div style={{ padding: 8, borderBottom: '1px solid var(--line-1)' }}>
                        <input autoFocus value={query} onChange={(e) => setQuery(e.target.value)} placeholder={placeholder}
                            style={{ width: '100%', border: '1px solid var(--line-2)', borderRadius: 'var(--r-sm)', padding: '6px 8px', fontSize: 13, outline: 'none', boxSizing: 'border-box' }} />
                    </div>
                    <div style={{ maxHeight: 230, overflowY: 'auto' }}>
                        <button onClick={() => { onClear(); setOpen(false); }} style={rowBtn(false)}>
                            <span style={{ fontSize: 13, color: 'var(--ink-on-paper-2)' }}>{clearLabel}</span>
                        </button>
                        {filtered.map((name) => {
                            const picked = isSelected(name);
                            return (
                                <button key={name} onClick={() => { onPick(name); if (!keepOpenOnPick) setOpen(false); }} style={rowBtn(picked)}>
                                    <span style={{ fontSize: 13, color: 'var(--ink-on-paper-1)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{name}</span>
                                    {picked && <Check size={15} stroke="#D60B27" style={{ marginLeft: 'auto' }} />}
                                </button>
                            );
                        })}
                        {filtered.length === 0 && (
                            <div style={{ padding: 12, fontSize: 12.5, color: 'var(--ink-on-paper-3)' }}>No matches.</div>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
}

function rowBtn(active: boolean): CSSProperties {
    return {
        width: '100%', display: 'flex', alignItems: 'center', gap: 8, padding: '8px 12px',
        border: 'none', cursor: 'pointer', textAlign: 'left',
        background: active ? 'var(--vm-red-tint-l)' : 'transparent',
    };
}

function TagEditor({ item, onAdd, onRemove }: {
    item: SavedOutlier;
    onAdd: (item: SavedOutlier, name: string) => void;
    onRemove: (item: SavedOutlier, name: string) => void;
}) {
    const [adding, setAdding] = useState(false);
    const [value, setValue] = useState('');
    return (
        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', alignItems: 'center' }}>
            {item.tags.map((t) => (
                <span key={t.id} style={{ display: 'inline-flex', alignItems: 'center', gap: 4, padding: '3px 8px', borderRadius: 'var(--r-pill)', background: 'var(--vm-red-tint-l)', color: 'var(--vm-red-deep)', fontSize: 11.5, fontWeight: 700 }}>
                    {t.name}
                    <X size={11} style={{ cursor: 'pointer' }} onClick={() => onRemove(item, t.name)} />
                </span>
            ))}
            {adding ? (
                <input autoFocus value={value} onChange={(e) => setValue(e.target.value)}
                    onKeyDown={(e) => { if (e.key === 'Enter') { onAdd(item, value); setValue(''); setAdding(false); } if (e.key === 'Escape') setAdding(false); }}
                    onBlur={() => { if (value.trim()) onAdd(item, value); setValue(''); setAdding(false); }}
                    placeholder="tag…" style={{ width: 80, border: '1px solid var(--line-2)', borderRadius: 'var(--r-pill)', padding: '3px 8px', fontSize: 11.5, outline: 'none' }} />
            ) : (
                <button onClick={() => setAdding(true)} style={{ display: 'inline-flex', alignItems: 'center', gap: 3, padding: '3px 8px', borderRadius: 'var(--r-pill)', border: '1px dashed var(--line-2)', background: 'transparent', color: 'var(--ink-on-paper-3)', fontSize: 11.5, cursor: 'pointer' }}>
                    <Plus size={11} /> Tag
                </button>
            )}
        </div>
    );
}
