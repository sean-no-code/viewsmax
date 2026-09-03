// Compact human durations for admin metrics ("30 min", "3 h", "12 d").
// Deliberately coarse: one unit, no compound "1 h 20 min" — these cells answer
// "roughly how fast/slow", not "exactly when" (the date sits next to them).

export function humanizeDuration(ms: number): string {
  const minutes = ms / 60_000;
  if (minutes < 1) return "<1 min";
  if (minutes < 60) return `${Math.round(minutes)} min`;
  const hours = minutes / 60;
  if (hours < 48) return `${Math.round(hours)} h`;
  return `${Math.round(hours / 24)} d`;
}

/** Duration between two ISO timestamps; null when either is missing/invalid/reversed. */
export function durationBetween(fromIso: string | null | undefined, toIso: string | null | undefined): string | null {
  if (!fromIso || !toIso) return null;
  const from = new Date(fromIso).getTime();
  const to = new Date(toIso).getTime();
  if (Number.isNaN(from) || Number.isNaN(to) || to < from) return null;
  return humanizeDuration(to - from);
}
