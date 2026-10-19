/** Countries for the outliers filter (ISO 3166-1 alpha-2, matching channels.country
 *  on the backend — YouTube's snippet.country). Popular creator markets are pinned
 *  to the top of the dropdown; the rest are alphabetical. */
import i18n from 'i18next';

export interface CountryOption {
    code: string;
    name: string;
}

const POPULAR: CountryOption[] = [
    { code: 'US', name: 'United States' },
    { code: 'GB', name: 'United Kingdom' },
    { code: 'AU', name: 'Australia' },
    { code: 'CA', name: 'Canada' },
    { code: 'NZ', name: 'New Zealand' },
    { code: 'IE', name: 'Ireland' },
    { code: 'IN', name: 'India' },
    { code: 'DE', name: 'Germany' },
    { code: 'FR', name: 'France' },
    { code: 'BR', name: 'Brazil' },
];

const REST: CountryOption[] = [
    { code: 'AR', name: 'Argentina' },
    { code: 'AT', name: 'Austria' },
    { code: 'BD', name: 'Bangladesh' },
    { code: 'BE', name: 'Belgium' },
    { code: 'BG', name: 'Bulgaria' },
    { code: 'CH', name: 'Switzerland' },
    { code: 'CL', name: 'Chile' },
    { code: 'CN', name: 'China' },
    { code: 'CO', name: 'Colombia' },
    { code: 'CZ', name: 'Czechia' },
    { code: 'DK', name: 'Denmark' },
    { code: 'EG', name: 'Egypt' },
    { code: 'ES', name: 'Spain' },
    { code: 'FI', name: 'Finland' },
    { code: 'GR', name: 'Greece' },
    { code: 'HK', name: 'Hong Kong' },
    { code: 'HR', name: 'Croatia' },
    { code: 'HU', name: 'Hungary' },
    { code: 'ID', name: 'Indonesia' },
    { code: 'IL', name: 'Israel' },
    { code: 'IT', name: 'Italy' },
    { code: 'JP', name: 'Japan' },
    { code: 'KE', name: 'Kenya' },
    { code: 'KR', name: 'South Korea' },
    { code: 'MX', name: 'Mexico' },
    { code: 'MY', name: 'Malaysia' },
    { code: 'NG', name: 'Nigeria' },
    { code: 'NL', name: 'Netherlands' },
    { code: 'NO', name: 'Norway' },
    { code: 'PE', name: 'Peru' },
    { code: 'PH', name: 'Philippines' },
    { code: 'PK', name: 'Pakistan' },
    { code: 'PL', name: 'Poland' },
    { code: 'PT', name: 'Portugal' },
    { code: 'RO', name: 'Romania' },
    { code: 'RS', name: 'Serbia' },
    { code: 'SA', name: 'Saudi Arabia' },
    { code: 'SE', name: 'Sweden' },
    { code: 'SG', name: 'Singapore' },
    { code: 'TH', name: 'Thailand' },
    { code: 'TR', name: 'Türkiye' },
    { code: 'TW', name: 'Taiwan' },
    { code: 'UA', name: 'Ukraine' },
    { code: 'AE', name: 'United Arab Emirates' },
    { code: 'VN', name: 'Vietnam' },
    { code: 'ZA', name: 'South Africa' },
];

export const COUNTRIES: CountryOption[] = [
    ...POPULAR,
    ...REST.sort((a, b) => a.name.localeCompare(b.name)),
];

const NAME_BY_CODE = new Map(COUNTRIES.map((c) => [c.code, c.name]));

const uiLanguage = () => i18n.resolvedLanguage || 'en';

// Country names in the UI language come from the browser (Intl.DisplayNames);
// English keeps the list's own names. `english` stays for search.
function localName(code: string, lang: string): string {
    if (lang.startsWith('en')) return NAME_BY_CODE.get(code) ?? code;
    try {
        return new Intl.DisplayNames([lang], { type: 'region' }).of(code) ?? NAME_BY_CODE.get(code) ?? code;
    } catch {
        return NAME_BY_CODE.get(code) ?? code;
    }
}

export function countryName(code: string): string {
    return localName(code, uiLanguage());
}

export interface LocalizedCountry extends CountryOption {
    english: string;
}

/** COUNTRIES in the UI language: popular markets first, the rest by local name. */
export function localizedCountries(lang = uiLanguage()): LocalizedCountry[] {
    const local = (c: CountryOption) => ({ ...c, name: localName(c.code, lang), english: c.name });
    return [
        ...POPULAR.map(local),
        ...REST.map(local).sort((a, b) => a.name.localeCompare(b.name, lang)),
    ];
}

/** 'US' → 🇺🇸 via regional-indicator symbols. */
export function countryFlag(code: string): string {
    if (!/^[A-Za-z]{2}$/.test(code)) return '🌐';
    return String.fromCodePoint(...[...code.toUpperCase()].map((c) => 0x1f1a5 + c.charCodeAt(0)));
}
