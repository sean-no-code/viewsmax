// Fills untranslated UI strings with Claude: `npm run i18n:translate`.
//
// For every catalog in src/i18n/catalog.ts (app UI + homepage) and every
// locale, a string is sent for translation when it is missing or empty, or
// when its English changed since it was last translated (tracked by a hash per
// string in scripts/i18n-translate.lock.json). Everything else is kept as is,
// so hand-edited translations survive. Each locale file is rewritten in the
// English file's shape: keys removed from English drop out everywhere.
//
// CI runs this before the build on every deploy (.github/workflows/main.yml)
// and commits the result back. With nothing to translate it never calls the
// API, so it needs no key; otherwise it needs ANTHROPIC_API_KEY (or an
// `ant auth login` profile locally).
//
//   npm run i18n:translate             harvest t() keys, then translate
//   tsx scripts/i18n-translate.ts --dry-run   list what would be translated
import { createHash } from "node:crypto";
import { existsSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import Anthropic from "@anthropic-ai/sdk";
import { betaZodOutputFormat } from "@anthropic-ai/sdk/helpers/beta/zod";
import { z } from "zod/v4";
import { CATALOGS, PLURAL_KEY, forLocale, leaves, mapStrings, samePlaceholders, type Catalog, type Leaf } from "../src/i18n/catalog";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const LOCK_FILE = join(root, "scripts/i18n-translate.lock.json");
const MODEL = "claude-opus-5-5";
const BATCH = 60; // strings per request
// Up to LOCALES_AT_ONCE x BATCHES_PER_LOCALE requests in flight: a first run
// over a whole new catalog is thousands of strings.
const LOCALES_AT_ONCE = 5;
const BATCHES_PER_LOCALE = 2;
const DRY_RUN = process.argv.includes("--dry-run");

// The prompt names the target language precisely (pt is Brazilian here).
const LANGUAGE_NAMES: Record<string, string> = {
  es: "Spanish (neutral, for Latin America and Spain)",
  de: "German",
  fr: "French",
  pt: "Brazilian Portuguese",
  "pt-br": "Brazilian Portuguese",
  ru: "Russian",
  id: "Indonesian",
};

const SYSTEM = `You translate user-interface text for ViewsMax, a web app for creators and marketers. ViewsMax schedules social posts to YouTube, TikTok, Instagram, X, LinkedIn, Threads and Bluesky, tracks which posts drive clicks and sales, and finds outlier videos worth copying.

Rules:
- Keep brand, product and platform names exactly as written: ViewsMax, YouTube, TikTok, Instagram, X, LinkedIn, Threads, Bluesky, Beehiiv, Discord, GitHub, Claude, ChatGPT, Codex, Cursor, OpenClaw, Hermes, MCP, API, CLI, SDK, OAuth, Reels, the plan names Starter, Creator, Pro and Agency, and TikTok's policy titles "Branded Content Policy" and "Music Usage Confirmation" (TikTok's posting rules require them as written).
- Keep every {{placeholder}} exactly as written, untranslated.
- Keep prices and figures as written ($0, $4,280).
- Consecutive items whose ids end in .0, .1, ... are parts of one sentence or list, split for layout. Translate them so they read correctly in order.
- Ids ending in _zero, _one, _two, _few, _many or _other are the CLDR plural forms of one message in the target language. The English shown is the closest English form; write the correct target-language form for that category, keeping {{count}}.
- Match the terminology and tone of the existing translations you are given, so the product reads consistently.
- Return one translation for every id you are given, and nothing else.`;

const Output = z.object({
  translations: z.array(z.object({ id: z.string(), text: z.string() })),
});

type Lock = Record<string, Record<string, string>>;
const hash = (s: string) => createHash("sha256").update(s).digest("hex").slice(0, 12);
const readJson = (file: string): unknown => (existsSync(file) ? JSON.parse(readFileSync(file, "utf8")) : {});
const writeJson = (file: string, data: unknown) => writeFileSync(file, `${JSON.stringify(data, null, 2)}\n`);

let client: Anthropic | undefined; // created on first use: no key needed when nothing is missing

async function translate(catalog: Catalog, locale: string, items: Leaf[], reference: Record<string, [string, string]>) {
  client ??= new Anthropic({ maxRetries: 4 });
  const language = LANGUAGE_NAMES[locale];
  if (!language) throw new Error(`Add "${locale}" to LANGUAGE_NAMES in scripts/i18n-translate.ts`);

  const note = (key: string) =>
    Object.entries(catalog.notes ?? {}).find(([prefix]) => key.startsWith(prefix))?.[1] ??
    (PLURAL_KEY.test(key) ? `Plural form "${key.match(PLURAL_KEY)![1]}" in ${language}.` : undefined);
  const payload = {
    target_language: language,
    what_these_are: catalog.context,
    existing_translations: reference, // id -> [English, current translation]
    translate: items.map((i) => ({ id: i.key, english: i.text, ...(note(i.key) ? { note: note(i.key) } : {}) })),
  };

  const response = await client.beta.messages.parse({
    model: MODEL,
    max_tokens: 16000,
    betas: ["server-side-fallback-2026-07-01"],
    fallbacks: "default", // a safety-classifier decline is retried on another model in the same call
    output_config: { effort: "medium", format: betaZodOutputFormat(Output) },
    system: SYSTEM,
    messages: [{ role: "user", content: JSON.stringify(payload, null, 2) }],
  });
  if (response.stop_reason === "refusal") throw new Error(`${catalog.name}/${locale}: request declined (${response.stop_details?.category ?? "no category"})`);
  if (response.stop_reason === "max_tokens") throw new Error(`${catalog.name}/${locale}: output hit max_tokens; lower BATCH`);
  if (!response.parsed_output) throw new Error(`${catalog.name}/${locale}: response did not match the output schema`);

  // Accept only complete, placeholder-safe answers for ids we asked about.
  const asked = new Map(items.map((i) => [i.key, i.text]));
  const done = new Map<string, string>();
  for (const { id, text } of response.parsed_output.translations) {
    const english = asked.get(id);
    if (english === undefined || !text.trim()) continue;
    if (!samePlaceholders(id, text, english)) {
      console.warn(`  ! ${id}: placeholders changed ("${text}"), will retry`);
      continue;
    }
    done.set(id, text);
  }
  return done;
}

// Runs `jobs` with at most `limit` in flight at once.
async function pool<T>(limit: number, jobs: (() => Promise<T>)[]): Promise<T[]> {
  const results: T[] = [];
  let next = 0;
  const worker = async () => {
    while (next < jobs.length) {
      const i = next++;
      results[i] = await jobs[i]();
    }
  };
  await Promise.all(Array.from({ length: Math.min(limit, jobs.length) }, worker));
  return results;
}

// Translates one catalog into one locale; returns how many strings needed it.
async function translateLocale(catalog: Catalog, locale: string, en: unknown, lock: Lock): Promise<number> {
  const source = forLocale(en, locale); // English in this language's plural forms
  const english = leaves(source);
  const file = join(root, catalog.file(locale));
  const current = new Map(leaves(readJson(file)).map((l) => [l.key, l.text]));
  const hashes = (lock[`${catalog.name}:${locale}`] ??= {});
  const label = `${catalog.name}/${locale}`;

  // A translation with no recorded hash predates the lock: trust it.
  const stale = (l: Leaf) => hashes[l.key] !== undefined && hashes[l.key] !== hash(l.text);
  const todo = english.filter((l) => l.text.trim() && (!current.get(l.key)?.trim() || stale(l)));
  if (todo.length) console.log(`${label}: ${todo.length} to translate`);
  if (DRY_RUN) {
    for (const l of todo) console.log(`  ${l.key}: ${l.text}`);
    return todo.length;
  }

  const translated = new Map<string, string>();
  // Rewritten in English's shape after every batch, so a failed run keeps what it paid for.
  const save = () => writeJson(file, mapStrings(source, (key, text) => translated.get(key) ?? current.get(key) ?? (text.trim() ? "" : text)));

  if (todo.length) {
    const todoKeys = new Set(todo.map((l) => l.key));
    const reference = Object.fromEntries(
      english.filter((l) => !todoKeys.has(l.key) && current.get(l.key)).map((l) => [l.key, [l.text, current.get(l.key)!]]),
    ) as Record<string, [string, string]>;
    const batches = Array.from({ length: Math.ceil(todo.length / BATCH) }, (_, i) => todo.slice(i * BATCH, (i + 1) * BATCH));
    await pool(BATCHES_PER_LOCALE, batches.map((first) => async () => {
      let batch = first;
      for (let attempt = 1; batch.length && attempt <= 2; attempt++) {
        const done = await translate(catalog, locale, batch, reference);
        done.forEach((text, key) => translated.set(key, text));
        batch = batch.filter((l) => !done.has(l.key));
      }
      save();
      if (batch.length) throw new Error(`${label}: no usable translation for ${batch.map((l) => l.key).join(", ")}`);
      console.log(`${label}: ${translated.size}/${todo.length}`);
    }));
  }

  // Record the English each string now matches; forget keys English dropped.
  save();
  for (const l of english) hashes[l.key] = hash(l.text);
  const keys = new Set(english.map((l) => l.key));
  for (const key of Object.keys(hashes)) if (!keys.has(key)) delete hashes[key];
  return todo.length;
}

async function main() {
  const lock: Lock = readJson(LOCK_FILE) as Lock;
  // One language failing doesn't stop the others: each keeps going (and saving),
  // then the run fails with every error.
  const failures: unknown[] = [];
  const jobs = CATALOGS.flatMap((catalog) => {
    const en = readJson(join(root, catalog.file("en")));
    return catalog.locales.map((locale) => async () => {
      try {
        return await translateLocale(catalog, locale, en, lock);
      } catch (err) {
        failures.push(err);
        return 0;
      }
    });
  });
  const total = (await pool(LOCALES_AT_ONCE, jobs)).reduce((a, b) => a + b, 0);

  if (!DRY_RUN) writeJson(LOCK_FILE, Object.fromEntries(Object.entries(lock).sort(([a], [b]) => a.localeCompare(b))));
  if (failures.length) {
    for (const err of failures.slice(1)) console.error(err instanceof Error ? err.message : err);
    throw failures[0];
  }
  console.log(total ? `${DRY_RUN ? "would translate" : "translated"} ${total} string(s)` : "all strings translated");
}

main().catch((err) => {
  if (err instanceof Anthropic.AuthenticationError) {
    console.error("Claude API key rejected or missing: set ANTHROPIC_API_KEY (the ANTHROPIC_API_KEY repo secret in CI).");
  } else if (err instanceof Anthropic.APIError) {
    console.error(`Claude API error ${err.status}: ${err.message}`);
  } else {
    console.error(err instanceof Error ? err.message : err);
    if (!process.env.ANTHROPIC_API_KEY) console.error("Set ANTHROPIC_API_KEY (the ANTHROPIC_API_KEY repo secret in CI).");
  }
  process.exit(1);
});
