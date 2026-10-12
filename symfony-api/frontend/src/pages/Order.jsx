import { Link, useParams } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useRef, useState } from 'react';
import StoreLayout from '@/layouts/StoreLayout';
import { api, RequestError } from '@/api/client';
import { money, useI18n } from '@/lib/i18n';
import NotFound from '@/pages/NotFound';

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
    const queryClient = useQueryClient();
    const containerRef = useRef(null);
    const [notice, setNotice] = useState(null);
    const paidRef = useRef(false);
    const errorText = error => (error instanceof RequestError
        ? (error.payload?.detail ?? error.payload?.title ?? t('shop_pay_failed'))
        : t('shop_pay_failed'));
    const payments = suffix => `/orders/${order.number}/payments/shop-pay/${suffix}`;

    useEffect(() => {
        if (!shopPay?.config || !containerRef.current) {
            return undefined;
        }
        let cancelled = false;
        let session = null;
        const listeners = [];
        const listen = (name, handler) => {
            if (!session?.addEventListener) {
                return;
            }
            session.addEventListener(name, handler);
            listeners.push([name, handler]);
        };

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
            const generalError = error => [
                { type: 'generalError', message: errorText(error) },
            ];
            session = window.ShopPay.PaymentRequest.createSession({
                paymentRequest: build(shopPay.payment_request),
            });
            const rebuilt = () => build({ ...(session.paymentRequest ?? shopPay.payment_request) });

            const onSessionRequested = () => {
                api.post(payments('session'))
                    .then(payload => {
                        if (cancelled) {
                            return;
                        }
                        const data = payload.data;
                        session.completeSessionRequest({
                            token: data.token,
                            checkoutUrl: data.checkoutUrl,
                            sourceIdentifier: data.sourceIdentifier,
                            updatedPaymentRequest: build(
                                data.paymentRequest ?? shopPay.payment_request,
                            ),
                        });
                    })
                    .catch(() => {
                        if (cancelled) {
                            return;
                        }
                        setNotice(t('shop_pay_failed'));
                        session.close();
                    });
            };
            const onDeliveryMethodTypeChanged = ev => {
                if (ev.deliveryMethodType === 'SHIPPING') {
                    session.completeDeliveryMethodTypeChange({ updatedPaymentRequest: rebuilt() });
                } else {
                    session.completeDeliveryMethodTypeChange({
                        errors: [{ type: 'generalError', message: t('shop_pay_shipping_only') }],
                    });
                }
            };
            const onShippingAddressChanged = ev => {
                api.patch(payments('quote'), {
                    session_token: session.token,
                    shipping_address: contact(ev.shippingAddress),
                    delivery_method:
                        session.paymentRequest?.shippingLines?.[0]?.code ?? order.delivery_method,
                }).then(payload => {
                    if (cancelled) {
                        return;
                    }
                    session.completeShippingAddressChange({
                        updatedPaymentRequest: build(payload.data.paymentRequest),
                    });
                }).catch(error => {
                    if (cancelled) {
                        return;
                    }
                    session.completeShippingAddressChange({
                        updatedPaymentRequest: build({
                            ...(session.paymentRequest ?? shopPay.payment_request),
                            deliveryMethods: [],
                        }),
                        errors: [{ type: 'shippingAddressError', message: errorText(error) }],
                    });
                });
            };
            const onDeliveryMethodChanged = ev => {
                api.patch(payments('quote'), {
                    session_token: session.token,
                    shipping_address: contact(session.paymentRequest?.shippingAddress),
                    delivery_method: ev.deliveryMethod?.code,
                }).then(payload => {
                    if (cancelled) {
                        return;
                    }
                    session.completeDeliveryMethodChange({
                        updatedPaymentRequest: build(payload.data.paymentRequest),
                    });
                }).catch(error => {
                    if (cancelled) {
                        return;
                    }
                    session.completeDeliveryMethodChange({ errors: generalError(error) });
                });
            };
            const onPaymentConfirmationRequested = () => {
                api.post(payments('submit'), {
                    session_token: session.token,
                    payment_method: session.paymentRequest?.paymentMethod ?? '',
                }).then(() => {
                    if (!cancelled) {
                        session.completePaymentConfirmationRequest();
                    }
                }).catch(error => {
                    if (cancelled) {
                        return;
                    }
                    session.completePaymentConfirmationRequest({ errors: generalError(error) });
                });
            };
            const onPaymentComplete = async () => {
                setNotice(t('shop_pay_confirming'));
                for (let attempt = 0; attempt < 6 && !cancelled; attempt += 1) {
                    try {
                        const result = await api.post(payments('reconcile'));
                        if (cancelled) {
                            return;
                        }
                        if (result.data.paid) {
                            paidRef.current = true;
                            session.close();
                            queryClient.invalidateQueries({ queryKey: ['order', order.number] });
                            return;
                        }
                    } catch {
                        if (!cancelled) {
                            setNotice(t('shop_pay_confirming'));
                        }
                    }
                    await new Promise(resolve => setTimeout(resolve, 2000));
                }
                if (!cancelled) {
                    setNotice(t('shop_pay_pending'));
                }
            };
            const onPaymentAttemptFailed = () => {
                setNotice(t('shop_pay_retry'));
            };
            const onWindowClosed = () => {
                if (!paidRef.current) {
                    setNotice(t('shop_pay_window_closed'));
                }
            };

            listen('sessionrequested', onSessionRequested);
            listen('deliverymethodtypechanged', onDeliveryMethodTypeChanged);
            listen('shippingaddresschanged', onShippingAddressChanged);
            listen('deliverymethodchanged', onDeliveryMethodChanged);
            listen('paymentconfirmationrequested', onPaymentConfirmationRequested);
            listen('paymentcomplete', onPaymentComplete);
            listen('paymentattemptfailed', onPaymentAttemptFailed);
            listen('windowclosed', onWindowClosed);
            window.ShopPay.PaymentRequest.createButton().render('#shop-pay-button-container');
        }).catch(() => {
            if (!cancelled) {
                setNotice(t('shop_pay_unavailable'));
            }
        });

        return () => {
            cancelled = true;
            if (session?.removeEventListener) {
                listeners.forEach(([name, handler]) => session.removeEventListener(name, handler));
            }
            session?.close?.();
            containerRef.current?.replaceChildren();
        };
    }, [order.number, shopPay?.config?.shopId, shopPay?.config?.clientId]);

    if (!shopPay?.config) {
        return <p className="mt-6 text-sm text-slate-500">{t('shop_pay_unavailable')}</p>;
    }

    return <div className="mt-6">
        <div id="shop-pay-button-container" ref={containerRef} className="flex justify-center" />
        {notice && <p className="mt-3 text-sm text-slate-500" role="status">{notice}</p>}
    </div>;
}

export default function Order() {
    const { number } = useParams();
    const t = useI18n();
    const { data, error, isPending } = useQuery({
        queryKey: ['order', number],
        queryFn: () => api.get(`/orders/${number}`),
        retry: false,
    });

    if (isPending) {
        return <StoreLayout title="daoSport"><main className="mx-auto max-w-3xl px-5 py-20" /></StoreLayout>;
    }
    if (error instanceof RequestError && error.status === 404) {
        return <NotFound />;
    }
    const order = data?.data;
    const shopPay = order.shop_pay;
    const locale = order.locale === 'pl' ? 'pl-PL' : order.locale === 'ru' ? 'ru-RU' : 'en-IE';
    const pending = order.status === 'pending_payment';
    const badgeClass = pending ? 'bg-amber-300' : 'bg-blue-400';
    const eyebrowClass = pending ? 'text-amber-600' : 'text-blue-600';

    return <StoreLayout title={`Order ${order.number}`}>
        <main className="mx-auto max-w-3xl px-5 py-20 text-center lg:px-8">
            <div className={`mx-auto flex h-16 w-16 items-center justify-center rounded-full
                text-3xl ${badgeClass}`}>
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
                        <span>
                            {money(order.net_subtotal_cents ?? order.subtotal_cents, order.currency, locale)}
                        </span>
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
                to="/shop"
                className="mt-8 inline-block rounded-full bg-slate-950 px-7 py-3 font-bold text-white"
            >
                {t('continue_shopping')} ↗
            </Link>
        </main>
    </StoreLayout>;
}
