import { useState, useEffect, useMemo, CSSProperties } from 'react';
import { Loader2, X, Check, Bookmark } from 'lucide-react';
import { getOutlierTags, type OutlierVideo, type OutlierTag } from '@/lib/outlier-service';

const CARD: CSSProperties = {
    background: 'var(--paper-0)',
    border: '1px solid var(--line-1)',
    borderRadius: 'var(--r-lg)',
    boxShadow: '0 24px 60px -12px rgba(10,10,12,.35)',
};

interface Props {
    video: OutlierVideo;
    onSave: (tags: string[]) => Promise<void>;
    onClose: () => void;
}

/** Save-to-library modal: pick existing tags (typeahead) or create new ones inline. */
export default function SaveOutlierModal({ video, onSave, onClose }: Props) {
    const [allTags, setAllTags] = useState<OutlierTag[]>([]);
    const [selected, setSelected] = useState<string[]>([]);
    const [query, setQuery] = useState('');
    const [saving, setSaving] = useState(false);

    useEffect(() => { getOutlierTags().then(setAllTags).catch(() => {}); }, []);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose(); };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [onClose]);

    const q = query.trim();
    const matches = useMemo(() => allTags
        .filter((t) => !selected.includes(t.name))
        .filter((t) => !q || t.name.toLowerCase().includes(q.toLowerCase())), [allTags, selected, q]);
    const exactMatch = allTags.some((t) => t.name.toLowerCase() === q.toLowerCase());
    const canCreate = q !== '' && !exactMatch && !selected.some((s) => s.toLowerCase() === q.toLowerCase());

    const addTag = (name: string) => {
        const clean = name.trim();
        if (!clean || selected.includes(clean)) return;
        setSelected((prev) => [...prev, clean]);
        setQuery('');
    };
    const removeTag = (name: string) => setSelected((prev) => prev.filter((t) => t !== name));

    // Whatever is still typed in the box counts as a tag on save — the API
    // creates unknown tags itself, so there's no separate "create" step.
    const pendingTag = q !== '' && !selected.some((s) => s.toLowerCase() === q.toLowerCase())
        ? (allTags.find((t) => t.name.toLowerCase() === q.toLowerCase())?.name ?? q)
        : null;
    const tagsToSave = pendingTag ? [...selected, pendingTag] : selected;

    const handleSave = async () => {
        setSaving(true);
        try {
            await onSave(tagsToSave);
        } finally {
            setSaving(false);
        }
    };

    const thumb = video.thumbnail_medium_url || video.thumbnail_url;

    return (
        <div
            onClick={onClose}
            style={{ position: 'fixed', inset: 0, zIndex: 100, background: 'rgba(6,6,8,.45)', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20, fontFamily: 'var(--font-body)' }}
        >
            <div onClick={(e) => e.stopPropagation()} style={{ ...CARD, width: 440, maxWidth: '100%', overflow: 'hidden' }}>
                {/* Header */}
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '14px 18px', borderBottom: '1px solid var(--line-1)' }}>
                    <Bookmark size={16} stroke="var(--vm-red)" />
                    <span style={{ fontFamily: 'var(--font-display)', fontWeight: 800, fontSize: 16, letterSpacing: '-.02em', color: 'var(--ink-on-paper-1)' }}>Save to library</span>
                    <button onClick={onClose} aria-label="Close" style={{ marginLeft: 'auto', border: 'none', background: 'transparent', cursor: 'pointer', color: 'var(--ink-on-paper-3)', display: 'flex' }}>
                        <X size={17} />
                    </button>
                </div>

                {/* Video row */}
                <div style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '14px 18px 4px' }}>
                    {thumb && <img src={thumb} alt="" style={{ width: 74, height: 42, objectFit: 'cover', borderRadius: 'var(--r-sm)', background: 'var(--ink-800)', flexShrink: 0 }} />}
                    <div style={{ minWidth: 0 }}>
                        <div style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 13.5, color: 'var(--ink-on-paper-1)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{video.title}</div>
                        <div style={{ fontSize: 12, color: 'var(--ink-on-paper-3)', marginTop: 2 }}>{video.channel?.channel_name || 'Unknown'}</div>
                    </div>
                </div>

                {/* Tags */}
                <div style={{ padding: '12px 18px 18px' }}>
                    <div style={{ fontSize: 9, letterSpacing: '.12em', textTransform: 'uppercase', color: 'var(--ink-on-paper-3)', fontWeight: 700, marginBottom: 8 }}>Tags</div>

                    {selected.length > 0 && (
                        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginBottom: 8 }}>
                            {selected.map((name) => (
                                <span key={name} style={{ display: 'inline-flex', alignItems: 'center', gap: 4, padding: '4px 10px', borderRadius: 'var(--r-pill)', background: 'var(--vm-red-tint-l)', color: 'var(--vm-red-deep)', fontSize: 12, fontWeight: 700 }}>
                                    {name}
                                    <X size={12} style={{ cursor: 'pointer' }} onClick={() => removeTag(name)} />
                                </span>
                            ))}
                        </div>
                    )}

                    <input
                        autoFocus
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter') {
                                if (canCreate) addTag(q);
                                else if (matches.length > 0) addTag(matches[0].name);
                            }
                        }}
                        placeholder="Search or add a tag…"
                        style={{ width: '100%', border: '1px solid var(--line-2)', borderRadius: 'var(--r-md)', padding: '9px 12px', fontSize: 13.5, outline: 'none', boxSizing: 'border-box' }}
                    />

                    {/* Suggestions — hidden while typing a brand-new tag (it's created on save) */}
                    {!(matches.length === 0 && canCreate) && (
                    <div style={{ marginTop: 8, maxHeight: 168, overflowY: 'auto', border: '1px solid var(--line-1)', borderRadius: 'var(--r-md)' }}>
                        {matches.map((t) => (
                            <button key={t.id} onClick={() => addTag(t.name)} style={rowBtn}>
                                <Check size={13} stroke="var(--line-2)" />
                                <span style={{ fontSize: 13, color: 'var(--ink-on-paper-1)' }}>{t.name}</span>
                            </button>
                        ))}
                        {matches.length === 0 && (
                            <div style={{ padding: '12px 12px', fontSize: 12.5, color: 'var(--ink-on-paper-3)' }}>
                                {allTags.length === 0 ? 'No tags yet — type one and save.' : 'No matching tags — type a new one and save.'}
                            </div>
                        )}
                    </div>
                    )}

                    {/* Actions */}
                    <div style={{ display: 'flex', gap: 8, marginTop: 14 }}>
                        <button onClick={onClose} style={{ flex: 1, padding: '10px', borderRadius: 'var(--r-md)', border: '1px solid var(--line-2)', background: 'var(--paper-0)', color: 'var(--ink-on-paper-2)', fontWeight: 700, fontSize: 13.5, cursor: 'pointer' }}>
                            Cancel
                        </button>
                        <button onClick={handleSave} disabled={saving} style={{ flex: 2, padding: '10px', borderRadius: 'var(--r-md)', border: 'none', background: 'var(--vm-red)', color: '#fff', fontWeight: 700, fontSize: 13.5, cursor: 'pointer', display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 6 }}>
                            {saving ? <Loader2 size={14} className="animate-spin" /> : <Bookmark size={14} />}
                            Save{tagsToSave.length > 0 ? ` with ${tagsToSave.length} tag${tagsToSave.length > 1 ? 's' : ''}` : ''}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    );
}

const rowBtn: CSSProperties = {
    width: '100%', display: 'flex', alignItems: 'center', gap: 8, padding: '9px 12px',
    border: 'none', borderBottom: '1px solid var(--line-1)', cursor: 'pointer', textAlign: 'left',
    background: 'transparent',
};
