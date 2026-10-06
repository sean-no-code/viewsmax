# design-sync notes — ViewsMax frontend (shadcn/ui library)

Target project: `ViewsMax Design System` (cc91e48d-ac65-424e-8e00-20e5e5f299ca). Re-adopted on
2026-10-05 at Sean's choice; it previously held a hand-authored brand kit (token preview cards,
`ui_kits/`, `assets/`, `colors_and_type.css`, `SKILL.md`). The old README/SKILL content was folded
into `conventions.md` so the design agent keeps the brand rules.

## Shape and the shim package
- This is a Vite app, not a library: no dist, no `.d.ts`. `.design-sync/ds-pkg/` is a committed shim
  package (`@viewsmax/ui`): `index.ts` barrel re-exporting every `src/components/ui/*.tsx`,
  `tsconfig.json` (extends `tsconfig.app.json`, declaration-only emit), `tailwind.config.ts`
  (the app theme + a safelist of layout/color utilities), `build.mjs`.
- `cfg.buildCmd` (`node .design-sync/ds-pkg/build.mjs`) regenerates the gitignored outputs the
  converter reads: `types/` (tsc), `dist/styles.css` (Tailwind), `docs/<Name>.md` (frontmatter-only
  stubs whose `category` groups the pane - the `GROUPS` map in build.mjs; add a row when a ui file lands).
  It also rewrites `@/` alias imports in the emitted .d.ts to relative paths (the converter's ts-morph
  project has no tsconfig paths).
- The converter is pointed at the shim with `--entry .design-sync/ds-pkg/index.ts` (also `cfg.entry`),
  so PKG_DIR = ds-pkg and `cssEntry`/`docsDir` are ds-pkg-relative; `srcDir`/`tsconfig` walk up to the app.
- `toaster.tsx` / `toast.tsx` (Radix toast) are NOT in the barrel: their `Toaster` collides with the
  sonner `Toaster`, and the product's toast styling (`.vm-toast-close`) targets sonner.
- `guidelinesGlob: []` is deliberate - the default glob swept the 239 doc stubs into `guidelines/`.
- No `provider` needed; context-bound parts (Tooltip, Sidebar, Carousel, Chart, Form) are previewed
  inside their parents.

## Toolchain facts
- Node 24, npm lockfile (`bun.lockb` is a stale May artifact). Converter deps live in `.ds-sync/`
  (esbuild, ts-morph, @types/react, playwright@1.61.1 - matches the cached chromium-1228 build in
  `~/Library/Caches/ms-playwright`).
- Fonts load at runtime from Google Fonts via the `@import url(...)` at the top of `src/index.css`
  (`[FONT_REMOTE]` is expected). Headless chromium fetched them fine on 2026-10-05.
- Tailwind CLI prints two `ambiguous class` warnings for `duration-[120ms]`/`ease-[...]` from app
  source; harmless.

## Known render warns (triaged)
- `[TOKENS_MISSING]` 9 vars: `--radix-*` (runtime-set by Radix) and `--ad-bg/--ad-border/--ad-accent`
  (free-tools ad slots in the app, not DS tokens). Non-blocking.
- `[RENDER_ERRORS]` on context-bound leaf floor cards (AvatarFallback, CarouselItem, ChartLegendContent,
  Form*, ToggleGroupItem, ...): the floor card's lone render throws the "must be used within" error and
  falls to the typographic card. Expected until those leaves get authored inside their parents.

## Authoring previews (folded from the 2026-10-05 batches)
- Flow that works: write `.design-sync/previews/<Name>.tsx` -> `preview-rebuild.mjs --components` -> `package-capture.mjs --components` -> Read sheet -> grade. 31 components, 90 cells, all good.
- SEQUENCING: add `cfg.overrides` (cardMode/viewport) BEFORE the full build that stamps `.stories-map.json`, or run `package-build.mjs` once after editing them. Otherwise `preview-rebuild.mjs` refuses those components with `[CONFIG_STALE]` (per-component config slices are stamped at build time). Cost one wave on the overlay batch.
- DOM/Radix pass-through props (`disabled`, `defaultValue`, `collapsible`, `src`, `rows`, `open`) work in previews even though the emitted `.d.ts` omits them; the preview build does not typecheck.
- Overlays render open with `open` (not `defaultOpen`) under `cardMode: single`; Tooltip needs `TooltipProvider` in the preview (no global provider). Sidebar parts need `SidebarProvider className="min-h-0"` inside a fixed-height wrapper or `min-h-svh` takes the whole viewport.
- Only the safelisted utility vocabulary is compiled: no negative margins, `flex-1`, `ml-auto`, or arbitrary values; use `justify-between` wrappers and `gap-*`. Vertical `Separator` needs a parent with explicit height.
- Leaf sub-parts (InputOTPSeparator, BreadcrumbEllipsis, PaginationEllipsis, SidebarMenuSkeleton, AvatarFallback...) have no honest lone render; author them as the full parent composition.
- `Label` `peer-disabled:` only dims when the control precedes the label in the DOM, which is not a plausible layout; show a disabled control under a plain label instead.
- DS source gap: `slider.tsx` uses `disabled:` pseudo-class variants on the Radix Thumb `<span>` (which only gets `data-disabled`), so a disabled slider barely dims. Upstream fix: `data-[disabled]:opacity-50` on Root.
- `AvatarImage` previews use i.pravatar.cc (loaded during capture); every one has an initials `AvatarFallback` so cards survive offline.
- Grid sheets reserve a tall cell per export; small controls look mostly empty in the sheet. Harness layout, not a defect.

## Re-sync risks
- `.design-sync/ds-pkg/index.ts` is a hand-maintained barrel: a new `src/components/ui/*.tsx` file is
  invisible to the sync until a line is added (and a `GROUPS` row in build.mjs).
- The safelist in `tailwind.config.ts` is the utility vocabulary promised in `conventions.md`; keep
  the two in step.
- `.d.ts` bodies include a verbose `ref?:` union from React 18 types (cosmetic); DOM attributes are
  filtered by design, so `disabled`/`onClick` etc. are implied, not listed.
- `RoleBadge` pulls app helpers (`plan-helpers`, `useUserRole` type) into the bundle; fine today.
