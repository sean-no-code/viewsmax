#!/usr/bin/env node
// design-sync shim build for the shadcn/ui library (context: ../NOTES.md).
// Produces, under this directory, three gitignored outputs the converter reads:
//   types/           tsc declarations for ./index.ts (the real <Name>Props contracts)
//   dist/styles.css  Tailwind compiled from src/index.css with ./tailwind.config.ts
//   docs/<Name>.md   frontmatter-only stubs whose `category` groups the Design System pane
// Re-run via cfg.buildCmd ("node .design-sync/ds-pkg/build.mjs") before the converter.
import { execFileSync } from 'node:child_process';
import { mkdirSync, readdirSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const frontend = resolve(here, '../..');
const npx = process.platform === 'win32' ? 'npx.cmd' : 'npx';
const run = (args) => execFileSync(npx, args, { cwd: frontend, stdio: 'inherit' });

// Source file -> Design System pane group. Every ui/*.tsx the barrel exports needs a row.
const GROUPS = {
  button: 'Actions', toggle: 'Actions', 'toggle-group': 'Actions',
  input: 'Forms', textarea: 'Forms', label: 'Forms', checkbox: 'Forms', 'radio-group': 'Forms',
  switch: 'Forms', slider: 'Forms', select: 'Forms', form: 'Forms', 'input-otp': 'Forms', calendar: 'Forms',
  card: 'Layout', separator: 'Layout', 'aspect-ratio': 'Layout', 'scroll-area': 'Layout',
  resizable: 'Layout', collapsible: 'Layout', accordion: 'Layout', skeleton: 'Layout',
  dialog: 'Overlays', 'alert-dialog': 'Overlays', sheet: 'Overlays', drawer: 'Overlays', popover: 'Overlays',
  'hover-card': 'Overlays', tooltip: 'Overlays', 'dropdown-menu': 'Menus', 'context-menu': 'Menus',
  menubar: 'Menus', command: 'Menus',
  tabs: 'Navigation', breadcrumb: 'Navigation', pagination: 'Navigation', 'navigation-menu': 'Navigation', sidebar: 'Navigation',
  alert: 'Feedback', badge: 'Feedback', progress: 'Feedback', sonner: 'Feedback', 'role-badge': 'Feedback',
  table: 'Data display', chart: 'Data display', avatar: 'Data display', carousel: 'Data display',
};

// 1. Declarations.
rmSync(join(here, 'types'), { recursive: true, force: true });
run(['tsc', '-p', join(here, 'tsconfig.json')]);

// 2. `@/x` alias imports -> relative. The converter's type parser has no tsconfig paths.
const typesSrc = join(here, 'types', 'src');
const walk = (d) => readdirSync(d, { withFileTypes: true }).flatMap((e) =>
  e.isDirectory() ? walk(join(d, e.name)) : e.name.endsWith('.d.ts') ? [join(d, e.name)] : []);
let rewritten = 0;
for (const f of walk(typesSrc)) {
  const txt = readFileSync(f, 'utf8');
  if (!txt.includes('"@/')) continue;
  let rel = relative(dirname(f), typesSrc).split('\\').join('/');
  if (!rel.startsWith('.')) rel = rel ? `./${rel}` : '.';
  writeFileSync(f, txt.replace(/"@\//g, `"${rel}/`));
  rewritten++;
}

// 3. Stylesheet.
mkdirSync(join(here, 'dist'), { recursive: true });
run(['tailwindcss', '-c', join(here, 'tailwind.config.ts'), '-i', join(frontend, 'src', 'index.css'), '-o', join(here, 'dist', 'styles.css')]);

// 4. Group stubs - one per PascalCase value export, named so the converter's slug match finds it.
const docs = join(here, 'docs');
rmSync(docs, { recursive: true, force: true });
mkdirSync(docs);
const uiTypes = join(typesSrc, 'components', 'ui');
let stubs = 0;
for (const f of readdirSync(uiTypes).filter((n) => n.endsWith('.d.ts'))) {
  const file = f.replace(/\.d\.ts$/, '');
  const group = GROUPS[file];
  if (!group) console.error(`! build.mjs: no GROUPS row for ui/${file} - its components land in "Components"`);
  const txt = readFileSync(join(uiTypes, f), 'utf8');
  const names = new Set();
  for (const m of txt.matchAll(/export\s*\{([^}]*)\}/g))
    for (const part of m[1].split(',')) {
      const nm = part.trim().split(/\s+as\s+/).pop();
      if (/^[A-Z][A-Za-z0-9]*$/.test(nm)) names.add(nm);
    }
  for (const m of txt.matchAll(/export\s+declare\s+(?:const|function)\s+([A-Z][A-Za-z0-9]*)/g)) names.add(m[1]);
  for (const nm of names) { writeFileSync(join(docs, `${nm}.md`), `---\ncategory: ${group ?? 'Components'}\n---\n`); stubs++; }
}
const kb = (statSync(join(here, 'dist', 'styles.css')).size / 1024).toFixed(0);
console.error(`ds-pkg build: ${walk(join(here, 'types')).length} .d.ts (${rewritten} alias-rewritten), dist/styles.css ${kb} KB, ${stubs} group stubs`);
