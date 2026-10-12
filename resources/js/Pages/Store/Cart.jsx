import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import StoreLayout from '@/Layouts/StoreLayout';
import { money, useI18n } from '@/lib/i18n';

export default function Cart({ cart }) {
    const [items, setItems] = useState(cart.items);
    const { market } = usePage().props;
    const t = useI18n();
    const currency = cart.currency ?? market.currency;
    const locale = market.locale === 'pl' ? 'pl-PL' : market.locale === 'ru' ? 'ru-RU' : 'en-IE';

    const update = (id, quantity) => {
        setItems(items.map(item => (item.id === id ? { ...item, quantity } : item)));
        router.patch(route('store.cart.update'), { items: [{ id, quantity }] });
    };

    const summary = (name, value) => (
        <div className="mt-3 flex justify-between text-sm text-slate-500">
            <span>{name}</span>
            <span>{value}</span>
        </div>
    );

    return (
        <StoreLayout title={t('bag')}>
            <main className="mx-auto max-w-5xl px-5 py-14 lg:px-8">
                <h1 className="text-4xl font-black">
                    {t('bag')} <span className="text-slate-400">({cart.count})</span>
                </h1>
                {cart.pricing_error && (
                    <p className="mt-5 rounded-xl bg-red-100 p-4 text-red-800">{cart.pricing_error}</p>
                )}
                {items.length === 0 ? (
                    <div className="mt-12 rounded-3xl bg-white p-12 text-center">
                        <p className="text-xl font-bold">Your bag is waiting.</p>
                        <Link
                            href={route('store.catalog')}
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
                                        <p className="mt-1 text-sm text-slate-500">
                                            {item.variant_name}
                                        </p>
                                        <p className="mt-3 font-bold">
                                            {money(item.unit_price_cents, currency, locale)}
                                        </p>
                                    </div>
                                    <div className="flex items-center self-center rounded-full border">
                                        <button
                                            type="button"
                                            aria-label={t('catalog.decrease_quantity')}
                                            onClick={() => update(
                                                item.id,
                                                Math.max(0, item.quantity - 1),
                                            )}
                                            className="px-3 py-2"
                                        >
                                            −
                                        </button>
                                        <span className="text-sm">{item.quantity}</span>
                                        <button
                                            type="button"
                                            aria-label={t('catalog.increase_quantity')}
                                            onClick={() => update(item.id, item.quantity + 1)}
                                            className="px-3 py-2"
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
                                money(
                                    cart.net_subtotal_cents ?? cart.subtotal_cents,
                                    currency,
                                    locale,
                                ),
                            )}
                            {summary(t('vat'), money(cart.tax_cents ?? 0, currency, locale))}
                            {summary(
                                t('delivery'),
                                cart.delivery_cents
                                    ? money(cart.delivery_cents, currency, locale)
                                    : t('free'),
                            )}
                            <div className="my-6 flex justify-between border-t pt-5 font-black">
                                <span>{t('total')}</span>
                                <span>
                                    {money(
                                        cart.total_cents ?? cart.subtotal_cents,
                                        currency,
                                        locale,
                                    )}
                                </span>
                            </div>
                            <Link
                                href={route('store.checkout')}
                                className="block rounded-full bg-slate-950 py-3 text-center font-bold text-white"
                            >
                                {t('checkout')} ↗
                            </Link>
                        </aside>
                    </div>
                )}
            </main>
        </StoreLayout>
    );
}
