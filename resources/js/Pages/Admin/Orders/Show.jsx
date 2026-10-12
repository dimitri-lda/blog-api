import { Link, useForm } from '@inertiajs/react';
import AdminLayout from '@/Layouts/AdminLayout';
import StatusSelect from '@/Components/StatusSelect';

const label = value => value.replaceAll('_', ' ').replace(/^./, character => character.toUpperCase());
const money = (cents, currency) => (
    (cents / 100).toLocaleString('en-IE', { style: 'currency', currency })
);
const Field = ({ name, value }) => (
    <div>
        <p className="text-xs font-bold uppercase tracking-wider text-slate-400">{name}</p>
        <p className="mt-2 font-semibold">{value || '—'}</p>
    </div>
);

export default function Show({ order, nextStatuses }) {
    const form = useForm({ status: nextStatuses[0] ?? '' });
    const submit = (e) => {
        e.preventDefault();
        form.patch(route('admin.orders.status.update', order.id));
    };

    const header = (
        <div className="flex flex-col gap-5 md:flex-row md:items-start md:justify-between">
            <div>
                <Link
                    href={route('admin.orders.index')}
                    className="text-sm font-semibold text-slate-500 hover:text-slate-900"
                >
                    ← Orders
                </Link>
                <h1 className="mt-3 text-4xl font-black tracking-tight">{order.number}</h1>
                <p className="mt-2 text-sm text-slate-500">
                    {new Date(order.created_at).toLocaleString()}
                </p>
            </div>
            {nextStatuses.length > 0 && (
                <form
                    onSubmit={submit}
                    className="flex w-full flex-col gap-2 md:w-auto md:flex-row md:items-center"
                >
                    <StatusSelect
                        value={form.data.status}
                        onChange={value => form.setData('status', value)}
                        options={nextStatuses}
                        label={label}
                        className="w-full md:w-48"
                    />
                    <button
                        disabled={form.processing}
                        className="w-full rounded-xl bg-blue-400 px-5 py-3 text-sm font-bold
                            text-slate-950 hover:bg-blue-300 md:w-auto"
                    >
                        Change status
                    </button>
                </form>
            )}
        </div>
    );

    const details = (
        <section className="rounded-2xl bg-white p-6 shadow-sm">
            <h2 className="text-lg font-black">Order details</h2>
            <div className="mt-6 grid grid-cols-2 gap-6">
                <Field name="Status" value={label(order.status)} />
                <Field name="Email" value={order.email} />
                <Field name="Phone" value={order.phone} />
                <Field name="Delivery" value={order.delivery_method} />
                <Field
                    name="Net subtotal"
                    value={order.net_subtotal_cents == null
                        ? '—'
                        : money(order.net_subtotal_cents, order.currency)}
                />
                <Field
                    name="VAT"
                    value={order.tax_cents == null ? '—' : money(order.tax_cents, order.currency)}
                />
                <Field name="Gross subtotal" value={money(order.subtotal_cents, order.currency)} />
                <Field name="Delivery cost" value={money(order.delivery_cents, order.currency)} />
                <Field name="Total" value={money(order.total_cents, order.currency)} />
                <Field name="Currency" value={order.currency} />
                <Field name="Market" value={order.market} />
                <Field name="Exchange rate" value={order.exchange_rate} />
                <Field
                    name="VAT rate"
                    value={order.vat_rate_basis_points == null
                        ? '—'
                        : `${order.vat_rate_basis_points / 100}%`}
                />
            </div>
        </section>
    );

    const customer = (
        <section className="rounded-2xl bg-white p-6 shadow-sm">
            <h2 className="text-lg font-black">Customer & delivery</h2>
            <div className="mt-6 grid grid-cols-2 gap-6">
                <Field name="Customer" value={order.customer?.name} />
                <Field name="Customer email" value={order.customer?.email} />
                <Field name="First name" value={order.address?.first_name} />
                <Field name="Last name" value={order.address?.last_name} />
                <Field
                    name="Address"
                    value={[order.address?.line1, order.address?.line2].filter(Boolean).join(', ')}
                />
                <Field
                    name="City"
                    value={[order.address?.postal_code, order.address?.city].filter(Boolean).join(' ')}
                />
                <Field name="Country" value={order.address?.country} />
            </div>
        </section>
    );

    const items = (
        <section className="mt-5 min-w-0 rounded-2xl bg-white p-6 shadow-sm">
            <h2 className="text-lg font-black">
                Items <span className="font-normal text-slate-400">({order.items.length})</span>
            </h2>
            <div className="mt-5 divide-y divide-slate-100">
                {order.items.map(item => (
                    <div
                        key={item.id}
                        className="grid min-w-0 gap-3 py-5
                            md:grid-cols-[minmax(0,1fr)_140px_100px_140px] md:items-center"
                    >
                        <div className="min-w-0">
                            <p className="truncate font-bold">{item.name}</p>
                            <p className="mt-1 truncate text-sm text-slate-500">
                                {item.variant_name || 'One size'}
                            </p>
                        </div>
                        <p className="text-sm text-slate-500">Qty {item.quantity}</p>
                        <p className="text-sm text-slate-500">
                            {money(item.unit_price_cents, order.currency)}
                        </p>
                        <p className="font-bold md:text-right">
                            {money(item.line_total_cents, order.currency)}
                        </p>
                    </div>
                ))}
            </div>
        </section>
    );

    return (
        <AdminLayout title={order.number}>
            {header}
            {form.errors.status && (
                <p className="mt-4 rounded-xl bg-red-100 px-4 py-3 text-sm font-semibold text-red-800">
                    {form.errors.status}
                </p>
            )}
            <div className="mt-8 grid gap-5 xl:grid-cols-2">
                {details}
                {customer}
            </div>
            {items}
        </AdminLayout>
    );
}
