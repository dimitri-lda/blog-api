import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { useI18n } from '@/lib/i18n';

function Preferences() {
    const { market, countries } = usePage().props;
    const t = useI18n();
    const [country, setCountry] = useState(market?.country ?? 'DE');
    const [locale, setLocale] = useState(market?.locale ?? 'en');
    const [expanded, setExpanded] = useState(false);
    const preferencesRef = useRef(null);
    const languageCodes = { en: 'EN', ru: 'RU', pl: 'PL' };
    const updateCountry = nextCountry => {
        setCountry(nextCountry);
        router.post(route('market.preferences.update'), { country: nextCountry, locale }, { preserveScroll: true, preserveState: true });
    };
    const updateLanguage = nextLocale => {
        setLocale(nextLocale);
        setExpanded(false);
        router.post(route('market.preferences.update'), { country, locale: nextLocale }, { preserveScroll: true });
    };
    const countryName = countries[country]?.[locale] ?? countries[country]?.en ?? country;

    useEffect(() => {
        const closeOnOutsideClick = event => {
            if (preferencesRef.current && !preferencesRef.current.contains(event.target)) setExpanded(false);
        };
        const closeOnEscape = event => { if (event.key === 'Escape') setExpanded(false); };
        document.addEventListener('pointerdown', closeOnOutsideClick);
        document.addEventListener('keydown', closeOnEscape);
        return () => {
            document.removeEventListener('pointerdown', closeOnOutsideClick);
            document.removeEventListener('keydown', closeOnEscape);
        };
    }, []);

    return <div ref={preferencesRef} className="relative w-full max-w-xs">
        <button type="button" onClick={() => setExpanded(!expanded)} aria-expanded={expanded} aria-controls="market-preferences" className="flex w-full items-center justify-between rounded-lg border border-slate-700 bg-slate-900 px-3 py-2 text-left text-sm font-semibold text-white transition hover:border-slate-500 hover:bg-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-400">
            <span>{countryName} <span className="text-slate-500">·</span> {languageCodes[locale] ?? locale.toUpperCase()}</span>
            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.8" className={`h-4 w-4 text-slate-400 transition-transform ${expanded ? 'rotate-180' : ''}`} aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="m4 6 4 4 4-4" /></svg>
        </button>
        {expanded && <div id="market-preferences" className="absolute bottom-[calc(100%+0.5rem)] right-0 z-50 w-full rounded-xl border border-slate-700 bg-slate-900 p-2 shadow-xl shadow-black/30">
            <p className="px-2 pb-2 pt-1 text-[10px] font-bold uppercase tracking-[.18em] text-slate-500">{t('market')} & {t('language')}</p>
            <select aria-label={t('market')} value={country} onChange={event => updateCountry(event.target.value)} className="w-full rounded-lg border-0 bg-slate-800 px-3 py-2.5 text-sm text-white focus:ring-2 focus:ring-blue-400"><optgroup label="Belarus & Poland"><option value="BY">{countries.BY?.[locale] ?? 'Belarus'}</option><option value="PL">{countries.PL?.[locale] ?? 'Poland'}</option></optgroup><optgroup label={t('eu_global')}>{Object.entries(countries).filter(([code]) => !['BY', 'PL'].includes(code)).map(([code, names]) => <option key={code} value={code}>{names[locale] ?? names.en}</option>)}</optgroup></select>
            <select aria-label={t('language')} value={locale} onChange={event => updateLanguage(event.target.value)} className="mt-2 w-full rounded-lg border-0 bg-slate-800 px-3 py-2.5 text-sm text-white focus:ring-2 focus:ring-blue-400"><option value="en">English</option><option value="ru">Русский</option><option value="pl">Polski</option></select>
        </div>}
    </div>;
}

export default function StoreLayout({ children, title = 'daoSport' }) {
    const { auth, flash } = usePage().props;
    const t = useI18n();
    const [open, setOpen] = useState(false);
    return <div className="min-h-screen bg-[#f7f8fa] text-slate-900"><Head title={title} />
        <div className="bg-slate-950 px-4 py-2 text-center text-xs font-medium tracking-wide text-white">{t('free_shipping_banner')}</div>
        <header className="sticky top-0 z-40 border-b border-slate-200/80 bg-white/95 backdrop-blur"><div className="mx-auto flex max-w-7xl items-center gap-5 px-5 py-4 lg:px-8">
            <button onClick={() => setOpen(!open)} className="rounded-lg p-2 lg:hidden" aria-label="Open menu">☰</button><Link href={route('store.home')} className="text-2xl font-black tracking-tight"><span className="text-blue-500">dao</span>Sport</Link>
            <nav className={`${open ? 'absolute left-0 right-0 top-full flex' : 'hidden'} flex-col gap-4 border-b bg-white p-5 text-sm font-semibold lg:static lg:flex lg:flex-row lg:border-0 lg:bg-transparent lg:p-0`}><Link href={route('store.catalog')}>{t('shop_all')}</Link><Link href={route('store.catalog', { category: 'running' })}>{t('running')}</Link><Link href={route('store.catalog', { category: 'fitness' })}>{t('fitness')}</Link><Link href={route('store.catalog', { category: 'tennis' })}>{t('racket_sports')}</Link><Link href={route('store.catalog', { category: 'outdoor' })}>{t('outdoor')}</Link></nav>
            <form action={route('store.catalog')} className="ml-auto hidden max-w-xs flex-1 md:flex"><input name="q" placeholder={t('search_products')} className="w-full rounded-full border-slate-200 bg-slate-50 px-5 py-2.5 text-sm focus:border-blue-500 focus:ring-blue-500" /></form>
            <div className="flex items-center gap-2 text-sm">
                <div className="group relative hidden sm:block">
                    <Link href={auth.user ? route('dashboard') : route('login')} className="flex items-center gap-2 rounded-full px-3 py-2 transition hover:bg-slate-100 focus:bg-slate-100 focus:outline-none" aria-label="Account menu">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="h-5 w-5" aria-hidden="true"><circle cx="12" cy="8" r="3.25" /><path strokeLinecap="round" d="M5 20c.8-3.15 3.2-5 7-5s6.2 1.85 7 5" /></svg>
                        <span className="hidden xl:inline">{t('account')}</span>
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.8" className="hidden h-3.5 w-3.5 xl:block" aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="m4 6 4 4 4-4" /></svg>
                    </Link>
                    <div className="invisible absolute right-0 top-full z-50 w-56 pt-2 opacity-0 transition duration-150 group-hover:visible group-hover:opacity-100 group-focus-within:visible group-focus-within:opacity-100">
                        <div className="rounded-2xl border border-slate-200 bg-white p-2 shadow-xl shadow-slate-900/10">
                            {auth.user ? <><p className="px-3 py-2 text-xs font-medium text-slate-500">{t('signed_in_as', { name: auth.user.name })}</p><Link href={route('dashboard')} className="block rounded-xl px-3 py-2 text-sm font-semibold hover:bg-slate-100">{t('my_account')}</Link><Link href={route('profile.edit')} className="block rounded-xl px-3 py-2 text-sm font-semibold hover:bg-slate-100">{t('profile_settings')}</Link><Link href={route('logout')} method="post" as="button" className="mt-1 block w-full rounded-xl px-3 py-2 text-left text-sm font-semibold hover:bg-slate-100">{t('log_out')}</Link></> : <><p className="px-3 py-2 text-xs font-medium text-slate-500">{t('have_account')}</p><Link href={route('login')} className="block rounded-xl px-3 py-2 text-sm font-semibold hover:bg-slate-100">{t('log_in')}</Link><Link href={route('register')} className="mt-1 block rounded-xl px-3 py-2 text-sm font-semibold hover:bg-slate-100">{t('create_account')}</Link></>}
                        </div>
                    </div>
                </div>
                <div className="group relative">
                    <Link href={route('store.cart')} className="flex items-center gap-2 rounded-full bg-slate-950 px-3 py-2.5 font-semibold text-white transition hover:bg-slate-800 focus:bg-slate-800 focus:outline-none" aria-label="Shopping bag menu">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="h-5 w-5" aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M5 8h14l-1 12H6L5 8Z" /><path strokeLinecap="round" d="M9 10V6a3 3 0 0 1 6 0v4" /></svg>
                        <span className="hidden sm:inline">{t('bag')}</span>
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.8" className="h-3.5 w-3.5 text-blue-300" aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="m4 6 4 4 4-4" /></svg>
                    </Link>
                    <div className="invisible absolute right-0 top-full z-50 w-56 pt-2 opacity-0 transition duration-150 group-hover:visible group-hover:opacity-100 group-focus-within:visible group-focus-within:opacity-100">
                        <div className="rounded-2xl border border-slate-200 bg-white p-2 text-slate-900 shadow-xl shadow-slate-900/10">
                            <p className="px-3 py-2 text-xs font-medium text-slate-500">{t('ready_checkout')}</p>
                            <Link href={route('store.cart')} className="block rounded-xl px-3 py-2 text-sm font-semibold hover:bg-slate-100">{t('view_bag')}</Link>
                            <Link href={route('store.checkout')} className="mt-1 block rounded-xl px-3 py-2 text-sm font-semibold hover:bg-slate-100">{t('checkout')}</Link>
                        </div>
                    </div>
                </div>
            </div>
        </div></header>
        {flash?.success && <div className="mx-auto mt-4 max-w-7xl rounded-xl bg-blue-100 px-5 py-3 text-sm font-medium text-blue-900">{flash.success}</div>}{flash?.error && <div className="mx-auto mt-4 max-w-7xl rounded-xl bg-red-100 px-5 py-3 text-sm text-red-900">{flash.error}</div>}
        {children}<footer className="mt-20 bg-slate-950 text-white"><div className="mx-auto grid max-w-7xl gap-10 px-5 py-14 sm:grid-cols-2 lg:grid-cols-4 lg:px-8"><div><div className="text-2xl font-black"><span className="text-blue-400">dao</span>Sport</div><p className="mt-4 max-w-xs text-sm leading-6 text-slate-400">{t('footer_copy')}</p></div><div><h3 className="font-bold">Shop</h3><div className="mt-4 space-y-3 text-sm text-slate-400"><Link href={route('store.catalog')} className="block">{t('shop_all')}</Link><Link href={route('store.catalog', { category: 'running' })} className="block">{t('running')}</Link><Link href={route('store.catalog', { category: 'fitness' })} className="block">{t('fitness')}</Link></div></div><div><h3 className="font-bold">Help</h3><div className="mt-4 space-y-3 text-sm text-slate-400"><p>{t('delivery_returns')}</p><p>{t('contact_us')}</p><p>{t('size_guide')}</p></div></div><Preferences /></div><div className="border-t border-slate-800 py-5 text-center text-xs text-slate-500">© 2026 daoSport · {usePage().props.market?.currency ?? 'EUR'} · {usePage().props.market?.market ?? 'EU Global'}</div></footer></div>;
}
