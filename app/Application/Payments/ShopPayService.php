<?php

namespace App\Application\Payments;

use App\Domain\Orders\ValueObjects\DeliveryMethod;
use App\Domain\Orders\ValueObjects\OrderStatus;
use App\Domain\Payments\PaymentAttemptStatus;
use App\Domain\Payments\PaymentSessionStatus;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentSession;
use App\Models\ProductVariant;
use App\Services\ShopPay\ShopifyAdminClient;
use App\Services\ShopPay\ShopPayConfiguration;
use App\Services\ShopPay\ShopPayPaymentRequestFactory;
use App\Services\ShopPay\ShopPayStorefrontClient;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ShopPayService
{
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

    public function updateQuote(Order $order, array $shippingAddress, string $deliveryMethod): array
    {
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
    }

    public function submit(Order $order, string $sessionToken, string $paymentMethod, string $buyerIp): array
    {
        $this->guardPayable($order);
        if ($paymentMethod === '' || mb_strlen($paymentMethod) > 10000) {
            throw new DomainException('Invalid payment method.');
        }
        $session = PaymentSession::where('order_id', $order->id)
            ->where('session_token_hash', hash('sha256', $sessionToken))
            ->first();
        if (! $session || ! in_array(
            $session->status,
            [PaymentSessionStatus::Ready, PaymentSessionStatus::Processing],
            true,
        )) {
            throw new DomainException('Shop Pay session mismatch.');
        }
        $this->guardStock($order);

        $methodHash = hash_hmac('sha256', $paymentMethod, (string) config('app.key'));
        $attempt = DB::transaction(fn () => PaymentAttempt::firstOrCreate(
            ['payment_session_id' => $session->id, 'payment_method_hash' => $methodHash],
            [
                'idempotency_key' => (string) Str::uuid(),
                'payload' => $this->requests->storefront($order, $paymentMethod),
                'status' => PaymentAttemptStatus::Pending,
            ],
        ));

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
        $session = $order->paymentSession;
        if (! $this->config->enabled() || ! $session) {
            return false;
        }
        if ($order->status === OrderStatus::Paid) {
            return true;
        }
        if ($order->status !== OrderStatus::PendingPayment) {
            return false;
        }

        $receipt = $this->admin->receiptBySourceIdentifier($session->source_identifier);
        if (! $receipt || $receipt['state'] !== 'COMPLETED') {
            return false;
        }
        if ($receipt['sourceIdentifier'] !== $session->source_identifier
            || $receipt['sourceIdentifier'] !== $this->sourceIdentifier($order)
            || $receipt['total']['currencyCode'] !== $order->currency
            || $this->amountToCents($receipt['total']['amount']) !== $order->total_cents) {
            $session->forceFill(['last_error' => 'Receipt does not match the local order.'])->save();

            return false;
        }

        DB::transaction(function () use ($order, $session, $receipt): void {
            $order->status = $order->status->transitionTo(OrderStatus::Paid);
            $order->save();
            $session->forceFill([
                'status' => PaymentSessionStatus::Paid,
                'provider_order_id' => $receipt['providerOrderId'],
                'receipt_token' => $receipt['token'],
                'paid_at' => now(),
                'last_error' => null,
            ])->save();
            foreach ($session->attempts as $attempt) {
                if (is_string($attempt->receipt_token) && hash_equals($attempt->receipt_token, $receipt['token'])) {
                    $attempt->forceFill([
                        'status' => PaymentAttemptStatus::Completed,
                        'last_error' => null,
                    ])->save();
                    break;
                }
            }
        });

        return true;
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

    private function guardStock(Order $order): void
    {
        $order->loadMissing('items');
        $variants = ProductVariant::with('product')
            ->whereIn('id', $order->items->pluck('product_variant_id')->filter()->all())
            ->get()
            ->keyBy('id');
        foreach ($order->items as $item) {
            $variant = $item->product_variant_id ? $variants->get($item->product_variant_id) : null;
            if (! $variant || ! $variant->product?->active) {
                throw new DomainException("{$item->name} is no longer available.");
            }
            if ($variant->stock < $item->quantity) {
                throw new DomainException("Not enough stock for {$item->name}.");
            }
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
