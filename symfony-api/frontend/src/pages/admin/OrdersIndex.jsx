import { Link, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import AdminLayout from '@/layouts/AdminLayout';
import StatusSelect from '@/components/StatusSelect';
import { api } from '@/api/client';
import AdminGuard from '@/pages/admin/AdminGuard';

const label = value => value.replaceAll('_', ' ').replace(/^./, character => character.toUpperCase());
const money = (cents, currency) => (
    (cents / 100).toLocaleString('en-IE', { style: 'currency', currency })
);
const tone = {
    pending_payment: 'bg-amber-100 text-amber-800',
    paid: 'bg-blue-100 text-blue-800',
    processing: 'bg-violet-100 text-violet-800',
    shipped: 'bg-cyan-100 text-cyan-800',
    delivered: 'bg-blue-100 text-blue-800',
    cancelled: 'bg-slate-200 text-slate-700',
    refunded: 'bg-rose-100 text-rose-800',
};

export default function OrdersIndex() {
    const [searchParams, setSearchParams] = useSearchParams();
    const filters = {
        q: searchParams.get('q') ?? '',
        status: searchParams.get('status') ?? '',
        page: searchParams.get('page') ?? '1',
    };
    const [q, setQ] = useState(filters.q);
    const [status, setStatus] = useState(filters.status);
    const params = new URLSearchParams();
    Object.entries(filters).forEach(([key, value]) => {
        if (value) {
            params.set(key, value);
        }
    });
    const { data } = useQuery({
        queryKey: ['admin-orders', filters],
        queryFn: () => api.get(`/admin/orders?${params}`),
    });
    const orders = data ?? { data: [], meta: { total: 0, current_page: 1, last_page: 1 } };
    const statuses = data?.statuses ?? [];

    const submit = event => {
        event.preventDefault();
        setSearchParams({ ...(q ? { q } : {}), ...(status ? { status } : {}) });
    };

    const heading = (
        <div className="flex flex-wrap items-end justify-between gap-5">
            <div>
                <p className="text-sm font-semibold text-slate-500">Operations</p>
                <h1 className="mt-1 text-4xl font-black tracking-tight">Orders</h1>
                <p className="mt-2 text-sm text-slate-500">
                    Review and manage customer orders.
                </p>
            </div>
            <div className="rounded-2xl bg-white px-5 py-3 text-right shadow-sm">
                <p className="text-xs font-semibold uppercase tracking-wider text-slate-400">
                    Total orders
                </p>
                <p className="mt-1 text-2xl font-black">{orders.meta.total}</p>
            </div>
        </div>
    );

    const searchForm = (
        <form
            onSubmit={submit}
            className="mt-8 flex flex-col gap-3 rounded-2xl bg-white p-4 shadow-sm sm:flex-row"
        >
            <input
                value={q}
                onChange={e => setQ(e.target.value)}
                placeholder="Search order number or email"
                className="min-w-0 flex-1 rounded-xl border-0 bg-slate-100 px-4 py-3 text-sm
                    outline-none ring-blue-400 focus:ring-2"
            />
            <StatusSelect
                value={status}
                onChange={setStatus}
                options={['', ...statuses]}
                label={option => (option ? label(option) : 'All statuses')}
            />
            <button className="rounded-xl bg-slate-950 px-5 py-3 text-sm font-bold text-white
                hover:bg-slate-800">
                Search
            </button>
        </form>
    );

    const row = order => (
        <tr key={order.id} className="hover:bg-slate-50">
            <td className="px-6 py-5">
                <p className="font-bold">{order.number}</p>
                <p className="mt-1 text-xs text-slate-400">
                    {new Date(order.created_at).toLocaleDateString()}
                </p>
            </td>
            <td className="px-6 py-5">
                <p>{order.email}</p>
            </td>
            <td className="px-6 py-5">
                <span className={`rounded-full px-3 py-1 text-xs font-bold ${tone[order.status]}`}>
                    {label(order.status)}
                </span>
            </td>
            <td className="px-6 py-5 text-slate-500">{order.items_count}</td>
            <td className="px-6 py-5 font-bold">{money(order.total_cents, order.currency)}</td>
            <td className="px-6 py-5 text-right">
                <Link
                    to={`/admin/orders/${order.id}`}
                    className="font-bold text-slate-900 underline decoration-blue-400
                        decoration-2 underline-offset-4"
                >
                    View
                </Link>
            </td>
        </tr>
    );

    const table = (
        <div className="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm">
            <div className="overflow-x-auto">
                <table className="w-full min-w-[760px] text-left text-sm">
                    <thead className="border-b border-slate-100 text-xs uppercase tracking-wider
                        text-slate-400">
                        <tr>
                            <th className="px-6 py-4">Order</th>
                            <th className="px-6 py-4">Customer</th>
                            <th className="px-6 py-4">Status</th>
                            <th className="px-6 py-4">Items</th>
                            <th className="px-6 py-4">Total</th>
                            <th className="px-6 py-4" />
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                        {orders.data.map(row)}
                    </tbody>
                </table>
            </div>
            {orders.data.length === 0 && (
                <p className="px-6 py-12 text-center text-sm text-slate-500">No orders found.</p>
            )}
        </div>
    );

    const pagination = orders.meta.last_page > 1 && (
        <div className="mt-5 flex gap-2">
            {Array.from({ length: orders.meta.last_page }, (_, i) => i + 1).map(page => (
                <Link
                    key={page}
                    to={`/admin/orders?${new URLSearchParams({
                        ...(filters.q ? { q: filters.q } : {}),
                        ...(filters.status ? { status: filters.status } : {}),
                        page: String(page),
                    })}`}
                    className={`rounded-lg px-3 py-2 text-sm font-bold ${
                        page === orders.meta.current_page
                            ? 'bg-slate-950 text-white'
                            : 'bg-white text-slate-600'
                    }`}
                >
                    {page}
                </Link>
            ))}
        </div>
    );

    return (
        <AdminGuard>
            <AdminLayout title="Orders">
                {heading}
                {searchForm}
                {table}
                {pagination}
            </AdminLayout>
        </AdminGuard>
    );
}
