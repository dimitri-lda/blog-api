import { Link, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import StoreLayout from '@/layouts/StoreLayout';
import ProductCard from '@/components/ProductCard';
import { api } from '@/api/client';
import { useI18n } from '@/lib/i18n';

function CategoryTree({ categories, parentId = null, filters, t, visited = [] }) {
    const children = categories.filter(
        category => category.parent_id === parentId && !visited.includes(category.id),
    );
    return children.map(category => {
        const active = filters.category === category.slug;
        const linkClass = [
            'block rounded-xl px-3 py-2 text-sm',
            active ? 'bg-slate-950 font-bold text-white' : 'hover:bg-slate-100',
        ].join(' ');
        return <li key={category.id}>
            <Link
                to={`/shop?${catalogParams({ ...filters, category: category.slug })}`}
                aria-current={active ? 'page' : undefined}
                className={linkClass}
            >
                {category.localized_name ?? category.name}{' '}
                <span
                    className="text-xs opacity-60"
                    aria-label={t('catalog.product_count', { count: category.products_count })}
                >
                    ({category.products_count})
                </span>
            </Link>
            <ul className="ml-3 border-l border-slate-200 pl-2">
                <CategoryTree
                    categories={categories}
                    parentId={category.id}
                    filters={filters}
                    t={t}
                    visited={[...visited, category.id]}
                />
            </ul>
        </li>;
    });
}

function catalogParams(filters) {
    const params = new URLSearchParams();
    ['q', 'category', 'sort', 'page'].forEach(key => {
        if (filters[key]) {
            params.set(key, filters[key]);
        }
    });
    return params.toString();
}

export default function Catalog() {
    const t = useI18n();
    const [searchParams, setSearchParams] = useSearchParams();
    const filters = {
        q: searchParams.get('q') ?? '',
        category: searchParams.get('category') ?? '',
        sort: searchParams.get('sort') ?? '',
        page: searchParams.get('page') ?? '1',
    };
    const { data: categoriesData } = useQuery({
        queryKey: ['categories'],
        queryFn: () => api.get('/categories'),
    });
    const { data: productsData } = useQuery({
        queryKey: ['products', filters],
        queryFn: () => api.get(`/products?${catalogParams(filters)}`),
    });
    const categories = categoriesData?.data ?? [];
    const products = productsData ?? { data: [], meta: { total: 0, last_page: 1, current_page: 1 } };
    const pages = Array.from({ length: products.meta.last_page }, (_, i) => i + 1);
    const allClass = [
        'mb-2 block rounded-xl px-3 py-2 text-sm',
        !filters.category ? 'bg-slate-950 text-white' : '',
    ].join(' ');
    const gridClass = 'grid grid-cols-2 gap-x-4 gap-y-10 md:grid-cols-3 xl:grid-cols-4';
    const pageLinkClass = active => [
        'rounded-full px-4 py-2 text-sm',
        active ? 'bg-slate-950 text-white' : 'bg-white',
    ].join(' ');
    const submit = event => {
        event.preventDefault();
        const values = new FormData(event.target);
        setSearchParams({
            ...(filters.category ? { category: filters.category } : {}),
            ...(values.get('q') ? { q: values.get('q') } : {}),
            ...(values.get('sort') ? { sort: values.get('sort') } : {}),
        });
    };

    return <StoreLayout title={t('catalog_title')}>
        <main className="mx-auto max-w-7xl px-5 py-12 lg:px-8">
            <div className="flex flex-col justify-between gap-5 lg:flex-row lg:items-end">
                <div>
                    <p className="text-xs font-bold uppercase tracking-[.25em] text-blue-600">
                        {t('catalog_eyebrow')}
                    </p>
                    <h1 className="mt-3 text-4xl font-black tracking-tight">{t('catalog_title')}</h1>
                    <p className="mt-2 text-sm text-slate-500">
                        {t('catalog_count', { count: products.meta.total })}
                    </p>
                </div>
                <form onSubmit={submit} className="flex flex-wrap gap-2">
                    <input
                        aria-label={t('catalog_search')}
                        name="q"
                        defaultValue={filters.q}
                        placeholder={t('catalog_search')}
                        className="min-w-0 rounded-full border-slate-200 bg-white px-5 py-2 text-sm"
                    />
                    <select
                        aria-label={t('catalog.sort')}
                        name="sort"
                        defaultValue={filters.sort}
                        className="rounded-full border-slate-200 bg-white px-4 py-2 text-sm"
                    >
                        <option value="">{t('catalog_latest')}</option>
                        <option value="price_asc">{t('catalog_price_asc')}</option>
                        <option value="price_desc">{t('catalog_price_desc')}</option>
                    </select>
                    <button className="rounded-full bg-slate-950 px-5 py-2 text-sm font-bold text-white">
                        {t('catalog_go')}
                    </button>
                </form>
            </div>
            <div className="mt-10 grid gap-8 lg:grid-cols-[230px_1fr]">
                <nav
                    aria-label={t('catalog.categories_aria')}
                    className="self-start rounded-2xl bg-white p-4"
                >
                    <h2 className="mb-3 font-bold">{t('catalog.categories_title')}</h2>
                    <Link
                        to={`/shop?${catalogParams({ q: filters.q, sort: filters.sort })}`}
                        className={allClass}
                    >
                        {t('catalog_all')}
                    </Link>
                    <ul>
                        <CategoryTree categories={categories} filters={filters} t={t} />
                    </ul>
                </nav>
                <div>
                    {products.data.length
                        ? <div className={gridClass}>
                            {products.data.map(product => <ProductCard key={product.id} product={product} />)}
                        </div>
                        : <div className="rounded-2xl bg-white p-10 text-center">
                            <p className="font-bold">{t('catalog.no_results')}</p>
                            <Link to="/shop" className="mt-4 inline-block text-blue-600">
                                {t('catalog.full_catalog')}
                            </Link>
                        </div>}
                    {pages.length > 1 && <nav
                        aria-label={t('catalog.pagination')}
                        className="mt-12 flex flex-wrap justify-center gap-2"
                    >
                        {pages.map(page => <Link
                            key={page}
                            to={`/shop?${catalogParams({ ...filters, page })}`}
                            aria-current={page === products.meta.current_page ? 'page' : undefined}
                            className={pageLinkClass(page === products.meta.current_page)}
                        >
                            {page}
                        </Link>)}
                    </nav>}
                </div>
            </div>
        </main>
    </StoreLayout>;
}
