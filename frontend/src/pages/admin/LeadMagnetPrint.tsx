import { Fragment, useEffect, useMemo, useState, CSSProperties, ReactNode } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { ArrowLeft, Loader2, Printer } from 'lucide-react';
import { useAuth } from '@/hooks/useAuth';
import {
    getLibrary, getOutlierBreakdown,
    type SavedOutlier, type BreakdownPayload, type BreakdownStructureBeat,
} from '@/lib/outlier-service';
import { formatCompact, formatDuration, isShortVideo } from '@/components/outliers/format';

/**
 * Admin lead-magnet PDF: "N winning formats in your niche" — one letter page
 * per selected saved outlier, print-to-PDF via the browser (no PDF lib; the
 * @page/print CSS below owns the geometry). Layout mirrors
 * design-import/lead-magnet-3-formats.dc.html. All page text is
 * contentEditable so wording can be tweaked before saving.
 */

interface FormatItem {
    saved: SavedOutlier;
    breakdown: BreakdownPayload | null;
}

const MONO = "'JetBrains Mono',monospace";
const DISPLAY = "'Archivo',sans-serif";
const BODY = "'Hanken Grotesk',sans-serif";

const snapStr = (s: SavedOutlier, key: string): string | null => {
    const v = (s.snapshot as Record<string, unknown>)[key];
    return typeof v === 'string' ? v : null;
};
const snapNum = (s: SavedOutlier, key: string): number | null => {
    const v = (s.snapshot as Record<string, unknown>)[key];
    return typeof v === 'number' ? v : null;
};

function beatChipColors(beat: BreakdownStructureBeat, index: number, count: number): { bg: string; fg: string } {
    if (beat.highlight === 'red') return { bg: '#FF1F3D', fg: '#fff' };
    if (beat.highlight === 'volt') return { bg: '#16E0C4', fg: '#0A0A0C' };
    if (index === 0) return { bg: '#FF1F3D', fg: '#fff' };
    if (index === count - 1) return { bg: '#16E0C4', fg: '#0A0A0C' };
    return index === 1 ? { bg: '#0A0A0C', fg: '#fff' } : { bg: '#3A3A42', fg: '#fff' };
}

const eyebrowStyle = (color: string): CSSProperties => ({
    fontFamily: MONO, fontSize: 9.5, letterSpacing: '0.14em', color, marginBottom: 4, textTransform: 'uppercase',
});

function InfoCard({ label, labelColor = '#8A8A93', children, style }: {
    label: string; labelColor?: string; children: ReactNode; style?: CSSProperties;
}) {
    return (
        <div style={{ border: '1px solid #E4E4DE', borderRadius: 12, padding: '12px 16px', marginBottom: 10, background: '#fff', ...style }}>
            <div style={eyebrowStyle(labelColor)}>{label}</div>
            {children}
        </div>
    );
}

function CtaRow() {
    return (
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 16, borderTop: '1px solid #FFD6DC', marginTop: 10, paddingTop: 10 }}>
            <span style={{ fontSize: 12, color: '#5B5B63' }}>Copy the transcript, add your angle — draft, schedule and track it in ViewsMax.</span>
            <a href="https://viewsmax.com" style={{ background: '#FF1F3D', color: '#fff', borderRadius: 999, padding: '8px 18px', fontSize: 12.5, fontWeight: 700, whiteSpace: 'nowrap', flexShrink: 0, textDecoration: 'none' }}>Start for free →</a>
        </div>
    );
}

function FormatBlock({ item, index }: { item: FormatItem; index: number }) {
    const { saved, breakdown } = item;
    const title = snapStr(saved, 'title') || 'Untitled';
    const channel = snapStr(saved, 'channel_name');
    const views = snapNum(saved, 'views');
    const likes = snapNum(saved, 'like_count');
    const duration = formatDuration(snapStr(saved, 'duration'));
    const short = isShortVideo({ platform: saved.platform, is_short: (saved.snapshot as Record<string, unknown>).is_short as boolean | null, duration: snapStr(saved, 'duration') });
    const thumb = snapStr(saved, 'thumbnail_medium_url') || snapStr(saved, 'thumbnail_url');
    const formatName = breakdown?.idea.topic || 'Winning format';
    const hookLine = breakdown?.hook?.[0]?.line;
    const beats = (breakdown?.structure.beats || []).slice(0, 5);

    return (
        <>
            {/* Video header: eyebrow + title + meta, thumbnail right */}
            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 20, marginBottom: 12 }}>
                <div style={{ flex: 1 }}>
                    <div style={{ ...eyebrowStyle('#FF1F3D'), fontSize: 11, fontWeight: 700, marginBottom: 6 }}>
                        FORMAT 0{index + 1} · {formatName}
                    </div>
                    <h2 style={{ fontFamily: DISPLAY, fontWeight: 900, fontSize: 22, letterSpacing: '-0.025em', lineHeight: 1.05, margin: '0 0 8px' }}>
                        “{title}”
                    </h2>
                    <div style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap', fontSize: 12.5, color: '#5B5B63' }}>
                        <span>
                            {channel && <><strong style={{ color: '#0A0A0C' }}>{channel}</strong> · </>}
                            {short ? 'Short form' : 'Long form'}{duration ? ` · ${duration}` : ''}
                        </span>
                        {views !== null && (
                            <span style={{ background: '#EFEFEA', borderRadius: 999, padding: '2px 10px', fontFamily: MONO, fontSize: 10.5 }}>{formatCompact(views)} views</span>
                        )}
                        {views === null && likes !== null && (
                            <span style={{ background: '#EFEFEA', borderRadius: 999, padding: '2px 10px', fontFamily: MONO, fontSize: 10.5 }}>{formatCompact(likes)} likes</span>
                        )}
                    </div>
                </div>
                {thumb && (
                    <img
                        src={thumb} alt={`Video thumbnail — ${channel || title}`}
                        style={short
                            ? { width: 96, borderRadius: 10, border: '1px solid #E4E4DE', display: 'block' }
                            : { width: 186, borderRadius: 10, border: '1px solid #E4E4DE', display: 'block' }}
                    />
                )}
            </div>

            {breakdown ? (
                <>
                    <InfoCard label="Why this idea works">
                        <p style={{ margin: 0, fontSize: 13, lineHeight: 1.5 }}>{breakdown.idea.unique_angle || breakdown.idea.idea_seed}</p>
                    </InfoCard>

                    {hookLine && (
                        <InfoCard label="Hook">
                            <p style={{ margin: 0, fontSize: 13, lineHeight: 1.5 }}><strong>“{hookLine}”</strong></p>
                        </InfoCard>
                    )}

                    {beats.length > 0 && (
                        <InfoCard label={`Structure · ${beats.length} beats`}>
                            <div style={{ display: 'grid', gridTemplateColumns: '104px 1fr', gap: '6px 12px', fontSize: 12.5, lineHeight: 1.4, marginTop: 4 }}>
                                {beats.map((b, i) => {
                                    const { bg, fg } = beatChipColors(b, i, beats.length);
                                    return (
                                        <Fragment key={i}>
                                            <span style={{ background: bg, color: fg, borderRadius: 5, padding: '2px 7px', fontFamily: MONO, fontSize: 10, textAlign: 'center', alignSelf: 'start' }}>
                                                {b.label}
                                            </span>
                                            <div><strong>{b.title}.</strong> {b.note}</div>
                                        </Fragment>
                                    );
                                })}
                            </div>
                        </InfoCard>
                    )}

                    <div style={{ border: '2px solid #FF1F3D', borderRadius: 12, padding: '12px 16px', background: '#fff', boxShadow: '4px 4px 0 #FF1F3D' }}>
                        <div style={{ ...eyebrowStyle('#FF1F3D'), fontWeight: 700 }}>Steal this format</div>
                        <p style={{ margin: 0, fontSize: 13, lineHeight: 1.55 }}>
                            <strong>{breakdown.idea.idea_seed}</strong>{breakdown.structure.summary ? ` ${breakdown.structure.summary}` : ''}
                        </p>
                        <CtaRow />
                    </div>
                </>
            ) : (
                <InfoCard label="Breakdown missing" labelColor="#FF1F3D">
                    <p style={{ margin: 0, fontSize: 13, lineHeight: 1.5 }}>This outlier has no completed AI breakdown yet — generate one from the picker, then reload this page.</p>
                </InfoCard>
            )}
        </>
    );
}

export default function LeadMagnetPrint() {
    const { user } = useAuth();
    const [params] = useSearchParams();
    const niche = params.get('niche') || 'your niche';
    const ids = useMemo(
        () => (params.get('ids') || '').split(',').map((s) => parseInt(s, 10)).filter((n) => !Number.isNaN(n)),
        [params],
    );
    const [items, setItems] = useState<FormatItem[] | null>(null);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        if (!ids.length) { setError('No outliers selected.'); return; }
        let cancelled = false;
        (async () => {
            try {
                const library = await getLibrary();
                const byId = new Map(library.map((s) => [s.id, s]));
                const saved = ids.map((id) => byId.get(id)).filter((s): s is SavedOutlier => !!s);
                if (!saved.length) throw new Error('Selected outliers were not found in your library.');
                const loaded = await Promise.all(saved.map(async (s) => {
                    try {
                        const b = await getOutlierBreakdown(s.platform, s.video_id);
                        return { saved: s, breakdown: b.status === 'completed' ? b.payload : null };
                    } catch {
                        return { saved: s, breakdown: null };
                    }
                }));
                if (!cancelled) setItems(loaded);
            } catch (e) {
                if (!cancelled) setError(e instanceof Error ? e.message : 'Failed to load');
            }
        })();
        return () => { cancelled = true; };
    }, [ids]);

    if (!user?.is_admin) {
        return <div style={{ padding: 48, textAlign: 'center', fontFamily: BODY, color: '#76767F' }}>Admins only.</div>;
    }
    if (error) {
        return <div style={{ padding: 48, textAlign: 'center', fontFamily: BODY, color: '#76767F' }}>{error}</div>;
    }
    if (!items) {
        return <div style={{ display: 'flex', justifyContent: 'center', padding: 80 }}><Loader2 size={28} className="animate-spin" style={{ color: '#76767F' }} /></div>;
    }

    const n = items.length;
    const pageStyle: CSSProperties = {
        fontFamily: BODY, background: '#FAFAF8', color: '#0A0A0C',
        padding: '38px 52px', boxSizing: 'border-box', display: 'flex', flexDirection: 'column',
    };

    return (
        <div className="lm-desk">
            <style>{`
                .lm-desk { background: #E9E9E3; min-height: 100vh; padding: 84px 24px 48px; }
                .lm-page {
                    width: 8.5in; height: 11in; overflow: hidden; margin: 0 auto 24px;
                    box-shadow: 0 2px 10px rgba(20,20,19,.18); border-radius: 6px;
                }
                .lm-toolbar { position: fixed; top: 0; left: 0; right: 0; z-index: 20; }
                @media print {
                    @page { size: letter; margin: 0; }
                    html, body, #root {
                        margin: 0 !important; padding: 0 !important; background: none !important;
                        height: auto !important; min-height: 0 !important; overflow: visible !important;
                    }
                    .lm-desk { background: none; padding: 0; min-height: 0; }
                    .lm-toolbar { display: none !important; }
                    .lm-page {
                        margin: 0; box-shadow: none; border-radius: 0;
                        break-after: page; page-break-after: always;
                    }
                    .lm-page:last-child { break-after: auto; page-break-after: auto; }
                    * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                }
            `}</style>

            <div className="lm-toolbar" style={{ display: 'flex', alignItems: 'center', gap: 16, padding: '12px 20px', background: '#0A0A0C', color: '#F6F6F8', fontFamily: BODY }}>
                <Link to="/dashboard/admin/lead-magnet" style={{ display: 'flex', alignItems: 'center', gap: 6, color: '#B3B3BE', fontSize: 13, textDecoration: 'none' }}>
                    <ArrowLeft size={15} /> Back to picker
                </Link>
                <span style={{ fontSize: 13, color: '#76767F' }}>Click any text on the pages to tweak it, then save. Use Letter paper, no margins, background graphics on.</span>
                <button
                    onClick={() => window.print()}
                    style={{ marginLeft: 'auto', display: 'flex', alignItems: 'center', gap: 8, background: '#FF1F3D', color: '#fff', border: 'none', borderRadius: 999, padding: '9px 20px', fontSize: 13.5, fontWeight: 700, cursor: 'pointer', fontFamily: BODY }}
                >
                    <Printer size={15} /> Save as PDF
                </button>
            </div>

            {items.map((item, i) => (
                <section key={item.saved.id} className="lm-page" style={pageStyle} contentEditable suppressContentEditableWarning>
                    {/* Brand header + document title — first page only */}
                    {i === 0 && (
                        <>
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', borderBottom: '2px solid #0A0A0C', paddingBottom: 14, marginBottom: 18 }}>
                                <div style={{ display: 'flex', alignItems: 'center', gap: 9 }}>
                                    <div style={{ width: 24, height: 24, background: '#FF1F3D', borderRadius: 6, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                                        <div style={{ width: 0, height: 0, borderLeft: '8px solid #fff', borderTop: '5px solid transparent', borderBottom: '5px solid transparent', marginLeft: 2 }} />
                                    </div>
                                    <span style={{ fontFamily: DISPLAY, fontWeight: 900, fontSize: 16, letterSpacing: '-0.02em' }}>ViewsMax</span>
                                </div>
                                <a href="https://viewsmax.com" style={{ fontSize: 11, fontFamily: MONO, color: '#FF1F3D', textDecoration: 'none', fontWeight: 700 }}>Analyze any competitor free → viewsmax.com</a>
                            </div>
                            <h1 style={{ fontFamily: DISPLAY, fontWeight: 900, fontSize: 23, lineHeight: 1, letterSpacing: '-0.03em', margin: '0 0 16px' }}>
                                {n} winning video formats in {niche} — replicate them this week
                            </h1>
                        </>
                    )}

                    <FormatBlock item={item} index={i} />

                    {/* Dark full-width CTA — end of the last page */}
                    {i === n - 1 && (
                        <div style={{ marginTop: 'auto', background: '#0A0A0C', borderRadius: 16, padding: '26px 30px', color: '#FAFAF8', position: 'relative', overflow: 'hidden' }}>
                            <div style={{ position: 'absolute', inset: 0, background: 'radial-gradient(circle at 18% 50%, rgba(255,31,61,0.28), transparent 60%)' }} />
                            <div style={{ position: 'relative', display: 'flex', alignItems: 'center', gap: 24 }}>
                                <div style={{ flex: 1 }}>
                                    <h3 style={{ fontFamily: DISPLAY, fontWeight: 900, fontSize: 24, lineHeight: 1, letterSpacing: '-0.03em', margin: '0 0 8px' }}>Find winning formats faster.</h3>
                                    <p style={{ margin: 0, fontSize: 13.5, lineHeight: 1.5, color: '#C9C9CF' }}>Copy the transcripts and add your own unique angle. Draft, plan, schedule your content and track what actually generates views and sales.</p>
                                </div>
                                <a href="https://viewsmax.com" style={{ background: '#FF1F3D', color: '#fff', borderRadius: 999, padding: '13px 26px', fontSize: 15, fontWeight: 700, whiteSpace: 'nowrap', flexShrink: 0, textDecoration: 'none' }}>Start for free →</a>
                            </div>
                        </div>
                    )}

                    {/* Footer strip — last page, below the CTA */}
                    {i === n - 1 && (
                        <div style={{ marginTop: 16, display: 'flex', justifyContent: 'space-between', alignItems: 'center', borderTop: '1px solid #E4E4DE', paddingTop: 12, fontSize: 11.5, color: '#8A8A93' }}>
                            <span>{n} formats that win in {niche} · ViewsMax</span>
                            <a href="https://viewsmax.com" style={{ fontFamily: MONO, fontSize: 11, color: '#FF1F3D', textDecoration: 'none', fontWeight: 700 }}>viewsmax.com</a>
                        </div>
                    )}
                </section>
            ))}
        </div>
    );
}
