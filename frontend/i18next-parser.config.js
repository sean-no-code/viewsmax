// String-extraction config: `npx i18next` harvests every t("key", "Default")
// call into src/locales/en/translation.json and stubs missing keys in the
// other locales (keeping existing translations). Run it after adding keys.
export default {
  locales: ["en", "es", "de", "fr", "pt"],
  output: "src/locales/$LOCALE/translation.json",
  input: ["src/**/*.{ts,tsx}"],
  defaultNamespace: "translation",
  keySeparator: ".",
  namespaceSeparator: false,
  // Keep en/translation.json in sync with the inline defaults.
  defaultValue: (locale, ns, key, value) => (locale === "en" ? value || key : ""),
  sort: true,
  createOldCatalogs: false,
};
