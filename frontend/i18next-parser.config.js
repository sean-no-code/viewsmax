// String-extraction config: `npx i18next` (run by `npm run i18n:translate`)
// harvests every t("key", "Default") call into src/locales/en/translation.json
// and stubs missing keys in the other locales (keeping existing translations).
export default {
  locales: ["en", "es", "de", "fr", "pt", "ru"],
  output: "src/locales/$LOCALE/translation.json",
  input: ["src/**/*.{ts,tsx}"],
  defaultNamespace: "translation",
  keySeparator: ".",
  namespaceSeparator: false,
  // Keep en/translation.json in sync with the inline defaults.
  defaultValue: (locale, ns, key, value) => (locale === "en" ? value || key : ""),
  sort: true,
  createOldCatalogs: false,
  // Keys looked up indirectly (the sidebar's `i18nKey: "nav.post"`) are invisible
  // to the parser; without this it deletes them, translations included. Remove
  // dead keys by hand.
  keepRemoved: true,
};
