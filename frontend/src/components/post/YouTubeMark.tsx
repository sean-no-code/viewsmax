// The official YouTube icon, for every place the app shows YouTube.
//
// YouTube API Services branding guidelines
// (https://developers.google.com/youtube/terms/branding-guidelines) only allow
// the official mark: its own shape and colours (red #FF0033 and white), never
// recoloured, tinted, greyed out, dimmed, cropped or drawn inside another
// shape, never under 20px high on screen, and linking to YouTube (the channel
// when we know it) where a click does nothing else. The API compliance review
// checks every icon, so the YouTube glyph in brand-icons.tsx (one colour, any
// size) must not be used for YouTube; use this instead.
//
// The geometry is the official icon from https://brand.youtube/youtube-icon.

/** Width / height of the official icon. */
export const YOUTUBE_MARK_RATIO = 396.533 / 277.776;

/** "The minimum height for digital media is 20 dp (20 px)." */
export const YOUTUBE_MARK_MIN_HEIGHT = 20;

/**
 * The mark's size for a slot of `size` px: at least as wide as the slot (so it
 * replaces a square icon of that size) and never under the minimum height, so
 * in a small slot it is bigger than the slot.
 */
export function youtubeMarkSize(size: number): { width: number; height: number } {
  const width = Math.max(size, YOUTUBE_MARK_MIN_HEIGHT * YOUTUBE_MARK_RATIO);
  const round = (n: number) => Math.round(n * 100) / 100;
  return { width: round(width), height: round(width / YOUTUBE_MARK_RATIO) };
}

/**
 * Where a click on the mark goes: the channel when we know its id (UC…) or
 * @handle, a youtube.com URL as given, otherwise YouTube's home page.
 */
export function youtubeMarkHref(channel?: string | null): string {
  const c = (channel ?? "").trim();
  if (/^UC[\w-]{22}$/.test(c)) return `https://www.youtube.com/channel/${c}`;
  if (/^@[^\s/?#\\]+$/.test(c)) return `https://www.youtube.com/${encodeURI(c)}`;
  if (/^https:\/\/(www\.|m\.)?youtube\.com\//i.test(c)) return c;
  return "https://www.youtube.com";
}

/**
 * The official YouTube mark.
 *
 * - `size`: the square slot it replaces; see youtubeMarkSize.
 * - `channel`: channel id, @handle or youtube.com URL to link to.
 * - `link`: false only where the mark sits inside another clickable element
 *   (a button, a link, a clickable row), since a link cannot be nested there
 *   and the click already does something else. Otherwise it opens YouTube in
 *   a new tab, without triggering the click handlers around it.
 */
export function YouTubeMark({ size = YOUTUBE_MARK_MIN_HEIGHT, channel, link = true, title = "YouTube" }: {
  size?: number;
  channel?: string | null;
  link?: boolean;
  title?: string;
}) {
  const { width, height } = youtubeMarkSize(size);
  const svg = (
    <svg
      width={width}
      height={height}
      viewBox="102.827 119.046 396.533 277.776"
      role="img"
      aria-label={title}
      focusable="false"
      style={{ display: "block", flex: "none", minWidth: width, minHeight: height }}
    >
      <path
        fill="#FF0033"
        transform="matrix(1 0 0 -1 261.5556 317.457)"
        d="M0 0L102.827 59.668L0 119.046ZM-115.28 -70.965C-132.37 -66.33 -145.694 -52.717 -150.618 -35.917C-158.728 -4.924 -158.728 59.668 -158.728 59.668C-158.728 59.668 -158.728 124.26 -150.618 154.963C-145.694 172.052 -132.37 185.377 -115.28 190.011C-84.577 198.411 39.393 198.411 39.393 198.411C39.393 198.411 163.653 198.411 194.357 190.011C211.736 185.377 224.77 172.052 229.405 154.963C237.804 124.26 237.804 59.668 237.804 59.668C237.804 59.668 237.804 -4.924 229.405 -35.917C224.77 -52.717 211.736 -66.33 194.357 -70.965C163.653 -79.365 39.393 -79.365 39.393 -79.365C39.393 -79.365 -84.577 -79.365 -115.28 -70.965"
      />
      <path fill="#FFFFFF" transform="matrix(1 0 0 -1 261.5556 317.457)" d="M0 0L102.827 59.668L0 119.046Z" />
    </svg>
  );
  const wrap = { display: "inline-flex", flex: "none", lineHeight: 0 } as const;

  if (!link) {
    return <span title={title} style={wrap}>{svg}</span>;
  }
  return (
    <a
      href={youtubeMarkHref(channel)}
      target="_blank"
      rel="noopener noreferrer"
      title={title}
      onClick={(e) => e.stopPropagation()}
      style={wrap}
    >
      {svg}
    </a>
  );
}
