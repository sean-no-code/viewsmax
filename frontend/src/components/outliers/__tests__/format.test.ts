import { describe, expect, it } from 'vitest';
import { cardVariant, formatBeatTime, isShortVideo } from '../format';

describe('isShortVideo', () => {
    it('trusts the backend classification over everything else', () => {
        expect(isShortVideo({ platform: 'youtube', is_short: true, duration: 'PT10M' })).toBe(true);
        expect(isShortVideo({ platform: 'youtube', is_short: false, duration: 'PT30S' })).toBe(false);
        expect(isShortVideo({ platform: 'tiktok', is_short: false })).toBe(false);
    });

    it('treats unclassified TikTok and Instagram as shorts', () => {
        expect(isShortVideo({ platform: 'tiktok', is_short: null, duration: 'PT10M' })).toBe(true);
        expect(isShortVideo({ platform: 'instagram', duration: null })).toBe(true);
    });

    it('falls back to the legacy 180s duration rule for unclassified YouTube', () => {
        expect(isShortVideo({ platform: 'youtube', is_short: null, duration: 'PT2M' })).toBe(true);
        expect(isShortVideo({ platform: 'youtube', duration: 'PT3M20S' })).toBe(false);
        expect(isShortVideo({ platform: 'youtube', duration: 'P0D' })).toBe(false);   // live stream → long
        expect(isShortVideo({ platform: 'youtube', duration: null })).toBe(false);
    });
});

describe('cardVariant', () => {
    it('maps to the card shape', () => {
        expect(cardVariant({ platform: 'youtube', is_short: true })).toBe('shorts');
        expect(cardVariant({ platform: 'youtube', is_short: false })).toBe('long');
    });
});

describe('formatBeatTime', () => {
    it('renders raw seconds as clock time', () => {
        expect(formatBeatTime('1320s')).toBe('22:00');
        expect(formatBeatTime('3180s')).toBe('53:00');
        expect(formatBeatTime('3s')).toBe('0:03');
        expect(formatBeatTime('2.6s')).toBe('0:03');
        expect(formatBeatTime('4000s')).toBe('1:06:40');
        expect(formatBeatTime(95)).toBe('1:35');
    });

    it('passes through clock times and unknown formats', () => {
        expect(formatBeatTime('0:05')).toBe('0:05');
        expect(formatBeatTime('1:02:03')).toBe('1:02:03');
        expect(formatBeatTime('intro')).toBe('intro');
        expect(formatBeatTime(null)).toBe('');
    });
});
