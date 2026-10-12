import { Link, useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import StoreLayout from '@/layouts/StoreLayout';
import ProductCard from '@/components/ProductCard';
import ProductImage from '@/components/ProductImage';
import { api, RequestError } from '@/api/client';
import { useApp } from '@/app/AppContext';
import { money, moneyLocale, useI18n } from '@/lib/i18n';
import NotFound from '@/pages/NotFound';

export default function Product() {
    const { slug } = useParams();
    const { market } = useApp();
    const queryClient = useQueryClient();
    const t = useI18n();
    const locale = moneyLocale(market);
    const { data, error, isPending } = useQuery({
        queryKey: ['product', slug],
        queryFn: () => api.get(`/products/${slug}`),
        retry: false,
    });
    const product = data?.data?.product;
    const breadcrumbs = data?.data?.breadcrumbs ?? [];
    const related = data?.data?.related ?? [];
    const [variantId, setVariantId] = useState(null);
    const [qty, setQty] = useState(1);
    const [selectedImage, setSelectedImage] = useState(0);
    const variant = product?.variants?.find(v => v.id === (variantId
        ?? product.variants.find(v => v.stock > 0)?.id));
    const addToCart = useMutation({
        mutationFn: body => api.post('/cart/items', body),
        onSuccess: response => queryClient.setQueryData(['cart'], response),
    });

    if (isPending) {
        return <StoreLayout title="daoSport"><main className="mx-auto max-w-7xl px-5 py-10" /></StoreLayout>;
    }
    if (error instanceof RequestError && error.status === 404) {
        return <NotFound />;
    }
    const shown = variant?.display_price ?? product.display_price;
    const maxQuantity = Math.min(20, variant?.stock ?? 0);
    const canBuy = product.available && maxQuantity > 0 && !product.pricing_error && !addToCart.isPending;
    const name = product.localized_name ?? product.name;
    const images = [
        ...(product.image_url ? [{ id: 'main', url: product.image_url, alt: name }] : []),
        ...(product.images ?? []).filter(image => image.url !== product.image_url),
    ];
    const image = images[selectedImage];
    const add = () => {
        if (!canBuy || !variant) {
            return;
        }
        addToCart.mutate({ variant_id: variant.id, quantity: Math.min(qty, maxQuantity) });
    };
    const thumbClass = index => [
        'w-20 overflow-hidden rounded-xl border-2',
        selectedImage === index ? 'border-blue-600' : 'border-transparent',
    ].join(' ');
    const variantClass = v => [
        'rounded-full border px-4 py-2 text-sm disabled:cursor-not-allowed disabled:opacity-40',
        variant?.id === v.id ? 'border-slate-950 bg-slate-950 text-white' : 'border-slate-200',
    ].join(' ');
    const buyButtonClass = [
        'flex-1 rounded-full bg-slate-950 px-5 py-3 font-bold text-white',
        'disabled:cursor-not-allowed disabled:bg-slate-300',
    ].join(' ');

    return <StoreLayout title={name}>
        <main className="mx-auto max-w-7xl px-5 py-10 lg:px-8">
            <nav
                aria-label={t('catalog.breadcrumb_label')}
                className="mb-7 flex flex-wrap gap-2 text-sm text-slate-500"
            >
                <Link to="/shop">{t('catalog.breadcrumb_catalog')}</Link>
                {breadcrumbs.map(category => <span key={category.id}>
                    {' / '}
                    <Link to={`/shop?category=${category.slug}`}>
                        {category.localized_name ?? category.name}
                    </Link>
                </span>)}
            </nav>
            <div className="grid gap-10 lg:grid-cols-2">
                <div>
                    <div className="overflow-hidden rounded-3xl bg-white">
                        <ProductImage
                            src={image?.url}
                            alt={image?.alt || name}
                            className="aspect-square w-full object-cover"
                        />
                    </div>
                    {images.length > 1 && <div
                        className="mt-4 flex flex-wrap gap-3"
                        role="group"
                        aria-label={t('catalog.product_images')}
                    >
                        {images.map((item, index) => <button
                            type="button"
                            key={item.id}
                            onClick={() => setSelectedImage(index)}
                            aria-label={t('catalog.show_image', { index: index + 1 })}
                            aria-pressed={selectedImage === index}
                            className={thumbClass(index)}
                        >
                            <ProductImage
                                src={item.url}
                                alt={item.alt || name}
                                className="aspect-square w-full object-cover"
                            />
                        </button>)}
                    </div>}
                </div>
                <div>
                    {product.brand_record && <p
                        className="text-sm font-semibold uppercase tracking-wider text-slate-500"
                    >
                        {product.brand_record.name}
                    </p>}
                    <h1 className="mt-2 text-4xl font-black tracking-tight sm:text-5xl">{name}</h1>
                    {product.manufacturer && <p className="mt-3 text-sm text-slate-600">
                        {t('catalog.manufacturer', { name: product.manufacturer.name })}
                    </p>}
                    <div className="mt-5 text-2xl font-bold">
                        {shown ? money(shown.gross_cents, shown.currency, locale)
                            : t('catalog.price_unavailable')}
                    </div>
                    <p className={`mt-3 font-semibold ${product.available ? 'text-green-700' : 'text-slate-500'}`}>
                        {product.available ? t('catalog.available') : t('catalog.unavailable')}
                    </p>
                    <p className="mt-7 whitespace-pre-line leading-7 text-slate-600">
                        {product.localized_description ?? product.description}
                    </p>
                    {product.variants.length > 0 && <div className="mt-8 border-t border-slate-200 pt-6">
                        <p className="text-sm font-bold">{t('catalog.choose_variant')}</p>
                        <div className="mt-3 flex flex-wrap gap-2">
                            {product.variants.map(v => <button
                                type="button"
                                key={v.id}
                                disabled={v.stock <= 0}
                                aria-pressed={variant?.id === v.id}
                                onClick={() => { setVariantId(v.id); setQty(1); }}
                                className={variantClass(v)}
                            >
                                {v.localized_name ?? v.name}
                                {v.stock <= 0 ? ` — ${t('catalog.variant_unavailable')}` : ''}
                            </button>)}
                        </div>
                    </div>}
                    <div className="mt-8 flex flex-wrap gap-3">
                        <div className="flex items-center rounded-full border border-slate-200 bg-white">
                            <button
                                type="button"
                                aria-label={t('catalog.decrease_quantity')}
                                disabled={!canBuy || qty <= 1}
                                onClick={() => setQty(q => Math.max(1, q - 1))}
                                className="px-4 py-3 disabled:opacity-30"
                            >
                                −
                            </button>
                            <span className="w-8 text-center text-sm font-bold">{qty}</span>
                            <button
                                type="button"
                                aria-label={t('catalog.increase_quantity')}
                                disabled={!canBuy || qty >= maxQuantity}
                                onClick={() => setQty(q => Math.min(maxQuantity, q + 1))}
                                className="px-4 py-3 disabled:opacity-30"
                            >
                                +
                            </button>
                        </div>
                        <button
                            type="button"
                            disabled={!canBuy}
                            onClick={add}
                            className={buyButtonClass}
                        >
                            {addToCart.isPending ? t('catalog.adding') : t('catalog.add_to_cart')}
                        </button>
                    </div>
                </div>
            </div>
            {product.specifications.length > 0 && <section className="mt-16 max-w-3xl">
                <h2 className="text-2xl font-black">{t('catalog.technical_parameters')}</h2>
                <dl className="mt-6 divide-y divide-slate-200">
                    {product.specifications.map(parameter => <div
                        key={parameter.id}
                        className="grid grid-cols-2 gap-4 py-4"
                    >
                        <dt className="font-semibold">{parameter.name}</dt>
                        <dd>
                            {parameter.value}{parameter.unit ? ` ${parameter.unit}` : ''}
                        </dd>
                    </div>)}
                </dl>
            </section>}
            {related.length > 0 && <section className="mt-24">
                <h2 className="text-2xl font-black">{t('catalog.related')}</h2>
                <div className="mt-7 grid grid-cols-2 gap-4 md:grid-cols-4">
                    {related.map(p => <ProductCard key={p.id} product={p} />)}
                </div>
            </section>}
        </main>
    </StoreLayout>;
}
