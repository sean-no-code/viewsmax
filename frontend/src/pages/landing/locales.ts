// Localized public pages: the homepage at `/` plus one path per language, and
// translated pages under the same prefix (/mcp -> /ru/mcp). App.tsx routes each
// path, and the postbuild prerender bakes each into dist/<path>/index.html with
// its own <html lang>, head tags, hreflang links and body.
// Copy lives in copy/<code>.json; en.json is the source and its shape is the
// contract (a translation missing a string fails tsc). `npm run i18n:translate`
// fills new or changed strings with Claude. To add a language: add a row here
// with an empty copy/<code>.json ({}), then run the script.
// Never translate brand/platform names (ViewsMax, TikTok, MCP…) or plan names.
import { createContext, useContext } from "react";
import type { FlagCode } from "@/components/LocaleFlag";
import en from "./copy/en.json";
import esJson from "./copy/es.json";
import ruJson from "./copy/ru.json";
import ptBRJson from "./copy/pt-br.json";
import idJson from "./copy/id.json";

export type LandingCopy = typeof en;
const es: LandingCopy = esJson;
const ru: LandingCopy = ruJson;
const ptBR: LandingCopy = ptBRJson;
const id: LandingCopy = idJson;

export interface HomeLocale {
  code: string;
  path: string;
  htmlLang: string;
  ogLocale: string;
  label: string;
  flag: FlagCode;
  // App UI language a visit switches to, so signup continues in that language.
  // null: leave the app's own detection alone (English home, or no app translation yet).
  appLocale: string | null;
  copy: LandingCopy;
}

export const HOME_LOCALES: HomeLocale[] = [
  { code: "en", path: "/", htmlLang: "en", ogLocale: "en_US", label: "English", flag: "en", appLocale: null, copy: en },
  { code: "es", path: "/es", htmlLang: "es", ogLocale: "es_ES", label: "Español", flag: "es", appLocale: "es", copy: es },
  { code: "ru", path: "/ru", htmlLang: "ru", ogLocale: "ru_RU", label: "Русский", flag: "ru", appLocale: "ru", copy: ru },
  { code: "pt-br", path: "/pt-br", htmlLang: "pt-BR", ogLocale: "pt_BR", label: "Português (Brasil)", flag: "pt", appLocale: "pt", copy: ptBR },
  { code: "id", path: "/id", htmlLang: "id", ogLocale: "id_ID", label: "Bahasa Indonesia", flag: "id", appLocale: null, copy: id },
];

export const DEFAULT_HOME = HOME_LOCALES[0];

export const homeLocale = (code?: string) => HOME_LOCALES.find((l) => l.code === code) ?? DEFAULT_HOME;

/** A page's address in a language: localizedPath(ru, "/mcp") -> "/ru/mcp", localizedPath(ru, "/") -> "/ru". */
export const localizedPath = (locale: HomeLocale, page = "/") =>
  page === "/" ? locale.path : locale.path === "/" ? page : `${locale.path}${page}`;

// Which translated page is showing ("/" or "/mcp"), so the language picker can
// switch to the same page in another language.
export const PagePathContext = createContext("/");
export const usePagePath = () => useContext(PagePathContext);

// Set by the translated pages (ViewsMaxLanding, McpLanding). Null on public pages
// that share the nav/footer but are English-only (/ai, the agent guides, ...):
// they show no language picker.
export const HomeLocaleContext = createContext<HomeLocale | null>(null);

export const useHomeLocale = () => useContext(HomeLocaleContext);
export const useLandingCopy = () => (useContext(HomeLocaleContext) ?? DEFAULT_HOME).copy;
