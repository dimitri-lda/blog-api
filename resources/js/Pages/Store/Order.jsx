import { Link } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import StoreLayout from '@/Layouts/StoreLayout';
import { money, useI18n } from '@/lib/i18n';

const SHOP_PAY_SDK_URL = 'https://cdn.shopify.com/shopifycloud/shop-js/shop-pay-payment-request.js';

function loadShopPaySdk() {
    if (window.ShopPay?.PaymentRequest) {
        return Promise.resolve();
    }
    const existing = document.head.querySelector(`script[src="${SHOP_PAY_SDK_URL}"]`);
    if (existing) {
        return new Promise((resolve, reject) => {
            existing.addEventListener('load', resolve, { once: true });
            existing.addEventListener('error', reject, { once: true });
        });
    }
    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = SHOP_PAY_SDK_URL;
        script.async = true;
        script.addEventListener('load', resolve, { once: true });
        script.addEventListener('error', reject, { once: true });
        document.head.appendChild(script);
    });
}

function xsrfToken() {
    return decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? '');
}

async function paymentApi(url, method, body = {}) {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify(body),
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        throw new Error(data.message || 'Request failed');
    }
    return data;
}

const contact = address => ({
    first_name: address?.firstName ?? '',
    last_name: address?.lastName ?? '',
    line1: address?.address1 ?? '',
    line2: address?.address2 ?? null,
    city: address?.city ?? '',
    postal_code: address?.postalCode ?? '',
    country: address?.countryCode ?? '',
});

function ShopPayPanel({ order, shopPay }) {
    const t = useI18n();
    const containerRef = useRef(null);
    const [notice, setNotice] = useState(null);
    const paidRef = useRef(false);

    useEffect(() => {
        if (!shopPay?.config || !containerRef.current) {
            return undefined;
        }
        let cancelled = false;
        loadShopPaySdk().then(() => {
            if (cancelled || !window.ShopPay?.PaymentRequest || !containerRef.current) {
                return;
            }
            window.ShopPay.PaymentRequest.configure({
                shopId: shopPay.config.shopId,
                clientId: shopPay.config.clientId,
                discountCodeField: false,
            });
            const build = request => window.ShopPay.PaymentRequest.build(request);
            const generalError = error => [{ type: 'generalError', message: error?.message || t('shop_pay_failed') }];
            const session = window.ShopPay.PaymentRequest.createSession({
                paymentRequest: build(shopPay.paymentRequest),
            });
            const rebuilt = () => build({ ...(session.paymentRequest ?? shopPay.paymentRequest) });

            session.addEventListener('sessionrequested', () => {
                paymentApi(route('store.order.shop-pay.session', order.number), 'POST')
                    .then(data => session.completeSessionRequest({
                        token: data.token,
                        checkoutUrl: data.checkoutUrl,
                        sourceIdentifier: data.sourceIdentifier,
                        updatedPaymentRequest: build(data.paymentRequest ?? shopPay.paymentRequest),
                    }))
                    .catch(() => {
                        setNotice(t('shop_pay_failed'));
                        session.close();
                    });
            });
            session.addEventListener('deliverymethodtypechanged', ev => {
                if (ev.deliveryMethodType === 'SHIPPING') {
                    session.completeDeliveryMethodTypeChange({ updatedPaymentRequest: rebuilt() });
                } else {
                    session.completeDeliveryMethodTypeChange({
                        errors: [{ type: 'generalError', message: t('shop_pay_shipping_only') }],
                    });
                }
            });
            session.addEventListener('shippingaddresschanged', ev => {
                paymentApi(route('store.order.shop-pay.quote', order.number), 'PATCH', {
                    shipping_address: contact(ev.shippingAddress),
                    delivery_method: session.paymentRequest?.shippingLines?.[0]?.code ?? order.delivery_method,
                }).then(data => session.completeShippingAddressChange({
                    updatedPaymentRequest: build(data.paymentRequest),
                })).catch(error => session.completeShippingAddressChange({
                    updatedPaymentRequest: build({
                        ...(session.paymentRequest ?? shopPay.paymentRequest),
                        deliveryMethods: [],
                    }),
                    errors: [{ type: 'shippingAddressError', message: error.message }],
                }));
            });
            session.addEventListener('deliverymethodchanged', ev => {
                paymentApi(route('store.order.shop-pay.quote', order.number), 'PATCH', {
                    shipping_address: contact(session.paymentRequest?.shippingAddress),
                    delivery_method: ev.deliveryMethod?.code,
                }).then(data => session.completeDeliveryMethodChange({
                    updatedPaymentRequest: build(data.paymentRequest),
                })).catch(error => session.completeDeliveryMethodChange({ errors: generalError(error) }));
            });
            session.addEventListener('paymentconfirmationrequested', () => {
                paymentApi(route('store.order.shop-pay.submit', order.number), 'POST', {
                    session_token: session.token,
                    payment_method: session.paymentRequest?.paymentMethod ?? '',
                }).then(() => session.completePaymentConfirmationRequest())
                    .catch(error => session.completePaymentConfirmationRequest({
                        errors: generalError(error),
                    }));
            });
            session.addEventListener('paymentcomplete', async () => {
                setNotice(t('shop_pay_confirming'));
                for (let attempt = 0; attempt < 6 && !cancelled; attempt += 1) {
                    try {
                        const result = await paymentApi(
                            route('store.order.shop-pay.reconcile', order.number), 'POST',
                        );
                        if (result.paid) {
                            paidRef.current = true;
                            session.close();
                            window.location.reload();
                            return;
                        }
                    } catch {
                        setNotice(t('shop_pay_confirming'));
                    }
                    await new Promise(resolve => setTimeout(resolve, 2000));
                }
                if (!cancelled) {
                    setNotice(t('shop_pay_pending'));
                }
            });
            session.addEventListener('paymentattemptfailed', () => {
                setNotice(t('shop_pay_retry'));
            });
            session.addEventListener('windowclosed', () => {
                if (!paidRef.current) {
                    setNotice(t('shop_pay_window_closed'));
                }
            });
            window.ShopPay.PaymentRequest.createButton().render('#shop-pay-button-container');
        }).catch(() => setNotice(t('shop_pay_unavailable')));

        return () => {
            cancelled = true;
        };
    }, [order.number]);

    if (!shopPay?.config) {
        return <p className="mt-6 text-sm text-slate-500">{t('shop_pay_unavailable')}</p>;
    }

    return <div className="mt-6">
        <div id="shop-pay-button-container" ref={containerRef} className="flex justify-center" />
        {notice && <p className="mt-3 text-sm text-slate-500" role="status">{notice}</p>}
    </div>;
}

export default function Order({ order, shopPay }) {
    const t = useI18n();
    const locale = order.locale === 'pl' ? 'pl-PL' : order.locale === 'ru' ? 'ru-RU' : 'en-IE';
    const pending = order.status === 'pending_payment';

    const badgeClass = pending ? 'bg-amber-300' : 'bg-blue-400';
    const eyebrowClass = pending ? 'text-amber-600' : 'text-blue-600';

    return <StoreLayout title={`Order ${order.number}`}>
        <main className="mx-auto max-w-3xl px-5 py-20 text-center lg:px-8">
            <div className={`mx-auto flex h-16 w-16 items-center justify-center rounded-full text-3xl ${badgeClass}`}>
                {pending ? '…' : '✓'}
            </div>
            <p className={`mt-7 text-xs font-bold uppercase tracking-[.25em] ${eyebrowClass}`}>
                {pending ? t('shop_pay_awaiting') : 'Order confirmed'}
            </p>
            <h1 className="mt-3 text-4xl font-black">
                {pending ? t('shop_pay_pending_title') : 'Thanks for moving with us.'}
            </h1>
            <p className="mt-4 text-slate-500">
                {pending
                    ? t('shop_pay_pending_copy', { number: order.number })
                    : <>Your order <strong>{order.number}</strong> is being prepared.
                        We sent confirmation to {order.email}.</>}
            </p>
            <div className="mx-auto mt-10 max-w-lg rounded-2xl bg-white p-6 text-left">
                <div className="space-y-3">
                    {order.items.map(item => <div key={item.id} className="flex justify-between text-sm">
                        <span>{item.name} × {item.quantity}</span>
                        <span className="font-bold">
                            {money(item.line_total_cents, order.currency, locale)}
                        </span>
                    </div>)}
                </div>
                <div className="mt-5 space-y-2 border-t pt-5 text-sm">
                    <div className="flex justify-between">
                        <span>{t('net')}</span>
                        <span>{money(order.net_subtotal_cents ?? order.subtotal_cents, order.currency, locale)}</span>
                    </div>
                    <div className="flex justify-between">
                        <span>{t('vat')}</span>
                        <span>{money(order.tax_cents ?? 0, order.currency, locale)}</span>
                    </div>
                    <div className="flex justify-between">
                        <span>{t('delivery')}</span>
                        <span>{money(order.delivery_cents, order.currency, locale)}</span>
                    </div>
                    <div className="flex justify-between text-lg font-black">
                        <span>{t('total')}</span>
                        <span>{money(order.total_cents, order.currency, locale)}</span>
                    </div>
                </div>
            </div>
            {pending && <ShopPayPanel order={order} shopPay={shopPay} />}
            <Link
                href={route('store.catalog')}
                className="mt-8 inline-block rounded-full bg-slate-950 px-7 py-3 font-bold text-white"
            >
                {t('continue_shopping')} ↗
            </Link>
        </main>
    </StoreLayout>;
}
