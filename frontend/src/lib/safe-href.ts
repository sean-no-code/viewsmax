/**
 * An href built from data we don't control (tracked referrers, user-entered
 * URLs) is only rendered as a link when it is an absolute http(s) URL.
 * React 18 passes javascript: URLs straight through, so anything else —
 * javascript:, data:, protocol-relative, bare hosts — returns undefined and
 * the caller renders plain text instead.
 */
export function safeHref(url: string | null | undefined): string | undefined {
  if (!url) return undefined;
  // Browsers ignore tabs/newlines inside a scheme ("java\nscript:"), so test the URL with them removed.
  const normalized = url.replace(/[\u0000-\u001F\u007F\s]+/g, "");
  return /^https?:\/\//i.test(normalized) ? url.trim() : undefined;
}
