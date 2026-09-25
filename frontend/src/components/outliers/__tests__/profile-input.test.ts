import { describe, expect, it } from 'vitest';
import { parseProfileInput } from '../profile-input';

describe('parseProfileInput', () => {
    it('recognises profile URLs on every platform', () => {
        expect(parseProfileInput('https://www.youtube.com/@MrBeast')).toEqual({ platform: 'youtube', handle: 'mrbeast' });
        expect(parseProfileInput('youtube.com/@MrBeast/videos'.replace(/^/, 'www.'))).toEqual({ platform: 'youtube', handle: 'mrbeast' });
        expect(parseProfileInput('https://www.youtube.com/channel/UCX6OQ3DkcsbYNE6H8uQQuVA')).toEqual({ platform: 'youtube', handle: 'UCX6OQ3DkcsbYNE6H8uQQuVA' });
        expect(parseProfileInput('https://www.youtube.com/user/PewDiePie')).toEqual({ platform: 'youtube', handle: 'pewdiepie' });
        expect(parseProfileInput('https://www.tiktok.com/@khaby.lame?lang=en')).toEqual({ platform: 'tiktok', handle: 'khaby.lame' });
        expect(parseProfileInput('https://www.instagram.com/nasa/')).toEqual({ platform: 'instagram', handle: 'nasa' });
        expect(parseProfileInput('https://instagram.com/NASA/reels/')).toEqual({ platform: 'instagram', handle: 'nasa' });
    });

    it('returns a platform-less result for bare handles', () => {
        expect(parseProfileInput('@Khaby.Lame')).toEqual({ platform: null, handle: 'khaby.lame' });
        expect(parseProfileInput('@not a handle')).toBeNull();
    });

    it('leaves video links, keywords and app pages alone', () => {
        expect(parseProfileInput('https://www.youtube.com/watch?v=dQw4w9WgXcQ')).toBeNull();
        expect(parseProfileInput('https://www.youtube.com/shorts/dQw4w9WgXcQ')).toBeNull();
        expect(parseProfileInput('https://youtu.be/dQw4w9WgXcQ')).toBeNull();
        expect(parseProfileInput('https://www.tiktok.com/@khaby.lame/video/7646812028874673439')).toBeNull();
        expect(parseProfileInput('https://www.instagram.com/reel/DbYaLffE2DD/')).toBeNull();
        expect(parseProfileInput('https://www.instagram.com/explore/')).toBeNull();
        expect(parseProfileInput('https://www.youtube.com/c/SomeChannel')).toBeNull();
        expect(parseProfileInput('faceless youtube automation')).toBeNull();
        expect(parseProfileInput('https://vimeo.com/someone')).toBeNull();
        expect(parseProfileInput('')).toBeNull();
    });
});
