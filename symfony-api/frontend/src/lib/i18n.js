import { useApp } from '@/app/AppContext';

export function useI18n() {
    const { translations = {} } = useApp();
    return (key, replacements = {}) => Object.entries(replacements).reduce(
        (text, [name, value]) => text.replace(`:${name}`, value),
        translations[key] ?? key,
    );
}

export const money = (cents, currency = 'EUR', locale = 'en-IE') =>
    (cents / 100).toLocaleString(locale, { style: 'currency', currency });

export const moneyLocale = market =>
    market?.locale === 'pl' ? 'pl-PL' : market?.locale === 'ru' ? 'ru-RU' : 'en-IE';
