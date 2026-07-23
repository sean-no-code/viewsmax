// X text rules: t.co-aware length weighting and `---` thread splitting.
//
// This is the TypeScript mirror of the backend's single source of truth,
// viewsmaxbackend/app/Services/Social/CaptionRules.php — any change to the
// counting algorithm, URL regex, or split regex must be made in BOTH files or
// the composer and the API will disagree about validity.

export const X_LIMIT = 280;
export const X_URL_WEIGHT = 23; // every URL counts as a fixed 23 chars (t.co)
export const X_MAX_SEGMENTS = 25;
export const X_MAX_IMAGES = 4;

// Keep character-for-character identical to CaptionRules::X_URL_REGEX.
const X_URL_RE = /(?:https?:\/\/|www\.)\S+/giu;
// A line that is exactly `---` (surrounding blanks allowed) splits X threads.
const X_THREAD_DELIMITER_RE = /^[ \t]*---[ \t]*$/m;

const normalize = (text: string) => text.replace(/\r\n?/g, "\n");

/** Plain code-point count — what every platform except X uses. */
export function plainLength(text: string): number {
  return [...normalize(text)].length;
}

/**
 * t.co-aware weighted length for X: every URL counts 23, remaining code
 * points weigh 1 in twitter-text's "light" ranges and 2 otherwise (CJK,
 * emoji). Same accepted drift as the backend: bare domains without a scheme
 * or `www.` aren't weighted; URL-glued punctuation is absorbed into the 23.
 */
export function xWeightedLength(text: string): number {
  const normalized = normalize(text);
  if (!normalized) return 0;

  const urls = normalized.match(X_URL_RE)?.length ?? 0;
  const rest = normalized.replace(X_URL_RE, "");

  let length = urls * X_URL_WEIGHT;
  for (const ch of rest) {
    const cp = ch.codePointAt(0)!;
    const light =
      cp <= 4351 ||                    // most Latin/Cyrillic/etc.
      (cp >= 8192 && cp <= 8205) ||    // general punctuation spaces/joiners
      (cp >= 8208 && cp <= 8223) ||    // dashes & quotes
      (cp >= 8242 && cp <= 8247);      // primes
    length += light ? 1 : 2;
  }
  return length;
}

/** Per-platform effective length: X is URL-weighted, everything else plain. */
export function platformLength(id: string, text: string): number {
  return id === "x" ? xWeightedLength(text) : plainLength(text);
}

/** Split an X caption into trimmed thread segments on `---` lines. */
export function splitXThread(text: string): string[] {
  const normalized = normalize(text);
  if (!normalized.trim()) return [];
  return normalized.split(X_THREAD_DELIMITER_RE).map((s) => s.trim());
}

export interface XSegment {
  text: string;
  len: number;
  over: boolean;
  empty: boolean;
}

export interface XThreadStatus {
  segments: XSegment[];
  tooMany: boolean;
  /** Any segment over 280 / empty, or more than 25 segments. */
  invalid: boolean;
  /** Weighted length of the longest segment (drives the counter chip). */
  maxLen: number;
}

export function xThreadStatus(text: string): XThreadStatus {
  const segments = splitXThread(text).map((s): XSegment => {
    const len = xWeightedLength(s);
    return { text: s, len, over: len > X_LIMIT, empty: s === "" };
  });
  // A blank caption isn't an invalid thread — media-only X posts are fine.
  const tooMany = segments.length > X_MAX_SEGMENTS;
  const invalid = tooMany || segments.some((s) => s.over || (s.empty && segments.length > 1));
  const maxLen = segments.reduce((m, s) => Math.max(m, s.len), 0);
  return { segments, tooMany, invalid, maxLen };
}

/**
 * Auto-split text into ≤280-weighted segments joined with `---` lines.
 * Prefers paragraph breaks, then sentence ends, then word boundaries.
 */
export function autoSplitX(text: string): string {
  const normalized = normalize(text).trim();
  if (!normalized) return normalized;

  const segments: string[] = [];
  let rest = normalized;

  while (rest && segments.length < X_MAX_SEGMENTS) {
    if (xWeightedLength(rest) <= X_LIMIT) {
      segments.push(rest);
      break;
    }

    // Grow a window word-by-word up to the limit, remembering the best
    // paragraph/sentence break seen along the way.
    let cut = 0;
    let paragraphCut = 0;
    let sentenceCut = 0;
    const breaks = [...rest.matchAll(/\n\n+|[.!?]["')\]]?(?=\s)|\s+/g)];
    for (const m of breaks) {
      const end = m.index!;
      if (end === 0) continue;
      if (xWeightedLength(rest.slice(0, end)) > X_LIMIT) break;
      cut = end;
      if (/^\n\n/.test(m[0])) {
        paragraphCut = end;
      } else if (/^[.!?]/.test(m[0])) {
        // Cut after the punctuation — only if it still fits.
        const sEnd = end + m[0].length;
        if (xWeightedLength(rest.slice(0, sEnd)) <= X_LIMIT) sentenceCut = sEnd;
      }
    }
    const at = paragraphCut || sentenceCut || cut;
    if (!at) {
      // One unbreakable blob (e.g. a giant word): hard-cut at the limit.
      const chars = [...rest];
      let end = 0;
      while (end < chars.length && xWeightedLength(chars.slice(0, end + 1).join("")) <= X_LIMIT) end++;
      segments.push(chars.slice(0, end).join(""));
      rest = chars.slice(end).join("").trim();
      continue;
    }
    segments.push(rest.slice(0, at).trim());
    rest = rest.slice(at).trim();
  }
  if (rest && segments[segments.length - 1] !== rest && xWeightedLength(rest) > 0 && segments.length >= X_MAX_SEGMENTS) {
    // Out of segments — append the remainder to the last one; validation will flag it.
    segments[segments.length - 1] += `\n${rest}`;
  }

  return segments.join("\n---\n");
}
