/**
 * Longest keyword search the API accepts. Mirrors the backend's
 * YouTubeSearchService::MAX_TERM_LENGTH (search_terms.term is varchar(255)).
 */
export const MAX_SEARCH_TERM_LENGTH = 200;

/**
 * How many characters a keyword search is over the limit (0 when it fits).
 * Pasted URLs never count: they take the video / profile path, not the
 * keyword search. Counts code points, like the server's mb_strlen.
 */
export function searchTermOverflow(raw: string): number {
    const value = raw.trim();
    if (/^(https?:\/\/|www\.)/i.test(value)) return 0;
    return Math.max(0, [...value].length - MAX_SEARCH_TERM_LENGTH);
}
