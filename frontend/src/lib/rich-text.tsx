// Renders the light markup used in translatable sentences: paired tags wrap
// text (<b>write</b>, <hub>install hub</hub>) and self-closing tags stand for
// whole elements (<docs/>). Each tag name maps to a render function; an
// unknown tag renders its text plainly, so a mistranslated tag never hides words.
import { Fragment, type ReactNode } from "react";

type Render = (children?: ReactNode) => ReactNode;

export function richText(text: string, tags: Record<string, Render>): ReactNode {
  const out: ReactNode[] = [];
  const re = /<(\w+)\s*\/>|<(\w+)>([\s\S]*?)<\/\2>/g;
  let last = 0;
  for (const m of text.matchAll(re)) {
    if (m.index! > last) out.push(text.slice(last, m.index));
    const name = m[1] ?? m[2];
    const render = tags[name];
    out.push(<Fragment key={m.index}>{render ? render(m[3]) : m[3] ?? ""}</Fragment>);
    last = m.index! + m[0].length;
  }
  if (last < text.length) out.push(text.slice(last));
  return out;
}
