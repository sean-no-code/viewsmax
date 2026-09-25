// Dark "bookmark us" strip shown under the nav on the free tools. The keyboard
// hint follows the visitor's OS: ⌘ D on macOS, Ctrl D everywhere else. Phones
// and tablets have no bookmark shortcut, so they get the plain sentence.
import { useState } from "react";
import { Bookmark } from "lucide-react";

type Platform = "mac" | "desktop" | "mobile";

function detectPlatform(): Platform {
  if (typeof navigator === "undefined") return "desktop";
  const nav = navigator as Navigator & { userAgentData?: { platform?: string; mobile?: boolean } };
  const ua = nav.userAgent || "";
  const platform = (nav.userAgentData?.platform || nav.platform || "").toLowerCase();
  // iPadOS reports itself as a Mac with a touch screen.
  const iPadDesktopUa = platform.startsWith("mac") && nav.maxTouchPoints > 1;
  if (nav.userAgentData?.mobile || iPadDesktopUa || /android|iphone|ipad|ipod|mobile/i.test(ua)) return "mobile";
  return platform.startsWith("mac") || /mac os x/i.test(ua) ? "mac" : "desktop";
}

const KEY_CLASS =
  "rounded-md border border-[color:var(--ink-550)] bg-[color:var(--ink-700)] px-[7px] py-[2px] font-mono text-xs font-medium text-[color:var(--fg-1)]";

export default function BookmarkBar() {
  const [platform] = useState<Platform>(detectPlatform);
  const modifier = platform === "mac" ? "cmd" : "ctrl";

  return (
    <div
      role="note"
      className="flex flex-wrap items-center justify-center gap-x-2.5 gap-y-1 bg-ink-900 px-6 py-2.5 text-center font-body text-sm font-semibold text-[color:var(--fg-1)]"
    >
      <Bookmark className="h-4 w-4 shrink-0" color="var(--vm-volt)" strokeWidth={2.25} aria-hidden />
      <span>Finding this tool useful? Bookmark us</span>
      {platform !== "mobile" && (
        <span className="inline-flex items-center gap-1" aria-label={`${modifier} plus d`}>
          <kbd className={KEY_CLASS}>{modifier}</kbd>
          <span className="text-[color:var(--fg-3)]">+</span>
          <kbd className={KEY_CLASS}>d</kbd>
        </span>
      )}
      <span>for easy and fast access!</span>
    </div>
  );
}
