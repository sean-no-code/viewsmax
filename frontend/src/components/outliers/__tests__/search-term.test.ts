import { describe, expect, it } from 'vitest';
import { MAX_SEARCH_TERM_LENGTH, searchTermOverflow } from '../search-term';

describe('searchTermOverflow', () => {
    it('is 0 for a keyword search within the limit', () => {
        expect(searchTermOverflow('faceless youtube automation')).toBe(0);
        expect(searchTermOverflow('a'.repeat(MAX_SEARCH_TERM_LENGTH))).toBe(0);
    });

    it('counts the characters over the limit for a long keyword search', () => {
        expect(searchTermOverflow('a'.repeat(MAX_SEARCH_TERM_LENGTH + 5))).toBe(5);
    });

    it('ignores surrounding whitespace, which the server trims', () => {
        expect(searchTermOverflow(`  ${'a'.repeat(MAX_SEARCH_TERM_LENGTH)}  `)).toBe(0);
    });

    it('counts characters, not UTF-16 units, like the server', () => {
        expect(searchTermOverflow('😀'.repeat(MAX_SEARCH_TERM_LENGTH))).toBe(0);
    });

    it('never limits a pasted URL, which takes the video path instead', () => {
        const long = 'x'.repeat(MAX_SEARCH_TERM_LENGTH + 50);
        expect(searchTermOverflow(`https://www.youtube.com/watch?v=abc&list=${long}`)).toBe(0);
        expect(searchTermOverflow(`www.tiktok.com/@a/video/1?${long}`)).toBe(0);
    });
});
