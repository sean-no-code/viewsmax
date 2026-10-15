// Flag language dropdown, shared by the dashboard top bar and the sign-in page.
// Picking a language switches the UI at once (the i18next detector saves it to
// localStorage); `onChange` lets a caller also save it, e.g. to the account.
import { useTranslation } from "react-i18next";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import LocaleFlag from "@/components/LocaleFlag";
import { SUPPORTED_LOCALES } from "@/i18n/locales";

export default function LanguageMenu({ onChange }: { onChange?: (code: string) => void }) {
  const { t, i18n } = useTranslation();
  const current = SUPPORTED_LOCALES.find((l) => l.code === i18n.resolvedLanguage) ?? SUPPORTED_LOCALES[0];

  const choose = async (code: string) => {
    await i18n.changeLanguage(code);
    onChange?.(code);
  };

  return (
    <DropdownMenu modal={false}>
      <DropdownMenuTrigger asChild>
        <Button variant="ghost" size="sm" className="gap-1.5" title={t("layout.topBar.language", "Language")}>
          <LocaleFlag code={current.code} />
          <span className="uppercase text-xs font-semibold">{current.code}</span>
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        {SUPPORTED_LOCALES.map((locale) => (
          <DropdownMenuItem
            key={locale.code}
            onClick={() => void choose(locale.code)}
            className={`gap-2.5${locale.code === current.code ? " font-semibold" : ""}`}
          >
            <LocaleFlag code={locale.code} />
            {locale.label}
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
