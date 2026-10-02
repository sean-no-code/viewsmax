// Build-time body prerender (no headless browser).
//
// prerender-seo.ts and prerender-agents.ts write dist/<path>/index.html with
// the right head tags but an empty <div id="root">, so a fetch that doesn't run
// JavaScript — AI crawlers, the plugin directory reviews — gets a page with no
// text. This runs last in `postbuild` and fills that div with each page's real
// HTML (src/lib/static-page-body.tsx). React renders over it on load.
import { readFileSync, writeFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { createServer } from "vite";

const rootDir = join(dirname(fileURLToPath(import.meta.url)), "..");
const distDir = join(rootDir, "dist");
const ROOT_DIV = '<div id="root"></div>';
// dist/index.html is also what the host serves for every app route with no
// file of its own (/dashboard, ...). Empty the homepage markup there before
// first paint so it doesn't flash ahead of the app.
const HOME_ONLY = '<script>if(location.pathname!=="/")document.getElementById("root").innerHTML=""</script>';

// The pages import assets, `@/` aliases and import.meta.env, so they load
// through Vite rather than plain tsx. api-service reads localStorage on
// import; there is no session at build time.
globalThis.localStorage ??= { getItem: () => null, setItem() {}, removeItem() {} } as unknown as Storage;
const vite = await createServer({
  root: rootDir,
  mode: "production",
  server: { middlewareMode: true },
  appType: "custom",
  logLevel: "error",
});
const { STATIC_BODY_PATHS, renderStaticPageBody } = (await vite.ssrLoadModule(
  "/src/lib/static-page-body.tsx",
)) as typeof import("../src/lib/static-page-body");

// The homepage goes last: every other page's file must be read before
// dist/index.html changes.
for (const path of [...STATIC_BODY_PATHS.filter((p) => p !== "/"), "/"]) {
  const file = join(distDir, path.replace(/^\//, ""), "index.html");
  const html = readFileSync(file, "utf8");
  // Fail the build rather than ship a page with no text.
  if (!html.includes(ROOT_DIV)) throw new Error(`${path}: no ${ROOT_DIV} in ${file} to fill`);

  const body = renderStaticPageBody(path);
  writeFileSync(file, html.replace(ROOT_DIV, () => `<div id="root">${body}</div>${path === "/" ? HOME_ONLY : ""}`));
  console.log(`prerendered body ${path}`);
}

await vite.close();
