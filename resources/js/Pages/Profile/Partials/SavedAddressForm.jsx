import InputError from '@/Components/InputError';
import { useForm } from '@inertiajs/react';

const countries = [
    ['PL', 'Poland'],
    ['DE', 'Germany'],
    ['FR', 'France'],
    ['NL', 'Netherlands'],
    ['CZ', 'Czechia'],
];

export default function SavedAddressForm({ address, user }) {
    const { data, setData, put, processing, errors, recentlySuccessful } = useForm({
        first_name: address?.first_name ?? user.name.split(' ')[0] ?? '',
        last_name: address?.last_name ?? user.name.split(' ').slice(1).join(' ') ?? '',
        line1: address?.line1 ?? '',
        line2: address?.line2 ?? '',
        city: address?.city ?? '',
        postal_code: address?.postal_code ?? '',
        country: address?.country ?? 'PL',
    });

    const field = (name, label, options = {}) => (
        <label className={options.full ? 'block sm:col-span-2' : 'block'}>
            <span className="text-sm font-medium text-slate-700">{label}</span>
            <input
                type={options.type ?? 'text'}
                value={data[name]}
                onChange={(event) => setData(name, event.target.value)}
                autoComplete={options.autoComplete}
                className="mt-2 block w-full rounded-xl border-slate-200 px-4 py-3 text-slate-900 shadow-none outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
            />
            <InputError className="mt-2" message={errors[name]} />
        </label>
    );

    const submit = (event) => {
        event.preventDefault();
        put(route('profile.address.update'), { preserveScroll: true });
    };

    return (
        <section>
            <header>
                <h2 className="text-xl font-black text-slate-950">Saved delivery address</h2>
                <p className="mt-2 text-sm leading-6 text-slate-500">
                    Use this address to make checkout faster next time.
                </p>
            </header>

            <form onSubmit={submit} className="mt-6">
                <div className="grid gap-4 sm:grid-cols-2">
                    {field('first_name', 'First name', { autoComplete: 'given-name' })}
                    {field('last_name', 'Last name', { autoComplete: 'family-name' })}
                    {field('line1', 'Address', { full: true, autoComplete: 'address-line1' })}
                    {field('line2', 'Apartment, suite (optional)', { full: true, autoComplete: 'address-line2' })}
                    {field('city', 'City', { autoComplete: 'address-level2' })}
                    {field('postal_code', 'Postal code', { autoComplete: 'postal-code' })}
                    <label className="block sm:col-span-2">
                        <span className="text-sm font-medium text-slate-700">Country</span>
                        <select
                            value={data.country}
                            onChange={(event) => setData('country', event.target.value)}
                            autoComplete="country"
                            className="mt-2 block w-full rounded-xl border-slate-200 bg-white px-4 py-3 text-slate-900 shadow-none outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200"
                        >
                            {countries.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                        </select>
                        <InputError className="mt-2" message={errors.country} />
                    </label>
                </div>

                <div className="mt-6 flex items-center gap-4">
                    <button disabled={processing} className="rounded-full bg-slate-950 px-5 py-3 text-sm font-bold text-white transition hover:bg-blue-600 disabled:cursor-not-allowed disabled:opacity-50">
                        Save address
                    </button>
                    {recentlySuccessful && <p className="text-sm font-medium text-blue-700">Address saved.</p>}
                </div>
            </form>
        </section>
    );
}
