import { Link, useNavigate } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect } from 'react';
import StoreLayout from '@/layouts/StoreLayout';
import { api } from '@/api/client';
import { useApp } from '@/app/AppContext';
import { money, moneyLocale, useI18n } from '@/lib/i18n';
import { useForm } from '@/lib/useForm';

export default function Checkout() {
    const { market, countries } = useApp();
    const queryClient = useQueryClient();
    const navigate = useNavigate();
    const t = useI18n();
    const { data: checkout } = useQuery({
        queryKey: ['checkout'],
        queryFn: () => api.get('/checkout'),
    });
    const cart = checkout?.data?.cart ?? { items: [], count: 0, subtotal_cents: 0 };
    const user = checkout?.data?.user;
    const address = checkout?.data?.address;
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        phone: '',
        first_name: '',
        last_name: '',
        line1: '',
        line2: '',
        city: '',
        postal_code: '',
        country: 'DE',
        delivery_method: 'standard',
    });

    useEffect(() => {
        if (!checkout) {
            return;
        }
        setData('email', user?.email ?? '');
        setData('first_name', address?.first_name ?? '');
        setData('last_name', address?.last_name ?? '');
        setData('line1', address?.line1 ?? '');
        setData('line2', address?.line2 ?? '');
        setData('city', address?.city ?? '');
        setData('postal_code', address?.postal_code ?? '');
        setData('country', address?.country ?? market?.country ?? 'DE');
    }, [checkout]);

    const currency = cart.currency ?? market?.currency;
    const locale = moneyLocale(market);
    const quote = cart.delivery_quotes?.[data.delivery_method] ?? cart;
    const deliveryPrice = method => cart.delivery_quotes?.[method]?.delivery_cents;
    const deliveryLabel = method => {
        const cents = deliveryPrice(method) ?? (method === data.delivery_method ? cart.delivery_cents : null);
        return cents ? money(cents, currency, locale) : t('free');
    };
    const netCents = (quote.net_subtotal_cents ?? cart.net_subtotal_cents ?? cart.subtotal_cents ?? 0)
        + (quote.delivery_net_cents ?? 0);
    const taxCents = (quote.tax_cents ?? 0) + (quote.delivery_tax_cents ?? 0);
    const deliveryCents = quote.delivery_cents ?? cart.delivery_cents ?? 0;
    const totalCents = quote.total_cents ?? cart.total_cents ?? cart.subtotal_cents ?? 0;

    const field = (name, placeholder, type = 'text') => (
        <div>
            <input
                type={type}
                value={data[name]}
                onChange={e => setData(name, e.target.value)}
                placeholder={placeholder}
                className="w-full rounded-xl border-slate-200 bg-white px-4 py-3 text-sm"
            />
            {errors[name] && <p className="mt-1 text-xs text-red-600">{errors[name]}</p>}
        </div>
    );

    const deliveryOption = (method, name) => (
        <label className="rounded-xl border p-4">
            <input
                type="radio"
                className="mr-3"
                checked={data.delivery_method === method}
                onChange={() => setData('delivery_method', method)}
            />
            {name}
            <span className="float-right font-bold">{deliveryLabel(method)}</span>
        </label>
    );

    const submit = event => {
        event.preventDefault();
        post('/orders', {
            onSuccess: response => {
                queryClient.invalidateQueries({ queryKey: ['cart'] });
                navigate(`/orders/${response.data.number}`);
            },
        });
    };

    return <StoreLayout title={t('checkout')}>
        <main className="mx-auto max-w-6xl px-5 py-12 lg:px-8">
            <h1 className="mt-3 text-4xl font-black">{t('checkout')}</h1>
            {cart.pricing_error && (
                <p className="mt-6 rounded-xl bg-red-100 p-4 text-red-800">{cart.pricing_error}</p>
            )}
            <form onSubmit={submit} className="mt-10 grid gap-10 lg:grid-cols-[1fr_360px]">
                <div className="space-y-7">
                    <section className="rounded-2xl bg-white p-6">
                        <h2 className="text-lg font-black">Contact</h2>
                        <div className="mt-5 grid gap-3 sm:grid-cols-2">
                            {field('email', 'Email address', 'email')}
                            {field('phone', 'Phone number', 'tel')}
                        </div>
                    </section>
                    <section className="rounded-2xl bg-white p-6">
                        <h2 className="text-lg font-black">Delivery address</h2>
                        <div className="mt-5 grid gap-3 sm:grid-cols-2">
                            {field('first_name', 'First name')}
                            {field('last_name', 'Last name')}
                            <div className="sm:col-span-2">{field('line1', 'Address')}</div>
                            <div className="sm:col-span-2">
                                {field('line2', 'Apartment, suite (optional)')}
                            </div>
                            {field('city', 'City')}
                            {field('postal_code', 'Postal code')}
                            <select
                                value={data.country}
                                onChange={e => setData('country', e.target.value)}
                                className="w-full rounded-xl border-slate-200 bg-white px-4 py-3 text-sm"
                            >
                                {Object.entries(countries).map(([code, names]) => (
                                    <option key={code} value={code}>
                                        {names[market?.locale] ?? names.en}
                                    </option>
                                ))}
                            </select>
                        </div>
                    </section>
                    <section className="rounded-2xl bg-white p-6">
                        <h2 className="text-lg font-black">{t('delivery')}</h2>
                        <div className="mt-5 grid gap-3 sm:grid-cols-2">
                            {deliveryOption('standard', 'Standard')}
                            {deliveryOption('express', 'Express')}
                        </div>
                    </section>
                </div>
                <aside className="h-fit rounded-2xl bg-slate-950 p-6 text-white">
                    <h2 className="text-xl font-black">Order summary</h2>
                    <div className="mt-6 space-y-3">
                        {cart.items.map(item => (
                            <div key={item.id} className="flex justify-between gap-3 text-sm">
                                <span className="text-slate-300">{item.name} × {item.quantity}</span>
                                <span>{money(item.line_total_cents, currency, locale)}</span>
                            </div>
                        ))}
                    </div>
                    <div className="my-6 space-y-3 border-t border-slate-700 pt-5 text-sm">
                        <div className="flex justify-between text-slate-300">
                            <span>{t('net')}</span>
                            <span>{money(netCents, currency, locale)}</span>
                        </div>
                        <div className="flex justify-between text-slate-300">
                            <span>{t('vat')} ({market?.vat_rate}%)</span>
                            <span>{money(taxCents, currency, locale)}</span>
                        </div>
                        <div className="flex justify-between text-slate-300">
                            <span>{t('delivery')}</span>
                            <span>
                                {deliveryCents ? money(deliveryCents, currency, locale) : t('free')}
                            </span>
                        </div>
                        <div className="mt-5 flex justify-between text-lg font-black">
                            <span>{t('total')}</span>
                            <span>{money(totalCents, currency, locale)}</span>
                        </div>
                    </div>
                    <button
                        disabled={processing || Boolean(cart.pricing_error)}
                        className="w-full rounded-full bg-blue-400 py-3.5 font-bold
                            text-slate-950 disabled:opacity-50"
                    >
                        {t('place_order')} ↗
                    </button>
                    {errors.cart && <p className="mt-3 text-xs text-red-300">{errors.cart}</p>}
                    <Link to="/cart" className="mt-4 block text-center text-xs text-slate-400">
                        ← {t('bag')}
                    </Link>
                </aside>
            </form>
        </main>
    </StoreLayout>;
}
