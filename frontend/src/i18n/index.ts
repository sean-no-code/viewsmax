// i18n bootstrap — imported once from main.tsx BEFORE the app renders.
//
// Conventions:
//  - Keys are namespaced by feature: `settings.notifications.title`,
//    `post.composer.addComment`, `boosts.autoRepost.title`, `nav.connections`.
//  - Always give a default value inline: t("nav.settings", "Settings") — the
//    app stays readable in code and `i18next-parser` harvests the defaults
//    into locales/en/translation.json (run `npx i18next` after adding keys).
//  - NEVER translate: platform/brand names (X, TikTok, Bluesky…), the `---`
//    thread delimiter, enum/slug values sent to the API.
import i18n from "i18next";
import { initReactI18next } from "react-i18next";
import LanguageDetector from "i18next-browser-languagedetector";

import en from "@/locales/en/translation.json";
import es from "@/locales/es/translation.json";
import de from "@/locales/de/translation.json";
import fr from "@/locales/fr/translation.json";
import pt from "@/locales/pt/translation.json";

export const SUPPORTED_LOCALES = [
  { code: "en", label: "English" },
  { code: "es", label: "Español" },
  { code: "de", label: "Deutsch" },
  { code: "fr", label: "Français" },
  { code: "pt", label: "Português" },
] as const;

void i18n
  .use(LanguageDetector)
  .use(initReactI18next)
  .init({
    resources: {
      en: { translation: en },
      es: { translation: es },
      de: { translation: de },
      fr: { translation: fr },
      pt: { translation: pt },
    },
    fallbackLng: "en",
    supportedLngs: SUPPORTED_LOCALES.map((l) => l.code),
    detection: {
      // localStorage first (the Settings switcher writes it), then browser.
      order: ["localStorage", "navigator"],
      caches: ["localStorage"],
      lookupLocalStorage: "vmx_locale",
    },
    interpolation: { escapeValue: false }, // React already escapes
    returnEmptyString: false,
  });

export default i18n;
