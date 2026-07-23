import { useCallback, useEffect, useRef, useState } from "react";
import { viewsMaxApi, type XUserSuggestion } from "@/lib/api-service";

/**
 * @mention typeahead over a plain <textarea>: detects an "@token" being typed
 * at the caret, shows recent mentions instantly, and (after a 300ms debounce)
 * queries the X user-search proxy. When the app's X API tier blocks live
 * search the backend degrades to exact-handle lookup — the hook remembers
 * that (module-wide) and adjusts the UI copy via `degraded`.
 */

const RECENTS_KEY = "vm_recent_mentions";
const RECENTS_CAP = 20;
const MIN_QUERY_FOR_NETWORK = 2;

// The token being typed: an "@" not glued to a word/handle, then handle chars.
const TOKEN_RE = /(^|[^A-Za-z0-9_@])@([A-Za-z0-9_]{0,15})$/;

let searchDegraded = false; // app-tier fact, shared across all composer fields

function loadRecents(): XUserSuggestion[] {
  try {
    const raw = localStorage.getItem(RECENTS_KEY);
    return raw ? (JSON.parse(raw) as XUserSuggestion[]) : [];
  } catch {
    return [];
  }
}

function pushRecent(user: XUserSuggestion): void {
  try {
    const rest = loadRecents().filter((u) => u.username.toLowerCase() !== user.username.toLowerCase());
    localStorage.setItem(RECENTS_KEY, JSON.stringify([user, ...rest].slice(0, RECENTS_CAP)));
  } catch {
    /* storage full/blocked — recents are best-effort */
  }
}

export interface MentionAutocomplete {
  open: boolean;
  loading: boolean;
  degraded: boolean;
  query: string;
  suggestions: XUserSuggestion[];
  highlight: number;
  setHighlight: (i: number) => void;
  insert: (user: XUserSuggestion) => void;
  close: () => void;
  /** Re-run token detection (call on click/keyup — the caret moves without a change event). */
  detect: () => void;
  /** Keyboard interception while the popover is open; returns true when handled. */
  onKeyDown: (e: React.KeyboardEvent<HTMLTextAreaElement>) => boolean;
}

export function useMentionAutocomplete({
  enabled,
  value,
  onChangeText,
  textareaRef,
  accountId,
}: {
  enabled: boolean;
  value: string;
  onChangeText: (v: string) => void;
  textareaRef: React.RefObject<HTMLTextAreaElement>;
  accountId?: number | null;
}): MentionAutocomplete {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [tokenStart, setTokenStart] = useState(0); // index of the "@" in value
  const [tokenEnd, setTokenEnd] = useState(0); // caret position at detect time
  const [loading, setLoading] = useState(false);
  const [degraded, setDegraded] = useState(searchDegraded);
  const [remote, setRemote] = useState<XUserSuggestion[]>([]);
  const [highlight, setHighlight] = useState(0);
  const seq = useRef(0); // stale-response guard

  const close = useCallback(() => {
    setOpen(false);
    setRemote([]);
    setLoading(false);
    seq.current++;
  }, []);

  const detect = useCallback(() => {
    const el = textareaRef.current;
    if (!enabled || !el || document.activeElement !== el) {
      close();
      return;
    }
    const caret = el.selectionStart ?? 0;
    // Only a collapsed caret can be "mid-token".
    if (caret !== (el.selectionEnd ?? caret)) {
      close();
      return;
    }
    const m = TOKEN_RE.exec(value.slice(0, caret));
    if (!m) {
      close();
      return;
    }
    const token = m[2];
    setQuery(token);
    setTokenStart(caret - token.length - 1);
    setTokenEnd(caret);
    setHighlight(0);
    setOpen(true);
  }, [enabled, value, textareaRef, close]);

  // The value changing moves the caret too — re-detect on every edit.
  useEffect(() => {
    detect();
  }, [detect]);

  // Debounced network search once the token is long enough.
  useEffect(() => {
    if (!open || query.length < MIN_QUERY_FOR_NETWORK) {
      setRemote([]);
      setLoading(false);
      return;
    }
    const mySeq = ++seq.current;
    setLoading(true);
    const t = setTimeout(async () => {
      const res = await viewsMaxApi.searchXUsers(query, accountId ?? undefined);
      if (mySeq !== seq.current) return; // a newer request superseded this one
      setLoading(false);
      if (!res.success || !res.data) {
        setRemote([]); // rate-limited / unavailable — recents still show
        return;
      }
      if (res.data.degraded || res.data.mode === "lookup") {
        searchDegraded = true;
        setDegraded(true);
      }
      setRemote(res.data.users);
    }, 300);
    return () => clearTimeout(t);
  }, [open, query, accountId]);

  // Recents matching the token render instantly; network results follow.
  const q = query.toLowerCase();
  const recents = loadRecents().filter((u) => q === "" || u.username.toLowerCase().startsWith(q) || u.name.toLowerCase().startsWith(q));
  const seen = new Set(recents.map((u) => u.username.toLowerCase()));
  const suggestions = [...recents, ...remote.filter((u) => !seen.has(u.username.toLowerCase()))].slice(0, 8);

  const insert = useCallback((user: XUserSuggestion) => {
    const before = value.slice(0, tokenStart);
    const after = value.slice(tokenEnd);
    const inserted = `@${user.username} `;
    onChangeText(before + inserted + after);
    pushRecent(user);
    close();
    const caretPos = before.length + inserted.length;
    requestAnimationFrame(() => {
      const el = textareaRef.current;
      if (el) {
        el.focus();
        el.setSelectionRange(caretPos, caretPos);
      }
    });
  }, [value, tokenStart, tokenEnd, onChangeText, close, textareaRef]);

  const onKeyDown = useCallback((e: React.KeyboardEvent<HTMLTextAreaElement>): boolean => {
    if (!open || suggestions.length === 0) {
      if (open && e.key === "Escape") {
        e.preventDefault();
        close();
        return true;
      }
      return false;
    }
    if (e.key === "ArrowDown") {
      e.preventDefault();
      setHighlight((h) => Math.min(h + 1, suggestions.length - 1));
      return true;
    }
    if (e.key === "ArrowUp") {
      e.preventDefault();
      setHighlight((h) => Math.max(h - 1, 0));
      return true;
    }
    if (e.key === "Enter" || e.key === "Tab") {
      e.preventDefault();
      insert(suggestions[Math.min(highlight, suggestions.length - 1)]);
      return true;
    }
    if (e.key === "Escape") {
      e.preventDefault();
      close();
      return true;
    }
    return false;
  }, [open, suggestions, highlight, insert, close]);

  return { open, loading, degraded, query, suggestions, highlight, setHighlight, insert, close, detect, onKeyDown };
}
