import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import StoreLayout from '@/layouts/StoreLayout';
import { api, RequestError } from '@/api/client';
import { useApp } from '@/app/AppContext';
import { money, moneyLocale, useI18n } from '@/lib/i18n';

export default function Cart() {
    const { market } = useApp();
    const queryClient = useQueryClient();
    const t = useI18n();
    const { data } = useQuery({ queryKey: ['cart'], queryFn: () => api.get('/cart') });
    const cart = data?.data ?? { items: [], count: 0, subtotal_cents: 0 };
    const [items, setItems] = useState(cart.items);
    const [mutationError, setMutationError] = useState(null);
    useEffect(() => setItems(cart.items), [cart.items]);
    const locale = moneyLocale(market);
    const currency = cart.currency ?? market?.currency;
    const update = useMutation({
        mutationFn: ({ id, quantity }) => api.patch(`/cart/items/${id}`, { quantity }),
        onSuccess: response => {
            queryClient.setQueryData(['cart'], response);
            setMutationError(null);
        },
        onError: error => {
            setItems(cart.items);
            setMutationError(error instanceof RequestError
                ? (error.payload?.detail ?? error.payload?.title ?? 'Cart update failed.')
                : 'Cart update failed.');
        },
    });
    const change = (id, quantity) => {
        if (update.isPending) {
            return;
        }
        setItems(current => current.map(item => (item.id === id ? { ...item, quantity } : item)));
        update.mutate({ id, quantity });
    };
    const summary = (name, value) => (
        <div className="mt-3 flex justify-between text-sm text-slate-500">
            <span>{name}</span>
            <span>{value}</span>
        </div>
    );

    return <StoreLayout title={t('bag')}>
        <main className="mx-auto max-w-5xl px-5 py-14 lg:px-8">
            <h1 className="text-4xl font-black">
                {t('bag')} <span className="text-slate-400">({cart.count})</span>
            </h1>
            {cart.pricing_error && (
                <p className="mt-5 rounded-xl bg-red-100 p-4 text-red-800">{cart.pricing_error}</p>
            )}
            {mutationError && (
                <p className="mt-5 rounded-xl bg-red-100 p-4 text-red-800">{mutationError}</p>
            )}
            {items.length === 0 ? (
                <div className="mt-12 rounded-3xl bg-white p-12 text-center">
                    <p className="text-xl font-bold">Your bag is waiting.</p>
                    <Link
                        to="/shop"
                        className="mt-6 inline-block rounded-full bg-slate-950 px-6 py-3 font-bold text-white"
                    >
                        {t('shop_all')}
                    </Link>
                </div>
            ) : (
                <div className="mt-10 grid gap-10 lg:grid-cols-[1fr_340px]">
                    <div className="space-y-3">
                        {items.map(item => (
                            <div key={item.id} className="flex gap-4 rounded-2xl bg-white p-4">
                                <img
                                    src={item.image_url}
                                    alt={item.name}
                                    className="h-24 w-24 rounded-xl object-cover"
                                />
                                <div className="min-w-0 flex-1">
                                    <p className="font-bold">{item.name}</p>
                                    <p className="mt-1 text-sm text-slate-500">{item.variant_name}</p>
                                    <p className="mt-3 font-bold">
                                        {money(item.unit_price_cents, currency, locale)}
                                    </p>
                                </div>
                                <div className="flex items-center self-center rounded-full border">
                                    <button
                                        type="button"
                                        aria-label={t('catalog.decrease_quantity')}
                                        disabled={update.isPending}
                                        onClick={() => change(item.id, Math.max(0, item.quantity - 1))}
                                        className="px-3 py-2 disabled:opacity-30"
                                    >
                                        −
                                    </button>
                                    <span className="text-sm">{item.quantity}</span>
                                    <button
                                        type="button"
                                        aria-label={t('catalog.increase_quantity')}
                                        disabled={update.isPending}
                                        onClick={() => change(item.id, item.quantity + 1)}
                                        className="px-3 py-2 disabled:opacity-30"
                                    >
                                        +
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                    <aside className="h-fit rounded-2xl bg-white p-6">
                        <h2 className="text-xl font-black">Summary</h2>
                        {summary(
                            t('net'),
                            money(cart.net_subtotal_cents ?? cart.subtotal_cents, currency, locale),
                        )}
                        {summary(t('vat'), money(cart.tax_cents ?? 0, currency, locale))}
                        {summary(
                            t('delivery'),
                            cart.delivery_cents ? money(cart.delivery_cents, currency, locale) : t('free'),
                        )}
                        <div className="my-6 flex justify-between border-t pt-5 font-black">
                            <span>{t('total')}</span>
                            <span>
                                {money(cart.total_cents ?? cart.subtotal_cents, currency, locale)}
                            </span>
                        </div>
                        <Link
                            to="/checkout"
                            className="block rounded-full bg-slate-950 py-3 text-center font-bold text-white"
                        >
                            {t('checkout')} ↗
                        </Link>
                    </aside>
                </div>
            )}
        </main>
    </StoreLayout>;
}
