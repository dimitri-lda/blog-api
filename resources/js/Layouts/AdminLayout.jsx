import { Head, Link, usePage } from '@inertiajs/react';

export default function AdminLayout({ children, title = 'Order management' }) {
    const { auth, flash } = usePage().props;

    return <div className="min-h-screen overflow-x-hidden bg-slate-100 text-slate-900">
        <Head title={title} />
        <div className="min-h-screen lg:grid lg:grid-cols-[248px_1fr]">
            <aside className="bg-slate-950 px-5 py-5 text-white lg:min-h-screen lg:px-6 lg:py-8">
                <div className="flex items-center justify-between lg:block">
                    <Link href={route('admin.orders.index')} className="text-xl font-black tracking-tight"><span className="text-blue-400">dao</span>Sport <span className="ml-1 text-xs font-semibold uppercase tracking-[.2em] text-slate-400">admin</span></Link>
                    <span className="rounded-full bg-slate-800 px-3 py-1 text-xs text-slate-300 lg:hidden">{auth.user.name}</span>
                </div>
                <nav className="mt-6 flex gap-2 lg:mt-12 lg:block lg:space-y-2">
                    <Link href={route('admin.orders.index')} className={`rounded-xl px-4 py-3 text-sm font-semibold transition ${route().current('admin.orders.*') ? 'bg-blue-400 text-slate-950' : 'text-slate-300 hover:bg-slate-800 hover:text-white'}`}>Orders</Link>
                </nav>
                <div className="mt-8 hidden border-t border-slate-800 pt-6 lg:block"><p className="font-semibold">{auth.user.name}</p><p className="mt-1 text-xs text-slate-400">{auth.user.email}</p><Link href={route('logout')} method="post" as="button" className="mt-5 text-sm font-semibold text-slate-300 hover:text-white">Log out</Link></div>
            </aside>
            <main className="min-w-0 px-5 py-7 sm:px-8 lg:px-12 lg:py-10">
                {flash?.success && <div className="mb-6 rounded-xl bg-blue-100 px-4 py-3 text-sm font-semibold text-blue-900">{flash.success}</div>}
                {flash?.error && <div className="mb-6 rounded-xl bg-red-100 px-4 py-3 text-sm font-semibold text-red-900">{flash.error}</div>}
                {children}
            </main>
        </div>
    </div>;
}
