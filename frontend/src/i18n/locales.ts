// App UI languages. Kept free of side effects (no i18next init) so build
// scripts can import it: scripts/i18n-translate.ts fills each one's catalog.
export const SUPPORTED_LOCALES = [
  { code: "en", label: "English" },
  { code: "es", label: "Español" },
  { code: "de", label: "Deutsch" },
  { code: "fr", label: "Français" },
  { code: "pt", label: "Português" },
  { code: "ru", label: "Русский" },
] as const;
