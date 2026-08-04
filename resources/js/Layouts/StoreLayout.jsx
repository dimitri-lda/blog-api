import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';

export default function StoreLayout({ children, title = 'Sportivo' }) {
    const { auth, flash } = usePage().props;
    const [open, setOpen] = useState(false);
    return <div className="min-h-screen bg-[#f7f8fa] text-slate-900"><Head title={title} />
        <div className="bg-slate-950 px-4 py-2 text-center text-xs font-medium tracking-wide text-white">Free shipping on orders over €50 · Easy 30-day returns</div>
        <header className="sticky top-0 z-40 border-b border-slate-200/80 bg-white/95 backdrop-blur"><div className="mx-auto flex max-w-7xl items-center gap-5 px-5 py-4 lg:px-8">
            <button onClick={() => setOpen(!open)} className="rounded-lg p-2 lg:hidden" aria-label="Open menu">☰</button><Link href={route('store.home')} className="text-2xl font-black tracking-tight"><span className="text-lime-500">sport</span>ivo</Link>
            <nav className={`${open ? 'absolute left-0 right-0 top-full flex' : 'hidden'} flex-col gap-4 border-b bg-white p-5 text-sm font-semibold lg:static lg:flex lg:flex-row lg:border-0 lg:bg-transparent lg:p-0`}><Link href={route('store.catalog')}>Shop all</Link><Link href={route('store.catalog', { category: 'running' })}>Running</Link><Link href={route('store.catalog', { category: 'fitness' })}>Fitness</Link><Link href={route('store.catalog', { category: 'tennis' })}>Racket sports</Link><Link href={route('store.catalog', { category: 'outdoor' })}>Outdoor</Link></nav>
            <form action={route('store.catalog')} className="ml-auto hidden max-w-xs flex-1 md:flex"><input name="q" placeholder="Search products, brands..." className="w-full rounded-full border-slate-200 bg-slate-50 px-5 py-2.5 text-sm focus:border-lime-500 focus:ring-lime-500" /></form>
            <div className="flex items-center gap-2 text-sm">
                <div className="group relative hidden sm:block">
                    <Link href={auth.user ? route('dashboard') : route('login')} className="flex items-center gap-2 rounded-full px-3 py-2 transition hover:bg-slate-100 focus:bg-slate-100 focus:outline-none" aria-label="Account menu">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="h-5 w-5" aria-hidden="true"><circle cx="12" cy="8" r="3.25" /><path strokeLinecap="round" d="M5 20c.8-3.15 3.2-5 7-5s6.2 1.85 7 5" /></svg>
                        <span className="hidden xl:inline">Account</span>
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.8" className="hidden h-3.5 w-3.5 xl:block" aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="m4 6 4 4 4-4" /></svg>
                    </Link>
                    <div className="invisible absolute right-0 top-full z-50 w-56 pt-2 opacity-0 transition duration-150 group-hover:visible group-hover:opacity-100 group-focus-within:visible group-focus-within:opacity-100">
                        <div className="rounded-2xl border border-slate-200 bg-white p-2 shadow-xl shadow-slate-900/10">
                            {auth.user ? <><p className="px-3 py-2 text-xs font-medium text-slate-500">Signed in as {auth.user.name}</p><Link href={route('dashboard')} className="block rounded-xl px-3 py-2 text-sm font-semibold hover:bg-slate-100">My account</Link><Link href={route('profile.edit')} className="block rounded-xl px-3 py-2 text-sm font-semibold hover:bg-slate-100">Profile settings</Link><Link href={route('logout')} method="post" as="button" className="mt-1 block w-full rounded-xl px-3 py-2 text-left text-sm font-semibold hover:bg-slate-100">Log out</Link></> : <><p className="px-3 py-2 text-xs font-medium text-slate-500">Have an account?</p><Link href={route('login')} className="block rounded-xl px-3 py-2 text-sm font-semibold hover:bg-slate-100">Log in</Link><Link href={route('register')} className="mt-1 block rounded-xl px-3 py-2 text-sm font-semibold hover:bg-slate-100">Create account</Link></>}
                        </div>
                    </div>
                </div>
                <div className="group relative">
                    <Link href={route('store.cart')} className="flex items-center gap-2 rounded-full bg-slate-950 px-3 py-2.5 font-semibold text-white transition hover:bg-slate-800 focus:bg-slate-800 focus:outline-none" aria-label="Shopping bag menu">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" className="h-5 w-5" aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="M5 8h14l-1 12H6L5 8Z" /><path strokeLinecap="round" d="M9 10V6a3 3 0 0 1 6 0v4" /></svg>
                        <span className="hidden sm:inline">Bag</span>
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" strokeWidth="1.8" className="h-3.5 w-3.5 text-lime-300" aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" d="m4 6 4 4 4-4" /></svg>
                    </Link>
                    <div className="invisible absolute right-0 top-full z-50 w-56 pt-2 opacity-0 transition duration-150 group-hover:visible group-hover:opacity-100 group-focus-within:visible group-focus-within:opacity-100">
                        <div className="rounded-2xl border border-slate-200 bg-white p-2 text-slate-900 shadow-xl shadow-slate-900/10">
                            <p className="px-3 py-2 text-xs font-medium text-slate-500">Ready to check out?</p>
                            <Link href={route('store.cart')} className="block rounded-xl px-3 py-2 text-sm font-semibold hover:bg-slate-100">View bag</Link>
                            <Link href={route('store.checkout')} className="mt-1 block rounded-xl px-3 py-2 text-sm font-semibold hover:bg-slate-100">Checkout</Link>
                        </div>
                    </div>
                </div>
            </div>
        </div></header>
        {flash?.success && <div className="mx-auto mt-4 max-w-7xl rounded-xl bg-lime-100 px-5 py-3 text-sm font-medium text-lime-900">{flash.success}</div>}{flash?.error && <div className="mx-auto mt-4 max-w-7xl rounded-xl bg-red-100 px-5 py-3 text-sm text-red-900">{flash.error}</div>}
        {children}<footer className="mt-20 bg-slate-950 text-white"><div className="mx-auto grid max-w-7xl gap-10 px-5 py-14 sm:grid-cols-2 lg:grid-cols-4 lg:px-8"><div><div className="text-2xl font-black"><span className="text-lime-400">sport</span>ivo</div><p className="mt-4 max-w-xs text-sm leading-6 text-slate-400">Equipment for every way you move. Curated gear, honest advice and fast delivery.</p></div><div><h3 className="font-bold">Shop</h3><div className="mt-4 space-y-3 text-sm text-slate-400"><Link href={route('store.catalog')} className="block">All products</Link><Link href={route('store.catalog', { category: 'running' })} className="block">Running</Link><Link href={route('store.catalog', { category: 'fitness' })} className="block">Fitness</Link></div></div><div><h3 className="font-bold">Help</h3><div className="mt-4 space-y-3 text-sm text-slate-400"><p>Delivery & returns</p><p>Contact us</p><p>Size guide</p></div></div><div><h3 className="font-bold">Stay in the loop</h3><p className="mt-4 text-sm text-slate-400">New drops and training tips, once a week.</p><div className="mt-4 flex"><input className="w-full rounded-l-lg border-0 bg-slate-800 text-sm" placeholder="Email address" /><button className="rounded-r-lg bg-lime-400 px-4 font-bold text-slate-950">→</button></div></div></div><div className="border-t border-slate-800 py-5 text-center text-xs text-slate-500">© 2026 Sportivo · EUR · Europe</div></footer></div>;
}
