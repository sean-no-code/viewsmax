// Fails while any locale is missing a string, so an untranslated label can't
// ship. Fix a failure with `npm run i18n:translate` (CI runs it before tests).
import { readFileSync } from "node:fs";
import { join } from "node:path";
import { describe, it, expect } from "vitest";
import { CATALOGS, forLocale, leaves, samePlaceholders } from "@/i18n/catalog";
import { PLAN_INCLUDES, PLAN_TIER_INCLUDES, planIncludesFor } from "@/lib/plan-helpers";

const read = (file: string): unknown => JSON.parse(readFileSync(join(process.cwd(), file), "utf8"));

describe.each(CATALOGS)("$name catalog", (catalog) => {
  const en = read(catalog.file("en"));

  it.each(catalog.locales)("%s has every English string, with the same placeholders", (locale) => {
    const english = leaves(forLocale(en, locale)); // plural keys follow the language's own forms
    const translated = new Map(leaves(read(catalog.file(locale))).map((l) => [l.key, l.text]));
    const missing = english.filter((l) => l.text.trim() && !translated.get(l.key)?.trim()).map((l) => l.key);
    const broken = english
      .filter((l) => translated.get(l.key) && !samePlaceholders(l.key, translated.get(l.key)!, l.text))
      .map((l) => l.key);
    expect({ missing, broken }).toEqual({ missing: [], broken: [] });
  });
});

describe("homepage pricing", () => {
  type Pricing = { pricing: { includes: string[]; tiers: Record<string, { feats: string[] }> } };
  const en = read("src/pages/landing/copy/en.json") as Pricing;

  it("lists the same shared plan inclusions as checkout", () => {
    expect(en.pricing.includes).toEqual([...PLAN_INCLUDES]);
  });

  it("lists no offer count on any tier", () => {
    // Offers are monetization — the Agency tier's feature, sold by its own
    // inclusion line, not as a per-tier count on every card.
    for (const { feats } of Object.values(en.pricing.tiers)) {
      for (const line of feats) expect(line).not.toMatch(/offer/i);
    }
  });

  it("promises each tier-specific line on the same tiers as checkout", () => {
    // The homepage cards render [...tier.feats, ...includes], so a line only
    // some tiers get has to live in that tier's feats.
    for (const [tier, { feats }] of Object.entries(en.pricing.tiers)) {
      const expected = planIncludesFor(tier).filter((l) => !PLAN_INCLUDES.includes(l));
      const actual = PLAN_TIER_INCLUDES.map((e) => e.line).filter((l) => feats.includes(l));
      expect(actual).toEqual(expected);
    }
  });
});

describe("samePlaceholders", () => {
  it("lets a plural form add or drop {{count}}, as Russian 'one' (1, 21, 31…) must", () => {
    const en = "A file didn't upload. Retry or remove before posting.";
    expect(samePlaceholders("post.composer.filesFailed_one", "{{count}} файл не загрузился.", en)).toBe(true);
    expect(samePlaceholders("post.composer.filesFailed_other", "Files didn't upload.", "{{count}} files didn't upload.")).toBe(true);
  });

  it("still rejects any other placeholder change", () => {
    expect(samePlaceholders("post.list.deleted", "Удалено", "Deleted {{title}}")).toBe(false);
    expect(samePlaceholders("x.items_one", "{{count}} элемент", "{{count}} item in {{place}}")).toBe(false);
    expect(samePlaceholders("x.items", "{{count}} элементов", "Items")).toBe(false);
  });
});
