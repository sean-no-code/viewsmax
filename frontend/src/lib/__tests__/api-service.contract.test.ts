// Guards the page ↔ API-service contract. Every `viewsMaxApi.<member>` the app
// references must exist on the service instance. `vite build` does not
// type-check, so a method deleted from api-service.ts while a page still calls
// it (91f72ac removed getTranscript and broke the free transcript tools) only
// surfaces as "is not a function" in the browser. This test makes it fail CI.
import { describe, it, expect } from "vitest";
import { readdirSync, readFileSync, statSync } from "node:fs";
import { join, resolve } from "node:path";
import { viewsMaxApi } from "../api-service";

// happy-dom replaces URL, so resolve from __dirname rather than import.meta.url.
const SRC = resolve(__dirname, "../..") + "/";
const SKIP_DIRS = new Set(["node_modules", "__tests__", "test"]);
const SOURCE_FILE = /\.(ts|tsx)$/;
const TEST_FILE = /\.(test|spec)\.(ts|tsx)$/;

function sourceFiles(dir: string, out: string[] = []): string[] {
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) {
      if (!SKIP_DIRS.has(entry)) sourceFiles(full, out);
    } else if (SOURCE_FILE.test(entry) && !TEST_FILE.test(entry) && !entry.endsWith(".d.ts")) {
      out.push(full);
    }
  }
  return out;
}

/** Every `viewsMaxApi.<member>` reference in src, with where it appears. */
function referencedMembers(): Map<string, string[]> {
  const refs = new Map<string, string[]>();
  for (const file of sourceFiles(SRC)) {
    const lines = readFileSync(file, "utf8").split("\n");
    lines.forEach((line, i) => {
      for (const m of line.matchAll(/\bviewsMaxApi\.([A-Za-z_$][\w$]*)/g)) {
        const name = m[1];
        if (!refs.has(name)) refs.set(name, []);
        refs.get(name)!.push(`${file.slice(SRC.length)}:${i + 1}`);
      }
    });
  }
  return refs;
}

describe("viewsMaxApi contract", () => {
  const refs = referencedMembers();

  it("finds the app's references to the service", () => {
    // Sanity check so a broken scanner can't pass by finding nothing.
    expect(refs.size).toBeGreaterThan(20);
    expect(refs.has("getTranscript")).toBe(true);
  });

  it("every referenced member exists on the service", () => {
    const missing = [...refs.entries()]
      .filter(([name]) => (viewsMaxApi as unknown as Record<string, unknown>)[name] === undefined)
      .map(([name, where]) => `viewsMaxApi.${name} — used in ${where.join(", ")}`);

    expect(missing, `Missing from ViewsMaxApiService:\n${missing.join("\n")}`).toEqual([]);
  });

  it("the free transcript tools can call getTranscript", () => {
    expect(typeof viewsMaxApi.getTranscript).toBe("function");
  });
});
