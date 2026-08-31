import { Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';

const label = value => value.replaceAll('_', ' ').replace(/^./, character => character.toUpperCase());
const money = (cents, currency) => (cents / 100).toLocaleString('en-IE', { style: 'currency', currency });
const tone = { pending_payment: 'bg-amber-100 text-amber-800', paid: 'bg-blue-100 text-blue-800', processing: 'bg-violet-100 text-violet-800', shipped: 'bg-cyan-100 text-cyan-800', delivered: 'bg-blue-100 text-blue-800', cancelled: 'bg-slate-200 text-slate-700', refunded: 'bg-rose-100 text-rose-800' };

function StatusSelect({ value, onChange, statuses }) {
    const [open, setOpen] = useState(false);
    const root = useRef(null);

    useEffect(() => {
        const close = event => { if (!root.current?.contains(event.target)) setOpen(false); };
        document.addEventListener('mousedown', close);
        return () => document.removeEventListener('mousedown', close);
    }, []);

    const selected = value ? label(value) : 'All statuses';

    return <div ref={root} className="relative w-full sm:w-52">
        <button type="button" aria-haspopup="listbox" aria-expanded={open} onClick={() => setOpen(current => !current)} className={`flex w-full items-center justify-between rounded-xl bg-slate-100 px-4 py-3 text-left text-sm font-semibold transition ${open ? 'bg-slate-200 ring-2 ring-blue-400' : 'hover:bg-slate-200'}`}>
            <span className="truncate">{selected}</span><svg aria-hidden="true" viewBox="0 0 20 20" fill="none" className={`ml-3 h-4 w-4 flex-none text-slate-400 transition-transform duration-200 ${open ? 'rotate-180' : ''}`}><path d="m5 7.5 5 5 5-5" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" /></svg>
        </button>
        <div role="listbox" aria-hidden={!open} className={`absolute left-0 right-0 z-30 mt-2 max-h-72 origin-top overflow-auto rounded-xl border border-slate-200 bg-white p-1.5 shadow-xl shadow-slate-900/10 transition duration-150 ease-out ${open ? 'visible translate-y-0 scale-100 opacity-100' : 'invisible pointer-events-none -translate-y-1 scale-95 opacity-0'}`}>
            {['', ...statuses].map(option => <button type="button" role="option" aria-selected={value === option} key={option || 'all'} onClick={() => { onChange(option); setOpen(false); }} className={`flex w-full items-center rounded-lg px-3 py-2.5 text-left text-sm font-semibold capitalize transition ${value === option ? 'bg-blue-400 text-slate-950' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-950'}`}><span className="mr-2 w-4 text-center">{value === option ? '✓' : ''}</span>{option ? label(option) : 'All statuses'}</button>)}
        </div>
    </div>;
}

export default function Index({ orders, filters, statuses }) {
    const [q, setQ] = useState(filters.q ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const submit = event => { event.preventDefault(); router.get(route('admin.orders.index'), { q, status }, { preserveState: true }); };

    return <AdminLayout title="Orders">
        <div className="flex flex-wrap items-end justify-between gap-5"><div><p className="text-sm font-semibold text-slate-500">Operations</p><h1 className="mt-1 text-4xl font-black tracking-tight">Orders</h1><p className="mt-2 text-sm text-slate-500">Review and manage customer orders.</p></div><div className="rounded-2xl bg-white px-5 py-3 text-right shadow-sm"><p className="text-xs font-semibold uppercase tracking-wider text-slate-400">Total orders</p><p className="mt-1 text-2xl font-black">{orders.meta.total}</p></div></div>
        <form onSubmit={submit} className="mt-8 flex flex-col gap-3 rounded-2xl bg-white p-4 shadow-sm sm:flex-row"><input value={q} onChange={e => setQ(e.target.value)} placeholder="Search order number or email" className="min-w-0 flex-1 rounded-xl border-0 bg-slate-100 px-4 py-3 text-sm outline-none ring-blue-400 focus:ring-2" /><StatusSelect value={status} onChange={setStatus} statuses={statuses} /><button className="rounded-xl bg-slate-950 px-5 py-3 text-sm font-bold text-white hover:bg-slate-800">Search</button></form>
        <div className="mt-6 overflow-hidden rounded-2xl bg-white shadow-sm"><div className="overflow-x-auto"><table className="w-full min-w-[760px] text-left text-sm"><thead className="border-b border-slate-100 text-xs uppercase tracking-wider text-slate-400"><tr><th className="px-6 py-4">Order</th><th className="px-6 py-4">Customer</th><th className="px-6 py-4">Status</th><th className="px-6 py-4">Items</th><th className="px-6 py-4">Total</th><th className="px-6 py-4"></th></tr></thead><tbody className="divide-y divide-slate-100">{orders.data.map(order => <tr key={order.id} className="hover:bg-slate-50"><td className="px-6 py-5"><p className="font-bold">{order.number}</p><p className="mt-1 text-xs text-slate-400">{new Date(order.created_at).toLocaleDateString()}</p></td><td className="px-6 py-5"><p>{order.email}</p></td><td className="px-6 py-5"><span className={`rounded-full px-3 py-1 text-xs font-bold ${tone[order.status]}`}>{label(order.status)}</span></td><td className="px-6 py-5 text-slate-500">{order.items_count}</td><td className="px-6 py-5 font-bold">{money(order.total_cents, order.currency)}</td><td className="px-6 py-5 text-right"><Link href={route('admin.orders.show', order.id)} className="font-bold text-slate-900 underline decoration-blue-400 decoration-2 underline-offset-4">View</Link></td></tr>)}</tbody></table></div>{orders.data.length === 0 && <p className="px-6 py-12 text-center text-sm text-slate-500">No orders found.</p>}</div>
        {orders.meta.last_page > 1 && <div className="mt-5 flex gap-2">{Array.from({ length: orders.meta.last_page }, (_, i) => i + 1).map(page => <Link key={page} href={route('admin.orders.index', { ...filters, page })} className={`rounded-lg px-3 py-2 text-sm font-bold ${page === orders.meta.current_page ? 'bg-slate-950 text-white' : 'bg-white text-slate-600'}`}>{page}</Link>)}</div>}
    </AdminLayout>;
}
