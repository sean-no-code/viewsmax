// Shown when a page crashes while rendering, instead of a blank screen. The
// crash itself is reported by Sentry's ErrorBoundary (see App.tsx).
import { useTranslation } from "react-i18next";
import { Button } from "@/components/ui/button";

export default function AppErrorFallback() {
  const { t } = useTranslation();
  return (
    <div role="alert" className="flex min-h-screen flex-col items-center justify-center gap-4 bg-background p-6 text-center">
      <h1 className="text-2xl font-semibold text-foreground">{t("layout.error.title", "Something went wrong")}</h1>
      <p className="max-w-md text-muted-foreground">
        {t("layout.error.body", "This page hit an unexpected error. We've been notified. Reloading usually fixes it.")}
      </p>
      <div className="flex gap-3">
        <Button onClick={() => window.location.reload()}>{t("layout.error.reload", "Reload the page")}</Button>
        <Button variant="outline" asChild>
          <a href="/">{t("layout.error.home", "Go to the homepage")}</a>
        </Button>
      </div>
    </div>
  );
}
