import { Link } from 'react-router-dom';
import StoreLayout from '@/layouts/StoreLayout';
import { useI18n } from '@/lib/i18n';

export default function NotFound() {
    const t = useI18n();
    return <StoreLayout title="404">
        <main className="mx-auto max-w-3xl px-5 py-24 text-center lg:px-8">
            <p className="text-xs font-bold uppercase tracking-[.25em] text-blue-600">404</p>
            <h1 className="mt-3 text-4xl font-black tracking-tight">Page not found.</h1>
            <Link
                to="/shop"
                className="mt-8 inline-block rounded-full bg-slate-950 px-7 py-3 font-bold text-white"
            >
                {t('continue_shopping')} ↗
            </Link>
        </main>
    </StoreLayout>;
}
