// A logged-out visitor on a translated public page (/ru, /ru/mcp) signs up into
// the app in that language. Logged-in users keep the language they chose.
import { useEffect } from "react";
import i18n from "i18next";
import { useAuth } from "@/hooks/useAuth";
import type { HomeLocale } from "./locales";

export function useAppLocaleFor(locale: HomeLocale) {
  const { user } = useAuth();
  useEffect(() => {
    if (!user && locale.appLocale && i18n.resolvedLanguage !== locale.appLocale) {
      void i18n.changeLanguage(locale.appLocale);
    }
  }, [user, locale]);
}
