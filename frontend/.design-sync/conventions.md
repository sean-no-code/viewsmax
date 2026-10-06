# ViewsMax UI — how to build with it

This is the ViewsMax product's real component library: shadcn/ui (Radix primitives) compiled from
`frontend/src/components/ui`, themed with the ViewsMax brand tokens. Every component is on
`window.ViewsMaxUI.<Name>` (bundle: `_ds_bundle.js`); the per-component `.d.ts` is the prop contract and
`<Name>.prompt.md` carries examples. Build screens by composing these parts, never by re-drawing them.

## Setup and wrapping
- No app-wide provider is needed. Light theme is the default; add `className="dark"` on a root element
  to flip the shadcn tokens to the dark ramp.
- Context-bound parts must sit inside their parent or they throw: `Tooltip*` inside `TooltipProvider`;
  `Sidebar*` inside `SidebarProvider`; `Carousel*` inside `Carousel`; `ChartTooltipContent` /
  `ChartLegendContent` inside `ChartContainer` (pass a `config` object); `Form*` inside `Form` which is
  react-hook-form's `FormProvider` (`<Form {...form}>` with `const form = useForm()`).
- Toasts: mount `<Toaster />` once (sonner) and call `toast("Post scheduled")`.
- Icons are `lucide-react`, 2px stroke, sized with `h-4 w-4`; they are not part of the bundle.

## Styling idiom: Tailwind utilities on the ViewsMax theme
Style with className utilities and component variant props; never inline hex colors. Only the utility
families below are compiled into `_ds_bundle.css`; arbitrary values such as `w-[320px]` are not.

| Family | Use these |
|---|---|
| Brand colors | `bg-vm-red` `text-vm-red` `bg-vm-red-hot` `bg-vm-red-deep` `bg-vm-volt` `text-vm-volt-deep` |
| Surfaces (light) | `bg-paper-0` `bg-paper-1` `bg-paper-2` `bg-paper-3` `border-line-1` `border-line-2` |
| Ink (dark islands) | `bg-ink-900` `bg-ink-800` `bg-ink-700` `text-ink-on-paper-1` `text-ink-on-paper-2` `text-ink-on-paper-3` |
| Semantic (shadcn) | `bg-background` `text-foreground` `bg-primary` `text-primary-foreground` `bg-secondary` `bg-muted` `text-muted-foreground` `bg-accent` `bg-destructive` `bg-card` `border-border` |
| Data / deltas | `text-up` `text-down` `text-data-teal` `text-data-violet` `text-data-amber` `text-data-blue` |
| Type | `font-display` (Archivo, headlines and numbers) `font-body` (Hanken Grotesk) `font-mono` (JetBrains Mono) `text-xs`…`text-6xl` `font-semibold` `font-bold` `tabular-nums` `tracking-tight` |
| Type roles (CSS classes) | `vm-display` `vm-h1` `vm-h2` `vm-h3` `vm-eyebrow` `vm-lead` `vm-metric` `vm-mono` `vm-caption` |
| Layout | `flex` `grid` `grid-cols-2` `items-center` `justify-between` `gap-2` `gap-4` `gap-6` `p-4` `p-6` `space-y-2` `max-w-sm` `max-w-md` `w-full` |
| Shape and depth | `rounded-md` `rounded-lg` `rounded-full` `border` `shadow-card` `shadow-hard` `shadow-hard-red` |

Component variants carry the brand: `Button variant="default"` is Max Red, `variant="hero"` is the
gradient marketing CTA, `variant="cta"` the pill upgrade button, `variant="secondary" | "outline" |
"ghost" | "link" | "destructive"` for the rest; `Badge variant="default" | "secondary" | "destructive" |
"outline"`; `Alert variant="default" | "destructive"`.

## Brand rules (ViewsMax)
Light-first: warm off-white `bg-paper-1` canvas, hairline `border-line-1`, near-black `bg-ink-900`
only as a deliberate contrast island (hero, footer, Go Pro). One action color, Max Red; Volt Aqua is
reserved for growth and standout data; never let them compete. Headlines short and tight in
`font-display`, sentence case everywhere, numbers lead and carry a direction: `▲ 18%` in `text-up`,
`▼ 4%` in `text-down`, big numbers abbreviated (2.4M). CTAs are verb-first: "Analyze channel".

## Where the truth lives
- `styles.css` imports `_ds_bundle.css`: the `:root` tokens (`--vm-red`, `--paper-1`, `--ink-900`,
  `--font-display`, `--r-lg`, `--s-4`…), the type-role classes, and every compiled utility.
- `components/<group>/<Name>/<Name>.d.ts` for props, `<Name>.prompt.md` for examples.

## One idiomatic build
```jsx
const { Card, CardHeader, CardDescription, CardTitle, CardContent, Button, Badge } = window.ViewsMaxUI;

<Card className="max-w-sm">
  <CardHeader className="pb-2">
    <div className="flex items-center justify-between gap-2">
      <CardDescription>Views, last 28 days</CardDescription>
      <Badge variant="secondary">YouTube</Badge>
    </div>
    <CardTitle className="font-display text-3xl tabular-nums">2.4M</CardTitle>
  </CardHeader>
  <CardContent className="space-y-3">
    <p className="text-sm font-medium text-up">▲ 18% vs previous period</p>
    <Button size="sm">See the trend</Button>
  </CardContent>
</Card>
```
