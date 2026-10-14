// Fails while any locale is missing a string, so an untranslated label can't
// ship. Fix a failure with `npm run i18n:translate` (CI runs it before tests).
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, it, expect } from "vitest";
import { CATALOGS, leaves, placeholders } from "@/i18n/catalog";
import { PLAN_INCLUDES } from "@/lib/plan-helpers";

const read = (file: string): unknown => JSON.parse(readFileSync(join(process.cwd(), file), "utf8"));

describe.each(CATALOGS)("$name catalog", (catalog) => {
  const english = leaves(read(catalog.file("en")));

  it.each(catalog.locales)("%s has every English string, with the same placeholders", (locale) => {
    const translated = new Map(leaves(read(catalog.file(locale))).map((l) => [l.key, l.text]));
    const missing = english.filter((l) => l.text.trim() && !translated.get(l.key)?.trim()).map((l) => l.key);
    const broken = english
      .filter((l) => translated.get(l.key) && placeholders(translated.get(l.key)!).join() !== placeholders(l.text).join())
      .map((l) => l.key);
    expect({ missing, broken }).toEqual({ missing: [], broken: [] });
  });
});

describe("homepage pricing", () => {
  it("lists the same plan inclusions as checkout", () => {
    const en = read("src/pages/landing/copy/en.json") as { pricing: { includes: string[] } };
    expect(en.pricing.includes).toEqual([...PLAN_INCLUDES]);
  });
});
