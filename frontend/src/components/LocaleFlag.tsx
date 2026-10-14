// Mini flag for a language picker, drawn as inline SVG: emoji flags render as
// two letters on Windows. Keyed by app locale code; English shows the UK flag
// and Portuguese the Brazilian one (our pt strings are Brazilian Portuguese).
import { useId, type ReactNode } from "react";

export type FlagCode = "en" | "es" | "de" | "fr" | "pt" | "ru" | "id";

function unionJack(id: string): [string, ReactNode] {
  const cross = "M0,0 L60,30 M60,0 L0,30";
  const plus = "M30,0 v30 M0,15 h60";
  return ["0 0 60 30", <>
    <clipPath id={id}><path d="M30,15 h30 v15 z v15 h-30 z h-30 v-15 z v-15 h30 z" /></clipPath>
    <rect width="60" height="30" fill="#012169" />
    <path d={cross} stroke="#fff" strokeWidth="6" />
    <path d={cross} clipPath={`url(#${id})`} stroke="#C8102E" strokeWidth="4" />
    <path d={plus} stroke="#fff" strokeWidth="10" />
    <path d={plus} stroke="#C8102E" strokeWidth="6" />
  </>];
}

// Horizontal bands top to bottom, equal height unless weights are given.
function bands(colors: string[], weights = colors.map(() => 1)): [string, ReactNode] {
  const total = weights.reduce((a, b) => a + b, 0);
  let y = 0;
  return [`0 0 3 2`, colors.map((fill, i) => {
    const h = (weights[i] / total) * 2;
    const rect = <rect key={fill + i} y={y} width="3" height={h} fill={fill} />;
    y += h;
    return rect;
  })];
}

function art(code: FlagCode, id: string): [string, ReactNode] {
  switch (code) {
    case "en": return unionJack(id);
    case "es": return bands(["#AA151B", "#F1BF00", "#AA151B"], [1, 2, 1]);
    case "de": return bands(["#000", "#DD0000", "#FFCE00"]);
    case "ru": return bands(["#fff", "#0039A6", "#D52B1E"]);
    case "id": return bands(["#CE1126", "#fff"]);
    case "fr": return ["0 0 3 2", <>
      <rect width="1" height="2" fill="#002654" />
      <rect x="1" width="1" height="2" fill="#fff" />
      <rect x="2" width="1" height="2" fill="#ED2939" />
    </>];
    case "pt": return ["0 0 20 14", <>
      <rect width="20" height="14" fill="#009C3B" />
      <path d="M1.7,7 L10,1.2 L18.3,7 L10,12.8 Z" fill="#FFDF00" />
      <circle cx="10" cy="7" r="3.5" fill="#002776" />
      <path d="M6.6,6.2 Q10,5.2 13.4,7.8" fill="none" stroke="#fff" strokeWidth=".6" />
    </>];
  }
}

export default function LocaleFlag({ code, size = 18 }: { code: FlagCode; size?: number }) {
  const id = useId().replace(/[^a-zA-Z0-9_-]/g, ""); // useId has colons, which break url(#...)
  const [viewBox, body] = art(code, `flag-${id}`);
  return (
    <svg viewBox={viewBox} width={size} height={(size * 2) / 3} preserveAspectRatio="xMidYMid slice"
      aria-hidden="true" style={{ display: "block", flexShrink: 0, borderRadius: 2, boxShadow: "0 0 0 1px rgba(0,0,0,.12)" }}>
      {body}
    </svg>
  );
}
