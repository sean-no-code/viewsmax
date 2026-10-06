import type { Config } from "tailwindcss";
import base from "../../tailwind.config";

// design-sync stylesheet config: the app's Tailwind theme, compiled against the ui
// components + authored previews, plus a safelist of the layout/colour vocabulary
// the Claude Design agent is told it may use (see ../conventions.md). The app's own
// build is untouched - this only feeds .design-sync/ds-pkg/dist/styles.css.
const tokenColors = [
  "vm-red", "vm-red-hot", "vm-red-deep", "vm-volt", "vm-volt-deep",
  "ink-900", "ink-800", "ink-700", "ink-on-paper-1", "ink-on-paper-2", "ink-on-paper-3",
  "paper-0", "paper-1", "paper-2", "paper-3", "line-1", "line-2",
  "data-red", "data-teal", "data-violet", "data-amber", "data-blue", "data-pink", "data-indigo", "up", "down",
  "background", "foreground", "primary", "primary-foreground", "secondary", "secondary-foreground",
  "muted", "muted-foreground", "accent", "accent-foreground", "destructive", "destructive-foreground",
  "card", "card-foreground", "popover", "popover-foreground", "border", "input", "ring",
  "white", "black", "transparent",
].join("|");
const scale = "0|0\\.5|1|1\\.5|2|2\\.5|3|3\\.5|4|5|6|7|8|9|10|11|12|14|16|20|24|32";

export default {
  ...base,
  content: {
    relative: true,
    files: ["../../src/**/*.{ts,tsx}", "../previews/**/*.tsx"],
  },
  safelist: [
    { pattern: new RegExp(`^(p|px|py|pt|pr|pb|pl|m|mx|my|mt|mr|mb|ml|gap|gap-x|gap-y|space-x|space-y)-(${scale})$`) },
    { pattern: /^(w|h|min-w|max-w|min-h|max-h)-(full|screen|fit|min|max|auto|px|0|1|2|3|4|5|6|8|10|12|16|20|24|32|40|48|56|64|72|80|96|xs|sm|md|lg|xl|2xl|3xl|4xl|5xl|6xl|7xl)$/ },
    { pattern: /^(flex|grid|inline-flex|inline-grid|block|inline-block|hidden|items-(start|center|end|stretch|baseline)|justify-(start|center|end|between|around|evenly)|flex-(row|col|wrap|nowrap|1|auto|none|row-reverse|col-reverse)|grid-cols-(1|2|3|4|5|6|12)|col-span-(1|2|3|4|6|12)|self-(start|center|end|stretch)|shrink-0|grow)$/ },
    { pattern: /^(text-(xs|sm|base|lg|xl|2xl|3xl|4xl|5xl|6xl|left|center|right)|font-(sans|body|display|mono|normal|medium|semibold|bold|extrabold|black)|leading-(none|tight|snug|normal|relaxed)|tracking-(tighter|tight|normal|wide|widest)|uppercase|truncate|tabular-nums|whitespace-nowrap)$/ },
    { pattern: new RegExp(`^(bg|text|border|ring)-(${tokenColors})$`), variants: ["hover"] },
    { pattern: /^(rounded(-(sm|md|lg|xl|2xl|3xl|full|none))?|border(-(0|2|t|b|l|r))?|shadow(-(sm|md|lg|xl|card|primary|hero|hard|hard-red|none))?|overflow-(hidden|auto|x-auto|y-auto)|relative|absolute|fixed|sticky|inset-0|top-0|bottom-0|left-0|right-0|z-(0|10|20|30|40|50)|cursor-pointer|select-none|opacity-(0|50|60|70|80|90|100)|transition(-(all|colors|opacity|transform))?|divide-y|container|mx-auto|sr-only|pointer-events-none|animate-(fade-in|float|spin|pulse))$/ },
  ],
} satisfies Config;
