import { usePage } from '@inertiajs/react';

export function useI18n() {
    const { translations = {} } = usePage().props;
    return (key, replacements = {}) => Object.entries(replacements).reduce((text, [name, value]) => text.replace(`:${name}`, value), translations[key] ?? key);
}

export const money = (cents, currency = 'EUR', locale = 'en-IE') =>
    (cents / 100).toLocaleString(locale, { style: 'currency', currency });
