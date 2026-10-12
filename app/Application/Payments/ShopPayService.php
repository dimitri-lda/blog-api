<?php

namespace App\Application\Payments;

use App\Domain\Orders\ValueObjects\DeliveryMethod;
use App\Domain\Orders\ValueObjects\OrderStatus;
use App\Domain\Payments\PaymentAttemptStatus;
use App\Domain\Payments\PaymentSessionStatus;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentSession;
use App\Services\ShopPay\ShopifyAdminClient;
use App\Services\ShopPay\ShopPayConfiguration;
use App\Services\ShopPay\ShopPayPaymentRequestFactory;
use App\Services\ShopPay\ShopPayStorefrontClient;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ShopPayService
{
    private const SUBMISSION_LEASE_SECONDS = 30;

    public function __construct(
        private readonly ShopPayConfiguration $config,
        private readonly ShopPayStorefrontClient $storefront,
        private readonly ShopifyAdminClient $admin,
        private readonly ShopPayPaymentRequestFactory $requests,
    ) {}

    public function createSession(Order $order, string $buyerIp): array
    {
        $this->guardPayable($order);
        $session = $order->paymentSession;
        if ($session
            && in_array($session->status, [PaymentSessionStatus::Ready, PaymentSessionStatus::Processing], true)
            && $session->session_token
            && $session->checkout_url) {
            return [
                'token' => $session->session_token,
                'checkoutUrl' => $session->checkout_url,
                'sourceIdentifier' => $session->source_identifier,
                'paymentRequest' => $this->requests->frontend($order),
            ];
        }

        $result = $this->storefront->createSession(
            $this->requests->storefront($order),
            $this->sourceIdentifier($order),
            $buyerIp,
        );
        PaymentSession::updateOrCreate(
            ['order_id' => $order->id],
            [
                'provider' => 'shop_pay',
                'source_identifier' => $result['sourceIdentifier'],
                'session_token' => $result['token'],
                'session_token_hash' => hash('sha256', $result['token']),
                'checkout_url' => $result['checkoutUrl'],
                'status' => PaymentSessionStatus::Ready,
                'last_error' => null,
            ],
        );

        return [...$result, 'paymentRequest' => $this->requests->frontend($order)];
    }

    public function updateQuote(
        Order $order,
        string $sessionToken,
        array $shippingAddress,
        string $deliveryMethod,
    ): array {
        return DB::transaction(function () use ($order, $sessionToken, $shippingAddress, $deliveryMethod): array {
            $session = PaymentSession::where('order_id', $order->id)
                ->where('session_token_hash', hash('sha256', $sessionToken))
                ->lockForUpdate()
                ->first();
            if (! $session || $session->status !== PaymentSessionStatus::Ready) {
                throw new DomainException('Shop Pay session is no longer editable.');
            }

            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->guardPayable($order);
            $order->loadMissing('address');
            $country = strtoupper((string) ($shippingAddress['country'] ?? ''));
            if ($country === '' || $country !== strtoupper((string) $order->address?->country)) {
                throw new DomainException('This order can only be shipped to its original country.');
            }
            $method = DeliveryMethod::tryFrom($deliveryMethod)
                ?? throw new DomainException('Unsupported delivery method.');

            $order->address?->update([
                'first_name' => $shippingAddress['first_name'] ?? $order->address->first_name,
                'last_name' => $shippingAddress['last_name'] ?? $order->address->last_name,
                'line1' => $shippingAddress['line1'] ?? $order->address->line1,
                'line2' => $shippingAddress['line2'] ?? $order->address->line2,
                'city' => $shippingAddress['city'] ?? $order->address->city,
                'postal_code' => $shippingAddress['postal_code'] ?? $order->address->postal_code,
            ]);

            $delivery = $this->requests->deliveryQuote($order, $method);
            $order->forceFill([
                'delivery_method' => $method->value,
                'delivery_net_cents' => $delivery['net'],
                'delivery_tax_cents' => $delivery['tax'],
                'delivery_cents' => $delivery['gross'],
                'total_cents' => $order->subtotal_cents + $delivery['gross'],
            ])->save();

            return ['paymentRequest' => $this->requests->frontend($order->refresh())];
        });
    }

    public function submit(Order $order, string $sessionToken, string $paymentMethod, string $buyerIp): array
    {
        if ($paymentMethod === '' || mb_strlen($paymentMethod) > 10000) {
            throw new DomainException('Invalid payment method.');
        }
        $methodHash = hash_hmac('sha256', $paymentMethod, (string) config('app.key'));

        [$session, $attempt, $cachedStatus] = DB::transaction(
            function () use ($order, $sessionToken, $methodHash, $paymentMethod): array {
                $session = PaymentSession::where('order_id', $order->id)
                    ->where('session_token_hash', hash('sha256', $sessionToken))
                    ->lockForUpdate()
                    ->first();
                if (! $session) {
                    throw new DomainException('Shop Pay session mismatch.');
                }
                $attempt = PaymentAttempt::where('payment_session_id', $session->id)
                    ->where('payment_method_hash', $methodHash)
                    ->lockForUpdate()
                    ->first();
                if ($session->status === PaymentSessionStatus::Processing && ! $attempt) {
                    throw new DomainException('Shop Pay session is already processing a payment.');
                }
                if (! in_array(
                    $session->status,
                    [PaymentSessionStatus::Ready, PaymentSessionStatus::Processing],
                    true,
                )) {
                    throw new DomainException('Shop Pay session mismatch.');
                }
                if ($attempt?->status === PaymentAttemptStatus::Pending
                    && $attempt->updated_at?->gt(now()->subSeconds(self::SUBMISSION_LEASE_SECONDS))) {
                    throw new DomainException('This payment submission is already in progress.');
                }
                if ($attempt?->status === PaymentAttemptStatus::Completed) {
                    return [$session, $attempt, 'completed'];
                }
                if ($attempt?->status === PaymentAttemptStatus::Processing
                    && filled($attempt->receipt_token)) {
                    return [$session, $attempt, 'processing'];
                }

                $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $this->guardPayable($order);

                if (! $attempt) {
                    $attempt = PaymentAttempt::create([
                        'payment_session_id' => $session->id,
                        'payment_method_hash' => $methodHash,
                        'idempotency_key' => (string) Str::uuid(),
                        'amount_cents' => $order->total_cents,
                        'currency' => $order->currency,
                        'payload' => $this->requests->storefront($order, $paymentMethod),
                        'status' => PaymentAttemptStatus::Pending,
                    ]);
                } else {
                    // Failed or Processing without a receipt token: crash recovery.
                    $attempt->forceFill([
                        'status' => PaymentAttemptStatus::Pending,
                        'last_error' => null,
                    ])->save();
                }
                $session->forceFill([
                    'status' => PaymentSessionStatus::Processing,
                    'last_error' => null,
                ])->save();

                return [$session, $attempt, null];
            },
        );

        if ($cachedStatus !== null) {
            return ['status' => $cachedStatus];
        }

        try {
            $result = $this->storefront->submit(
                $sessionToken,
                $attempt->payload,
                (string) $attempt->idempotency_key,
                $order->number,
                $buyerIp,
            );
        } catch (DomainException $exception) {
            $attempt->forceFill([
                'status' => PaymentAttemptStatus::Failed,
                'last_error' => $exception->getMessage(),
            ])->save();
            $session->refresh();
            if ($session->status === PaymentSessionStatus::Processing) {
                $session->forceFill(['status' => PaymentSessionStatus::Ready])->save();
            }
            throw $exception;
        }

        $attempt->forceFill([
            'receipt_token' => $result['receiptToken'],
            'status' => PaymentAttemptStatus::Processing,
            'last_error' => null,
        ])->save();
        $session->forceFill(['status' => PaymentSessionStatus::Processing, 'last_error' => null])->save();

        return ['status' => $result['status']];
    }

    public function reconcile(Order $order): bool
    {
        if (! $this->config->enabled()) {
            return false;
        }
        if ($order->status === OrderStatus::Paid) {
            return true;
        }
        if ($order->status !== OrderStatus::PendingPayment) {
            return false;
        }
        $session = $order->paymentSession;
        if (! $session) {
            return false;
        }

        $receipt = $this->admin->receiptBySourceIdentifier($session->source_identifier);
        if (! $receipt || $receipt['state'] !== 'COMPLETED') {
            return false;
        }

        return DB::transaction(function () use ($order, $receipt): bool {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $session = PaymentSession::where('order_id', $order->id)->lockForUpdate()->first();
            if (! $session) {
                return false;
            }
            if ($order->status === OrderStatus::Paid) {
                return true;
            }
            if ($order->status !== OrderStatus::PendingPayment) {
                return false;
            }

            $attempt = $session->attempts->first(
                fn (PaymentAttempt $attempt) => is_string($attempt->receipt_token)
                    && hash_equals($attempt->receipt_token, (string) $receipt['token']),
            );
            if (! $attempt
                || $receipt['sourceIdentifier'] !== $session->source_identifier
                || $receipt['sourceIdentifier'] !== $this->sourceIdentifier($order)
                || $attempt->amount_cents === null
                || $attempt->currency === null
                || $receipt['total']['currencyCode'] !== $attempt->currency
                || $this->amountToCents($receipt['total']['amount']) !== $attempt->amount_cents) {
                $session->forceFill(['last_error' => 'Receipt does not match the local order.'])->save();

                return false;
            }

            $order->status = $order->status->transitionTo(OrderStatus::Paid);
            $order->save();
            $session->forceFill([
                'status' => PaymentSessionStatus::Paid,
                'provider_order_id' => $receipt['providerOrderId'],
                'receipt_token' => $receipt['token'],
                'paid_at' => now(),
                'last_error' => null,
            ])->save();
            $attempt->forceFill([
                'status' => PaymentAttemptStatus::Completed,
                'last_error' => null,
            ])->save();

            return true;
        });
    }

    private function guardPayable(Order $order): void
    {
        if (! $this->config->enabled()) {
            throw new DomainException('Shop Pay is not available.');
        }
        if ($order->status !== OrderStatus::PendingPayment) {
            throw new DomainException('This order is not awaiting payment.');
        }
    }

    private function sourceIdentifier(Order $order): string
    {
        return 'shop-pay:'.$order->number;
    }

    private function amountToCents(string $amount): ?int
    {
        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            return null;
        }
        if (strlen(ltrim($matches[1], '0')) > 15) {
            return null;
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }
}
