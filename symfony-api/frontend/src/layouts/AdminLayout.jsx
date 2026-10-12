import { Link } from 'react-router-dom';
import Head from '@/components/Head';
import { useApp } from '@/app/AppContext';
import { useLogout } from '@/lib/useLogout';

export default function AdminLayout({ children, title = 'Order management' }) {
    const { auth } = useApp();
    const logout = useLogout();

    return <div className="min-h-screen overflow-x-hidden bg-slate-100 text-slate-900">
        <Head title={title} />
        <div className="min-h-screen lg:grid lg:grid-cols-[248px_1fr]">
            <aside className="bg-slate-950 px-5 py-5 text-white lg:min-h-screen lg:px-6 lg:py-8">
                <div className="flex items-center justify-between lg:block">
                    <Link to="/admin/orders" className="text-xl font-black tracking-tight">
                        <span className="text-blue-400">dao</span>Sport
                        <span className="ml-1 text-xs font-semibold uppercase tracking-[.2em] text-slate-400">
                            admin
                        </span>
                    </Link>
                    <span className="rounded-full bg-slate-800 px-3 py-1 text-xs text-slate-300 lg:hidden">
                        {auth.user?.name}
                    </span>
                </div>
                <nav className="mt-6 flex gap-2 lg:mt-12 lg:block lg:space-y-2">
                    <Link
                        to="/admin/orders"
                        className="rounded-xl bg-blue-400 px-4 py-3 text-sm font-semibold text-slate-950
                            transition"
                    >
                        Orders
                    </Link>
                </nav>
                <div className="mt-8 hidden border-t border-slate-800 pt-6 lg:block">
                    <p className="font-semibold">{auth.user?.name}</p>
                    <p className="mt-1 text-xs text-slate-400">{auth.user?.email}</p>
                    <button
                        type="button"
                        onClick={() => logout.mutate()}
                        className="mt-5 text-sm font-semibold text-slate-300 hover:text-white"
                    >
                        Log out
                    </button>
                </div>
            </aside>
            <main className="min-w-0 px-5 py-7 sm:px-8 lg:px-12 lg:py-10">
                {children}
            </main>
        </div>
    </div>;
}
