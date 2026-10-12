import { Link, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import AdminLayout from '@/layouts/AdminLayout';
import StatusSelect from '@/components/StatusSelect';
import { api, RequestError } from '@/api/client';
import AdminGuard from '@/pages/admin/AdminGuard';
import NotFound from '@/pages/NotFound';

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

export default function OrderShow() {
    const { id } = useParams();
    const queryClient = useQueryClient();
    const [status, setStatus] = useState('');
    const [statusError, setStatusError] = useState(null);
    const { data, error, isPending } = useQuery({
        queryKey: ['admin-order', id],
        queryFn: () => api.get(`/admin/orders/${id}`),
        retry: false,
    });
    const order = data?.data?.order;
    const nextStatuses = data?.data?.next_statuses ?? [];

    useEffect(() => {
        setStatus(nextStatuses[0] ?? '');
    }, [data]);

    const changeStatus = useMutation({
        mutationFn: next => api.patch(`/admin/orders/${id}/status`, { status: next }),
        onSuccess: () => {
            setStatusError(null);
            queryClient.invalidateQueries({ queryKey: ['admin-order', id] });
            queryClient.invalidateQueries({ queryKey: ['admin-orders'] });
        },
        onError: mutationError => {
            const errors = mutationError instanceof RequestError
                ? mutationError.payload?.errors
                : null;
            setStatusError(errors?.status?.[0] ?? 'Status change failed.');
        },
    });
    const submit = event => {
        event.preventDefault();
        changeStatus.mutate(status);
    };

    const header = (
        <div className="flex flex-col gap-5 md:flex-row md:items-start md:justify-between">
            <div>
                <Link
                    to="/admin/orders"
                    className="text-sm font-semibold text-slate-500 hover:text-slate-900"
                >
                    ← Orders
                </Link>
                <h1 className="mt-3 text-4xl font-black tracking-tight">{order?.number}</h1>
                <p className="mt-2 text-sm text-slate-500">
                    {order?.created_at ? new Date(order.created_at).toLocaleString() : ''}
                </p>
            </div>
            {nextStatuses.length > 0 && (
                <form
                    onSubmit={submit}
                    className="flex w-full flex-col gap-2 md:w-auto md:flex-row md:items-center"
                >
                    <StatusSelect
                        value={status}
                        onChange={setStatus}
                        options={nextStatuses}
                        label={label}
                        className="w-full md:w-48"
                    />
                    <button
                        disabled={changeStatus.isPending}
                        className="w-full rounded-xl bg-blue-400 px-5 py-3 text-sm font-bold
                            text-slate-950 hover:bg-blue-300 md:w-auto"
                    >
                        Change status
                    </button>
                </form>
            )}
        </div>
    );

    if (isPending) {
        return <AdminGuard><AdminLayout><main className="py-10" /></AdminLayout></AdminGuard>;
    }
    if (error instanceof RequestError && error.status === 404) {
        return <NotFound />;
    }
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
        <AdminGuard>
            <AdminLayout title={order.number}>
                {header}
                {statusError && (
                    <p className="mt-4 rounded-xl bg-red-100 px-4 py-3 text-sm font-semibold text-red-800">
                        {statusError}
                    </p>
                )}
                <div className="mt-8 grid gap-5 xl:grid-cols-2">
                    {details}
                    {customer}
                </div>
                {items}
            </AdminLayout>
        </AdminGuard>
    );
}
