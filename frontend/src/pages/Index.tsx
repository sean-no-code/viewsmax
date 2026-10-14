import { useEffect } from "react";
import { useNavigate } from "react-router-dom";
import i18n from "i18next";
import ViewsMaxLanding from "@/pages/landing/ViewsMaxLanding";
import { homeLocale } from "@/pages/landing/locales";
import CookieNotice from "@/components/CookieNotice";
import { useAuth } from "@/hooks/useAuth";

// `locale` is a HOME_LOCALES code ("ru", "pt-br", ...); omitted = English at "/".
const Index = ({ locale }: { locale?: string }) => {
  const { user } = useAuth();
  const navigate = useNavigate();
  const home = homeLocale(locale);

  useEffect(() => {
    if (user) {
      navigate('/dashboard', { replace: true });
    }
  }, [user, navigate]);

  // A visitor who chose /ru signs up into a Russian app. Logged-in users keep
  // the language they picked in the app.
  useEffect(() => {
    if (!user && home.appLocale && i18n.resolvedLanguage !== home.appLocale) {
      void i18n.changeLanguage(home.appLocale);
    }
  }, [user, home]);

  // Don't render landing page content if user is logged in (redirect will happen)
  if (user) {
    return null;
  }

  return (
    <>
      <ViewsMaxLanding home={home} />
      <CookieNotice copy={home.copy.cookie} />
    </>
  );
};

export default Index;
