import { CSSProperties, MouseEvent } from 'react';
import { useNavigate } from 'react-router-dom';
import { TrendingUp, Eye, Heart, Bookmark, MessageCircle, BarChart3, Star } from 'lucide-react';
import type { OutlierVideo } from '@/lib/outlier-service';
import { BrandIcon, hasBrandIcon } from '@/components/post/brand-icons';
import { formatCompact, formatDuration, formatScore, timeAgo, scoreTier, bestThumbnail, fallbackThumbnail, cardVariant } from './format';

interface Props {
    video: OutlierVideo;
    /** Card shape. Defaults to the video's own classification (see cardVariant). */
    variant?: 'long' | 'shorts';
    saved: boolean;
    onToggleSave: (video: OutlierVideo) => void;
    emojiTiers?: boolean;
    /** Admin-only: toggle this video in/out of the curated default feed. */
    onToggleFeature?: (video: OutlierVideo) => void;
}

const eyebrow: CSSProperties = {
    fontSize: 9,
    letterSpacing: '.12em',
    textTransform: 'uppercase',
    color: 'var(--ink-on-paper-3)',
    fontWeight: 700,
};

const mono = 'var(--font-mono)';

export default function OutlierCard({ video, variant, saved, onToggleSave, emojiTiers = true, onToggleFeature }: Props) {
    const navigate = useNavigate();
    // Instagram used to ship without views/score; newer rows have them. Gate on the
    // data, not the platform, so every platform renders the same card.
    const isIG = video.views == null;
    const isShorts = (variant ?? cardVariant(video)) === 'shorts';
    const tier = scoreTier(video.outlier_score, emojiTiers);
    const thumb = bestThumbnail(video);
    const durationLabel = formatDuration(video.duration);
    const handle = video.channel?.channel_name || '';
    const score = Math.round(video.outlier_score ?? 0);
    const barWidth = `${Math.min(100, Math.max(0, score))}%`;

    const openBreakdown = () => navigate(`/dashboard/outliers/breakdown/${video.platform}/${encodeURIComponent(video.youtube_video_id)}`);
    const handleSave = (e: MouseEvent) => {
        e.stopPropagation();
        onToggleSave(video);
    };

    const cardStyle: CSSProperties = {
        background: 'var(--paper-0)',
        border: '1px solid var(--line-1)',
        borderRadius: 'var(--r-lg)',
        overflow: 'hidden',
        boxShadow: '0 1px 3px rgba(10,10,12,.05)',
        alignSelf: 'start',
        cursor: 'pointer',
        transition: 'box-shadow .12s, border-color .12s',
    };

    return (
        <div
            style={cardStyle}
            onClick={openBreakdown}
            onMouseEnter={(e) => { e.currentTarget.style.boxShadow = '4px 4px 0 0 var(--line-2)'; e.currentTarget.style.borderColor = 'var(--line-2)'; }}
            onMouseLeave={(e) => { e.currentTarget.style.boxShadow = '0 1px 3px rgba(10,10,12,.05)'; e.currentTarget.style.borderColor = 'var(--line-1)'; }}
        >
            {/* Thumbnail */}
            <div style={{ position: 'relative', aspectRatio: isShorts ? '9 / 16' : '16 / 9', background: 'var(--ink-800)' }}>
                {thumb && (
                    <img
                        src={thumb} alt="" loading="lazy"
                        onError={(e) => { const fb = fallbackThumbnail(video); if (fb && e.currentTarget.src !== fb) e.currentTarget.src = fb; }}
                        style={{ width: '100%', height: '100%', objectFit: 'cover', display: 'block' }}
                    />
                )}
                {hasBrandIcon(video.platform) && (
                    <span style={{ position: 'absolute', left: 8, bottom: 8, width: 24, height: 24, borderRadius: '50%', background: 'rgba(6,6,8,.75)', display: 'flex', alignItems: 'center', justifyContent: 'center' }} title={video.platform}>
                        <BrandIcon platform={video.platform} size={13} color="#fff" />
                    </span>
                )}
                {durationLabel && (
                    <span style={{ position: 'absolute', right: 8, bottom: 8, background: 'rgba(6,6,8,.8)', color: '#fff', fontFamily: mono, fontSize: 11, padding: '3px 8px', borderRadius: 6 }}>
                        {durationLabel}
                    </span>
                )}
                <button
                    onClick={handleSave}
                    aria-label={saved ? 'Remove from library' : 'Save to library'}
                    style={{ position: 'absolute', top: 8, right: 8, width: 32, height: 32, borderRadius: '50%', border: 'none', background: 'rgba(6,6,8,.65)', display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer' }}
                    onMouseEnter={(e) => (e.currentTarget.style.background = 'rgba(6,6,8,.9)')}
                    onMouseLeave={(e) => (e.currentTarget.style.background = 'rgba(6,6,8,.65)')}
                >
                    <Bookmark size={15} fill={saved ? 'var(--vm-red)' : 'none'} stroke={saved ? 'var(--vm-red)' : '#fff'} />
                </button>
                {onToggleFeature && (
                    <button
                        onClick={(e) => { e.stopPropagation(); onToggleFeature(video); }}
                        aria-label={video.featured ? 'Remove from default feed' : 'Feature on default feed'}
                        title={video.featured ? 'Remove from default feed' : 'Feature on default feed'}
                        style={{ position: 'absolute', top: 46, right: 8, width: 32, height: 32, borderRadius: '50%', border: 'none', background: 'rgba(6,6,8,.65)', display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer' }}
                        onMouseEnter={(e) => (e.currentTarget.style.background = 'rgba(6,6,8,.9)')}
                        onMouseLeave={(e) => (e.currentTarget.style.background = 'rgba(6,6,8,.65)')}
                    >
                        <Star size={15} fill={video.featured ? 'var(--vm-volt)' : 'none'} stroke={video.featured ? 'var(--vm-volt)' : '#fff'} />
                    </button>
                )}
            </div>

            {isShorts ? (
                <ShortsBody video={video} isIG={isIG} tier={tier} handle={handle} />
            ) : (
                <LongBody video={video} isIG={isIG} tier={tier} score={score} barWidth={barWidth} />
            )}

            {/* Breakdown affordance — whole card navigates too, this makes it obvious */}
            <button
                onClick={(e) => { e.stopPropagation(); openBreakdown(); }}
                style={{ width: '100%', background: 'transparent', border: 'none', borderTop: '1px solid var(--line-1)', padding: '9px 16px', display: 'flex', alignItems: 'center', justifyContent: 'center', gap: 6, cursor: 'pointer', ...eyebrow, fontSize: 10 }}
                onMouseEnter={(e) => { e.currentTarget.style.background = 'var(--paper-1)'; e.currentTarget.style.color = 'var(--vm-red)'; }}
                onMouseLeave={(e) => { e.currentTarget.style.background = 'transparent'; e.currentTarget.style.color = 'var(--ink-on-paper-3)'; }}
            >
                <BarChart3 size={12} />Breakdown
            </button>
        </div>
    );
}

function ShortsBody({ video, isIG, tier, handle }: { video: OutlierVideo; isIG: boolean; tier: ReturnType<typeof scoreTier>; handle: string }) {
    return (
        <div style={{ padding: '10px 12px 12px', display: 'flex', flexDirection: 'column', gap: 7 }}>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, fontFamily: 'var(--font-mono)', fontSize: 13, fontWeight: 700, color: 'var(--ink-on-paper-1)', flexWrap: 'wrap' }}>
                {isIG ? (
                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }} title="Comments">
                        <MessageCircle size={13} stroke="#76767F" />{formatCompact(video.comment_count)}
                    </span>
                ) : (
                    <>
                        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, color: tier.scoreColor }} title="Outlier score">
                            <TrendingUp size={13} />{formatScore(video.outlier_score)}
                        </span>
                        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }} title="Views">
                            <Eye size={13} stroke="#76767F" />{formatCompact(video.views)}
                        </span>
                        {video.engagement_rate != null && (
                            <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }} title="Engagement">
                                <Heart size={13} stroke="#76767F" />{video.engagement_rate}%
                            </span>
                        )}
                    </>
                )}
            </div>
            <div style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 14, color: 'var(--ink-on-paper-1)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                {video.title}
            </div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 12, color: 'var(--ink-on-paper-3)' }}>
                <span style={{ fontWeight: 700, color: 'var(--ink-on-paper-2)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{handle}</span>
                <span style={{ marginLeft: 'auto', fontFamily: 'var(--font-mono)' }}>{timeAgo(video.published_at)}</span>
            </div>
        </div>
    );
}

function LongBody({ video, isIG, tier, score, barWidth }: {
    video: OutlierVideo; isIG: boolean; tier: ReturnType<typeof scoreTier>; score: number; barWidth: string;
}) {
    return (
        <>
            {/* Top block: score + bar / views — hidden when views are unknown */}
            {!isIG && (
                <div style={{ padding: '14px 16px 6px', display: 'grid', gridTemplateColumns: '1fr 1fr', alignItems: 'end', gap: 8 }}>
                    <div>
                        <div style={{ fontFamily: 'var(--font-display)', fontWeight: 900, fontSize: 26, lineHeight: 1, color: tier.scoreColor }}>
                            {formatScore(video.outlier_score)}{tier.emoji ? ' ' : ''}
                        </div>
                        <div style={{ ...eyebrow, marginTop: 3 }}>Outlier score</div>
                        <div style={{ position: 'relative', height: 6, background: 'var(--paper-2)', borderRadius: 'var(--r-pill)', marginTop: 8 }}>
                            <div style={{ position: 'absolute', inset: 0, width: barWidth, background: tier.barColor, borderRadius: 'var(--r-pill)' }} />
                            {tier.emoji && (
                                <span style={{ position: 'absolute', top: '50%', left: barWidth, transform: 'translate(-55%,-58%)', fontSize: 16, filter: 'drop-shadow(0 1px 1px rgba(0,0,0,.25))' }}>{tier.emoji}</span>
                            )}
                        </div>
                    </div>
                    <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-end' }}>
                        <div style={{ display: 'inline-flex', alignItems: 'center', gap: 5, fontFamily: 'var(--font-mono)', fontSize: 17, fontWeight: 700, color: 'var(--ink-on-paper-1)' }}>
                            <Eye size={15} stroke="#76767F" />{formatCompact(video.views)}
                        </div>
                        <div style={eyebrow}>Views</div>
                    </div>
                </div>
            )}

            {/* Title + channel */}
            <div style={{ padding: isIG ? '14px 16px 16px' : '10px 16px 16px' }}>
                <div style={{ fontFamily: 'var(--font-display)', fontWeight: 700, fontSize: 16, lineHeight: 1.25, color: 'var(--ink-on-paper-1)', display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical', overflow: 'hidden', minHeight: 40 }}>
                    {video.title}
                </div>
                <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginTop: 8, fontSize: 12.5, color: 'var(--ink-on-paper-3)' }}>
                    <span title={video.channel?.channel_name || 'Unknown'} style={{ fontWeight: 700, color: 'var(--ink-on-paper-2)', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', maxWidth: '12ch' }}>{video.channel?.channel_name || 'Unknown'}</span>
                    {isIG ? (
                        <span style={{ marginLeft: 'auto', display: 'inline-flex', alignItems: 'center', gap: 4, fontFamily: 'var(--font-mono)' }} title="Comments">
                            <MessageCircle size={13} stroke="#76767F" />{formatCompact(video.comment_count)}
                        </span>
                    ) : (
                        <>
                            {video.channel?.subscriber_count != null && (
                                <span style={{ fontFamily: 'var(--font-mono)' }}>· {formatCompact(video.channel.subscriber_count)} subs</span>
                            )}
                            <span style={{ marginLeft: 'auto', fontFamily: 'var(--font-mono)' }}>{timeAgo(video.published_at)}</span>
                        </>
                    )}
                </div>
            </div>
        </>
    );
}
