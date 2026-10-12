import { Link, Navigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import StoreLayout from '@/layouts/StoreLayout';
import { api, RequestError } from '@/api/client';
import { useApp } from '@/app/AppContext';
import { money } from '@/lib/i18n';

const orderLocale = order => (order.locale === 'pl'
    ? 'pl-PL'
    : order.locale === 'ru' ? 'ru-RU' : 'en-IE');

export default function Dashboard() {
    const { auth, contextLoading } = useApp();
    const { data, error, isPending } = useQuery({
        queryKey: ['my-orders'],
        queryFn: () => api.get('/me/orders'),
        enabled: Boolean(auth.user),
        retry: false,
    });
    const unauthenticated = error instanceof RequestError && error.status === 401;

    if (contextLoading) {
        return null;
    }
    if (!auth.user || unauthenticated) {
        return <Navigate to="/login" replace />;
    }
    const orders = data?.data ?? [];

    const row = order => (
        <Link
            key={order.id}
            to={`/orders/${order.number}`}
            className="flex flex-wrap items-center justify-between gap-4 rounded-2xl bg-white p-5"
        >
            <div>
                <p className="font-bold">{order.number}</p>
                <p className="mt-1 text-sm text-slate-500">
                    {order.items?.length ?? 0} items · {new Date(order.created_at).toLocaleDateString()}
                </p>
            </div>
            <div className="flex items-center gap-5">
                <span className="rounded-full bg-blue-100 px-3 py-1 text-xs font-bold text-blue-800">
                    {order.status.replaceAll('_', ' ')}
                </span>
                <span className="font-bold">
                    {money(order.total_cents, order.currency, orderLocale(order))}
                </span>
            </div>
        </Link>
    );

    return <StoreLayout title="Your account">
        <main className="mx-auto max-w-5xl px-5 py-14 lg:px-8">
            <p className="text-xs font-bold uppercase tracking-[.25em] text-blue-600">Your space</p>
            <h1 className="mt-3 text-4xl font-black">Account</h1>
            <div className="mt-10 rounded-2xl bg-slate-950 p-7 text-white">
                <p className="text-sm text-slate-400">Welcome back</p>
                <h2 className="mt-2 text-2xl font-black">Your movement, your way.</h2>
                <Link
                    to="/shop"
                    className="mt-6 inline-block rounded-full bg-blue-400 px-5 py-2.5
                        text-sm font-bold text-slate-950"
                >
                    Shop new gear ↗
                </Link>
            </div>
            <section className="mt-12">
                <h2 className="text-2xl font-black">Order history</h2>
                {isPending ? null : orders.length === 0 ? (
                    <div className="mt-5 rounded-2xl bg-white p-8 text-slate-500">
                        No orders yet. Your next adventure starts here.
                    </div>
                ) : (
                    <div className="mt-5 space-y-3">{orders.map(row)}</div>
                )}
            </section>
        </main>
    </StoreLayout>;
}
