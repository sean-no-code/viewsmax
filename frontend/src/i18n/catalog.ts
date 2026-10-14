// The translated string catalogs and helpers to walk them. Shared by
// scripts/i18n-translate.ts (fills missing strings with Claude) and
// src/i18n/__tests__/catalogs.test.ts (fails while any string is missing).
// English is the source in both; its shape is what every locale must match.
import { SUPPORTED_LOCALES } from "./locales";
import { HOME_LOCALES } from "@/pages/landing/locales";

export interface Catalog {
  name: string;
  // Path from the frontend root to one locale's file.
  file: (locale: string) => string;
  locales: string[];
  // What these strings are, for the translator.
  context: string;
  // Extra guidance for keys starting with the given prefix.
  notes?: Record<string, string>;
}

export const CATALOGS: Catalog[] = [
  {
    name: "app",
    file: (l) => `src/locales/${l}/translation.json`,
    locales: SUPPORTED_LOCALES.map((l) => l.code).filter((c) => c !== "en"),
    context: "In-app UI strings: sidebar labels, settings, buttons and toasts. Keep labels as short as the English.",
  },
  {
    name: "homepage",
    file: (l) => `src/pages/landing/copy/${l}.json`,
    locales: HOME_LOCALES.map((l) => l.code).filter((c) => c !== "en"),
    context: "Marketing homepage copy: headlines, feature descriptions, pricing and navigation. Write natural, persuasive copy a native marketer would write, not a literal translation. Nav and button labels must stay about as short as the English.",
    notes: {
      "hero.headline": "Two lines of one headline; the second line is shown in red.",
      "monetize.title": "One sentence split in two; the second part (the final word or two) is shown in red.",
      "cta.title": "Two lines of one headline.",
      "mock.pill": "{{post}} becomes the post name and {{amount}} a dollar amount, both bold.",
      "demos.demoAlt": "Appended after a tab label, as in \"Outliers demo\".",
      "pricing.tiers": "The keys are plan names; the plan names themselves are never translated.",
    },
  },
];

export interface Leaf {
  key: string;
  text: string;
}

// Every string in a catalog, keyed by its dotted path ("nav.post", "hero.headline.0").
export function leaves(value: unknown, path: string[] = []): Leaf[] {
  if (typeof value === "string") return [{ key: path.join("."), text: value }];
  if (Array.isArray(value)) return value.flatMap((v, i) => leaves(v, [...path, String(i)]));
  if (value && typeof value === "object") {
    return Object.entries(value).flatMap(([k, v]) => leaves(v, [...path, k]));
  }
  return [];
}

// A copy of `source` with each string replaced by `text(key, sourceText)`.
// The result has exactly the source's shape and key order, so keys removed
// from English drop out of every locale.
export function mapStrings(source: unknown, text: (key: string, sourceText: string) => string, path: string[] = []): unknown {
  if (typeof source === "string") return text(path.join("."), source);
  if (Array.isArray(source)) return source.map((v, i) => mapStrings(v, text, [...path, String(i)]));
  if (source && typeof source === "object") {
    return Object.fromEntries(Object.entries(source).map(([k, v]) => [k, mapStrings(v, text, [...path, k])]));
  }
  return source;
}

// i18next plurals are sibling keys per CLDR category ("count_one", "count_other"),
// and each language has its own set (Russian one/few/many/other, Indonesian
// only other). Returns English reshaped to `locale`'s categories, so that
// language's file is expected to hold exactly those keys. A new form starts
// from the English "one" text for `one`, otherwise from "other".
const PLURAL_ORDER = ["zero", "one", "two", "few", "many", "other"];
export const PLURAL_KEY = /_(zero|one|two|few|many|other)$/;

export function forLocale(source: unknown, locale: string): unknown {
  if (Array.isArray(source)) return source.map((v) => forLocale(v, locale));
  if (!source || typeof source !== "object") return source;
  const categories = new Intl.PluralRules(locale).resolvedOptions().pluralCategories;
  const forms = PLURAL_ORDER.filter((c) => categories.includes(c as Intl.LDMLPluralRule));
  const entries = Object.entries(source as Record<string, unknown>);
  const out: Record<string, unknown> = {};
  for (const [key, value] of entries) {
    const base = key.replace(PLURAL_KEY, "");
    if (base === key || typeof (source as Record<string, unknown>)[`${base}_other`] !== "string") {
      out[key] = forLocale(value, locale);
      continue;
    }
    if (`${base}_other` in out) continue; // group already expanded at its first key
    const en = source as Record<string, string>;
    for (const form of forms) out[`${base}_${form}`] = form === "one" && en[`${base}_one`] ? en[`${base}_one`] : en[`${base}_other`];
  }
  return out;
}

// i18next / template placeholders, sorted: "Hi {{name}}" -> ["name"].
export const placeholders = (s: string) => [...s.matchAll(/\{\{\s*(\w+)\s*\}\}/g)].map((m) => m[1]).sort();
