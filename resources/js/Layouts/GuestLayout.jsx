import ApplicationLogo from '@/Components/ApplicationLogo';
import { Link } from '@inertiajs/react';

export default function GuestLayout({ children }) {
    return <div className="flex min-h-screen flex-col bg-[#f7f8fa] text-slate-900"><header className="border-b bg-white px-6 py-5"><Link href="/" className="text-2xl font-black"><span className="text-blue-500">dao</span>Sport</Link></header><main className="flex flex-1 items-center justify-center px-5 py-12"><div className="w-full max-w-md rounded-3xl bg-white px-7 py-8 shadow-sm sm:px-10">{children}</div></main><footer className="py-6 text-center text-xs text-slate-400">© 2026 daoSport · Europe</footer></div>;
}
