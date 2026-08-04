import { Link, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';

const label = value => value.replaceAll('_', ' ').replace(/^./, character => character.toUpperCase());
const money = (cents, currency) => (cents / 100).toLocaleString('en-IE', { style: 'currency', currency });
const Field = ({ name, value }) => <div><p className="text-xs font-bold uppercase tracking-wider text-slate-400">{name}</p><p className="mt-2 font-semibold">{value || '—'}</p></div>;

function StatusSelect({ value, onChange, statuses }) {
    const [open, setOpen] = useState(false);
    const root = useRef(null);
    useEffect(() => {
        const close = event => { if (!root.current?.contains(event.target)) setOpen(false); };
        document.addEventListener('mousedown', close);
        return () => document.removeEventListener('mousedown', close);
    }, []);

    return <div ref={root} className="relative w-full md:w-48">
        <button type="button" aria-haspopup="listbox" aria-expanded={open} onClick={() => setOpen(current => !current)} className={`flex w-full items-center justify-between rounded-xl bg-white px-4 py-3 text-left text-sm font-semibold shadow-sm transition ${open ? 'ring-2 ring-lime-400' : 'hover:bg-slate-50'}`}>
            <span className="truncate">{label(value)}</span><svg aria-hidden="true" viewBox="0 0 20 20" fill="none" className={`ml-3 h-4 w-4 flex-none text-slate-400 transition-transform duration-200 ${open ? 'rotate-180' : ''}`}><path d="m5 7.5 5 5 5-5" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round" /></svg>
        </button>
        <div role="listbox" aria-hidden={!open} className={`absolute left-0 right-0 top-full z-30 mt-2 origin-top rounded-xl border border-slate-200 bg-white p-1.5 shadow-xl shadow-slate-900/10 transition duration-150 ease-out ${open ? 'visible translate-y-0 scale-100 opacity-100' : 'invisible pointer-events-none -translate-y-1 scale-95 opacity-0'}`}>
            {statuses.map(option => <button type="button" role="option" aria-selected={value === option} key={option} onClick={() => { onChange(option); setOpen(false); }} className={`flex w-full items-center rounded-lg px-3 py-2.5 text-left text-sm font-semibold capitalize transition ${value === option ? 'bg-lime-400 text-slate-950' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-950'}`}><span className="mr-2 w-4 text-center">{value === option ? '✓' : ''}</span>{label(option)}</button>)}
        </div>
    </div>;
}

export default function Show({ order, nextStatuses }) {
    const form = useForm({ status: nextStatuses[0] ?? '' });
    const submit = e => { e.preventDefault(); form.patch(route('admin.orders.status.update', order.id)); };
    return <AdminLayout title={order.number}>
        <div className="flex flex-col gap-5 md:flex-row md:items-start md:justify-between"><div><Link href={route('admin.orders.index')} className="text-sm font-semibold text-slate-500 hover:text-slate-900">← Orders</Link><h1 className="mt-3 text-4xl font-black tracking-tight">{order.number}</h1><p className="mt-2 text-sm text-slate-500">{new Date(order.created_at).toLocaleString()}</p></div>{nextStatuses.length > 0 && <form onSubmit={submit} className="flex w-full flex-col gap-2 md:w-auto md:flex-row md:items-center"><StatusSelect value={form.data.status} onChange={value => form.setData('status', value)} statuses={nextStatuses} /><button disabled={form.processing} className="w-full rounded-xl bg-lime-400 px-5 py-3 text-sm font-bold text-slate-950 hover:bg-lime-300 md:w-auto">Change status</button></form>}</div>
        {form.errors.status && <p className="mt-4 rounded-xl bg-red-100 px-4 py-3 text-sm font-semibold text-red-800">{form.errors.status}</p>}
        <div className="mt-8 grid gap-5 xl:grid-cols-2"><section className="rounded-2xl bg-white p-6 shadow-sm"><h2 className="text-lg font-black">Order details</h2><div className="mt-6 grid grid-cols-2 gap-6"><Field name="Status" value={label(order.status)} /><Field name="Email" value={order.email} /><Field name="Phone" value={order.phone} /><Field name="Delivery" value={order.delivery_method} /><Field name="Subtotal" value={money(order.subtotal_cents, order.currency)} /><Field name="Delivery cost" value={money(order.delivery_cents, order.currency)} /><Field name="Total" value={money(order.total_cents, order.currency)} /><Field name="Currency" value={order.currency} /></div></section><section className="rounded-2xl bg-white p-6 shadow-sm"><h2 className="text-lg font-black">Customer & delivery</h2><div className="mt-6 grid grid-cols-2 gap-6"><Field name="Customer" value={order.customer?.name} /><Field name="Customer email" value={order.customer?.email} /><Field name="First name" value={order.address?.first_name} /><Field name="Last name" value={order.address?.last_name} /><Field name="Address" value={[order.address?.line1, order.address?.line2].filter(Boolean).join(', ')} /><Field name="City" value={[order.address?.postal_code, order.address?.city].filter(Boolean).join(' ')} /><Field name="Country" value={order.address?.country} /></div></section></div>
        <section className="mt-5 min-w-0 rounded-2xl bg-white p-6 shadow-sm"><h2 className="text-lg font-black">Items <span className="font-normal text-slate-400">({order.items.length})</span></h2><div className="mt-5 divide-y divide-slate-100">{order.items.map(item => <div key={item.id} className="grid min-w-0 gap-3 py-5 md:grid-cols-[minmax(0,1fr)_140px_100px_140px] md:items-center"><div className="min-w-0"><p className="truncate font-bold">{item.name}</p><p className="mt-1 truncate text-sm text-slate-500">{item.variant_name || 'One size'}</p></div><p className="text-sm text-slate-500">Qty {item.quantity}</p><p className="text-sm text-slate-500">{money(item.unit_price_cents, order.currency)}</p><p className="font-bold md:text-right">{money(item.line_total_cents, order.currency)}</p></div>)}</div></section>
    </AdminLayout>;
}
