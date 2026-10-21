// i18n bootstrap — imported once from main.tsx BEFORE the app renders.
//
// Conventions:
//  - Keys are namespaced by feature: `settings.notifications.title`,
//    `post.composer.addComment`, `boosts.autoRepost.title`, `nav.connections`.
//  - Always give a default value inline: t("nav.settings", "Settings") — the
//    app stays readable in code. `npm run i18n:translate` harvests the defaults
//    into locales/en/translation.json and has Claude fill the other locales
//    (CI runs it on every deploy; see scripts/i18n-translate.ts).
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
import ru from "@/locales/ru/translation.json";

import { SUPPORTED_LOCALES } from "./locales";

export { SUPPORTED_LOCALES };

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
      ru: { translation: ru },
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

// Keep <html lang> on the UI language (screen readers, hyphenation). The
// homepages set their own on mount, after this.
const setHtmlLang = (lng?: string) => {
  if (lng && typeof document !== "undefined") document.documentElement.lang = lng;
};
setHtmlLang(i18n.resolvedLanguage); // init above already ran
i18n.on("languageChanged", setHtmlLang);

export default i18n;
