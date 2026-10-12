<?php

namespace App\Application\Payments;

use App\Domain\Orders\ValueObjects\DeliveryMethod;
use App\Domain\Orders\ValueObjects\OrderStatus;
use App\Domain\Payments\PaymentAttemptStatus;
use App\Domain\Payments\PaymentSessionStatus;
use App\Infrastructure\Crypto\PaymentPayloadCodec;
use App\Infrastructure\Payments\ShopifyAdminClient;
use App\Infrastructure\Payments\ShopPayConfiguration;
use App\Infrastructure\Payments\ShopPayPaymentRequestFactory;
use App\Infrastructure\Payments\ShopPayStorefrontClient;
use App\Infrastructure\Persistence\Doctrine\Orders\DbalOrderReader;
use DateInterval;
use Doctrine\DBAL\Connection;
use DomainException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

class ShopPayService
{
    private const SUBMISSION_LEASE_SECONDS = 30;

    public function __construct(
        private readonly Connection $connection,
        private readonly ShopPayConfiguration $configuration,
        private readonly ShopPayStorefrontClient $storefront,
        private readonly ShopifyAdminClient $admin,
        private readonly ShopPayPaymentRequestFactory $requests,
        private readonly PaymentPayloadCodec $codec,
        private readonly DbalOrderReader $orders,
        private readonly ClockInterface $clock,
    ) {
    }

    public function createSession(array $order, string $buyerIp): array
    {
        $this->guardPayable($order);
        $session = $this->session((int) $order['id']);
        if (
            $session !== null
            && in_array(
                $session['status'],
                [PaymentSessionStatus::Ready->value, PaymentSessionStatus::Processing->value],
                true,
            )
            && $session['session_token'] !== null
            && $session['checkout_url'] !== null
        ) {
            return [
                'token' => $this->codec->decryptString(
                    $session['session_token'],
                    "payment_sessions.session_token:{$session['id']}",
                ),
                'checkoutUrl' => $session['checkout_url'],
                'sourceIdentifier' => $session['source_identifier'],
                'paymentRequest' => $this->requests->frontend($order),
            ];
        }

        $result = $this->storefront->createSession(
            $this->requests->storefront($order),
            $this->sourceIdentifier($order),
            $buyerIp,
        );
        $this->saveSession((int) $order['id'], $result);

        return [...$result, 'paymentRequest' => $this->requests->frontend($order)];
    }

    public function storefrontState(array $order): ?array
    {
        if (
            !$this->configuration->enabled()
            || $order['status'] !== OrderStatus::PendingPayment->value
        ) {
            return null;
        }
        $session = $this->session((int) $order['id']);

        return [
            'config' => $this->configuration->publicConfig(),
            'state' => $session['status'] ?? null,
            'payment_request' => $this->requests->frontend($order),
        ];
    }

    public function updateQuote(
        array $order,
        string $sessionToken,
        array $shippingAddress,
        string $deliveryMethod,
    ): array {
        return $this->connection->transactional(function () use (
            $order,
            $sessionToken,
            $shippingAddress,
            $deliveryMethod,
        ): array {
            $session = $this->connection->fetchAssociative(
                <<<'SQL'
SELECT *
FROM payment_sessions
WHERE order_id = :order_id AND session_token_hash = :token_hash
FOR UPDATE
SQL,
                [
                    'order_id' => (int) $order['id'],
                    'token_hash' => hash('sha256', $sessionToken),
                ],
            );
            if ($session === false || $session['status'] !== PaymentSessionStatus::Ready->value) {
                throw new DomainException('Shop Pay session is no longer editable.');
            }

            $locked = $this->connection->fetchAssociative(
                'SELECT * FROM orders WHERE id = :id FOR UPDATE',
                ['id' => (int) $order['id']],
            );
            if ($locked === false) {
                throw new DomainException('Order not found.');
            }
            $this->guardPayable($locked);
            $address = $this->connection->fetchAssociative(
                'SELECT * FROM order_addresses WHERE order_id = :order_id FOR UPDATE',
                ['order_id' => (int) $order['id']],
            );
            $country = strtoupper((string) ($shippingAddress['country'] ?? ''));
            if (
                $address === false
                || $country === ''
                || $country !== strtoupper((string) $address['country'])
            ) {
                throw new DomainException('This order can only be shipped to its original country.');
            }

            $method = DeliveryMethod::tryFrom($deliveryMethod)
                ?? throw new DomainException('Unsupported delivery method.');
            $this->connection->update(
                'order_addresses',
                [
                    'first_name' => $shippingAddress['first_name'] ?? $address['first_name'],
                    'last_name' => $shippingAddress['last_name'] ?? $address['last_name'],
                    'line1' => $shippingAddress['line1'] ?? $address['line1'],
                    'line2' => $shippingAddress['line2'] ?? $address['line2'],
                    'city' => $shippingAddress['city'] ?? $address['city'],
                    'postal_code' => $shippingAddress['postal_code'] ?? $address['postal_code'],
                    'updated_at' => $this->now(),
                ],
                ['id' => (int) $address['id']],
            );

            $loaded = $this->orders->byNumber((string) $order['number'])
                ?? throw new DomainException('Order not found.');
            $delivery = $this->requests->deliveryQuote($loaded, $method);
            $this->connection->update(
                'orders',
                [
                    'delivery_method' => $method->value,
                    'delivery_net_cents' => $delivery['net'],
                    'delivery_tax_cents' => $delivery['tax'],
                    'delivery_cents' => $delivery['gross'],
                    'total_cents' => (int) $locked['subtotal_cents'] + $delivery['gross'],
                    'updated_at' => $this->now(),
                ],
                ['id' => (int) $order['id']],
            );
            $updated = $this->orders->byNumber((string) $order['number'])
                ?? throw new DomainException('Order not found.');

            return ['paymentRequest' => $this->requests->frontend($updated)];
        });
    }

    public function submit(
        array $order,
        string $sessionToken,
        string $paymentMethod,
        string $buyerIp,
    ): array {
        if ($paymentMethod === '' || mb_strlen($paymentMethod) > 10000) {
            throw new DomainException('Invalid payment method.');
        }
        $methodHash = hash_hmac(
            'sha256',
            $paymentMethod,
            $this->configuration->paymentHashKey(),
        );

        [$session, $attempt, $cachedStatus] = $this->prepareAttempt(
            $order,
            $sessionToken,
            $paymentMethod,
            $methodHash,
        );
        if ($cachedStatus !== null) {
            return ['status' => $cachedStatus];
        }

        try {
            $result = $this->storefront->submit(
                $sessionToken,
                $this->codec->decryptArray(
                    $attempt['payload'],
                    "payment_attempts.payload:{$attempt['id']}",
                ),
                $attempt['idempotency_key'],
                $order['number'],
                $buyerIp,
            );
        } catch (DomainException $exception) {
            $this->connection->transactional(function () use ($attempt, $session, $exception): void {
                $this->connection->update(
                    'payment_attempts',
                    [
                        'status' => PaymentAttemptStatus::Failed->value,
                        'last_error' => $exception->getMessage(),
                        'updated_at' => $this->now(),
                    ],
                    ['id' => (int) $attempt['id']],
                );
                $this->connection->executeStatement(
                    <<<'SQL'
UPDATE payment_sessions
SET status = :ready, updated_at = :updated_at
WHERE id = :id AND status = :processing
SQL,
                    [
                        'ready' => PaymentSessionStatus::Ready->value,
                        'processing' => PaymentSessionStatus::Processing->value,
                        'updated_at' => $this->now(),
                        'id' => (int) $session['id'],
                    ],
                );
            });
            throw $exception;
        }

        $this->connection->transactional(function () use ($attempt, $session, $result): void {
            $this->connection->update(
                'payment_attempts',
                [
                    'receipt_token' => $this->codec->encryptString(
                        $result['receiptToken'],
                        "payment_attempts.receipt_token:{$attempt['id']}",
                    ),
                    'status' => PaymentAttemptStatus::Processing->value,
                    'last_error' => null,
                    'updated_at' => $this->now(),
                ],
                ['id' => (int) $attempt['id']],
            );
            $this->connection->update(
                'payment_sessions',
                [
                    'status' => PaymentSessionStatus::Processing->value,
                    'last_error' => null,
                    'updated_at' => $this->now(),
                ],
                ['id' => (int) $session['id']],
            );
        });

        return ['status' => $result['status']];
    }

    public function reconcile(array $order): bool
    {
        if (!$this->configuration->enabled()) {
            return false;
        }
        if ($order['status'] === OrderStatus::Paid->value) {
            return true;
        }
        if ($order['status'] !== OrderStatus::PendingPayment->value) {
            return false;
        }

        $session = $this->session((int) $order['id']);
        if ($session === null) {
            return false;
        }
        $receipt = $this->admin->receiptBySourceIdentifier($session['source_identifier']);
        if ($receipt === null || $receipt['state'] !== 'COMPLETED') {
            return false;
        }

        return $this->reconcileReceipt($order, $session, $receipt);
    }

    private function prepareAttempt(
        array $order,
        string $sessionToken,
        string $paymentMethod,
        string $methodHash,
    ): array {
        return $this->connection->transactional(function () use (
            $order,
            $sessionToken,
            $paymentMethod,
            $methodHash,
        ): array {
            $session = $this->connection->fetchAssociative(
                <<<'SQL'
SELECT *
FROM payment_sessions
WHERE order_id = :order_id AND session_token_hash = :token_hash
FOR UPDATE
SQL,
                [
                    'order_id' => (int) $order['id'],
                    'token_hash' => hash('sha256', $sessionToken),
                ],
            );
            if ($session === false) {
                throw new DomainException('Shop Pay session mismatch.');
            }
            $attempt = $this->connection->fetchAssociative(
                <<<'SQL'
SELECT *
FROM payment_attempts
WHERE payment_session_id = :session_id AND payment_method_hash = :method_hash
FOR UPDATE
SQL,
                [
                    'session_id' => (int) $session['id'],
                    'method_hash' => $methodHash,
                ],
            );
            if (
                $session['status'] === PaymentSessionStatus::Processing->value
                && $attempt === false
            ) {
                throw new DomainException('Shop Pay session is already processing a payment.');
            }
            if (!in_array(
                $session['status'],
                [PaymentSessionStatus::Ready->value, PaymentSessionStatus::Processing->value],
                true,
            )) {
                throw new DomainException('Shop Pay session mismatch.');
            }

            if ($attempt !== false && $this->leaseActive($attempt)) {
                throw new DomainException('This payment submission is already in progress.');
            }
            if ($attempt !== false && $attempt['status'] === PaymentAttemptStatus::Completed->value) {
                return [$session, $attempt, 'completed'];
            }
            if (
                $attempt !== false
                && $attempt['status'] === PaymentAttemptStatus::Processing->value
                && $attempt['receipt_token'] !== null
            ) {
                return [$session, $attempt, 'processing'];
            }

            $lockedOrder = $this->connection->fetchAssociative(
                'SELECT * FROM orders WHERE id = :id FOR UPDATE',
                ['id' => (int) $order['id']],
            );
            if ($lockedOrder === false) {
                throw new DomainException('Order not found.');
            }
            $this->guardPayable($lockedOrder);

            if ($attempt === false) {
                $attempt = $this->createAttempt($order, $session, $paymentMethod, $methodHash);
            } else {
                $this->connection->update(
                    'payment_attempts',
                    [
                        'status' => PaymentAttemptStatus::Pending->value,
                        'last_error' => null,
                        'updated_at' => $this->now(),
                    ],
                    ['id' => (int) $attempt['id']],
                );
                $attempt['status'] = PaymentAttemptStatus::Pending->value;
                $attempt['last_error'] = null;
                $attempt['updated_at'] = $this->now();
            }
            $this->connection->update(
                'payment_sessions',
                [
                    'status' => PaymentSessionStatus::Processing->value,
                    'last_error' => null,
                    'updated_at' => $this->now(),
                ],
                ['id' => (int) $session['id']],
            );
            $session['status'] = PaymentSessionStatus::Processing->value;

            return [$session, $attempt, null];
        });
    }

    private function createAttempt(
        array $order,
        array $session,
        string $paymentMethod,
        string $methodHash,
    ): array {
        $now = $this->now();
        $idempotencyKey = Uuid::v4()->toRfc4122();
        $id = (int) $this->connection->fetchOne(
            <<<'SQL'
INSERT INTO payment_attempts (
    payment_session_id,
    payment_method_hash,
    amount_cents,
    currency,
    idempotency_key,
    payload,
    status,
    created_at,
    updated_at
)
VALUES (
    :session_id,
    :method_hash,
    :amount_cents,
    :currency,
    :idempotency_key,
    '',
    :status,
    :created_at,
    :updated_at
)
RETURNING id
SQL,
            [
                'session_id' => (int) $session['id'],
                'method_hash' => $methodHash,
                'amount_cents' => (int) $order['total_cents'],
                'currency' => $order['currency'],
                'idempotency_key' => $idempotencyKey,
                'status' => PaymentAttemptStatus::Pending->value,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
        $payload = $this->codec->encryptArray(
            $this->requests->storefront($order, $paymentMethod),
            "payment_attempts.payload:{$id}",
        );
        $this->connection->update('payment_attempts', ['payload' => $payload], ['id' => $id]);

        return [
            'id' => $id,
            'payment_session_id' => (int) $session['id'],
            'payment_method_hash' => $methodHash,
            'amount_cents' => (int) $order['total_cents'],
            'currency' => $order['currency'],
            'idempotency_key' => $idempotencyKey,
            'payload' => $payload,
            'receipt_token' => null,
            'status' => PaymentAttemptStatus::Pending->value,
            'updated_at' => $now,
        ];
    }

    private function saveSession(int $orderId, array $result): void
    {
        $this->connection->transactional(function () use ($orderId, $result): void {
            $session = $this->connection->fetchAssociative(
                'SELECT * FROM payment_sessions WHERE order_id = :order_id FOR UPDATE',
                ['order_id' => $orderId],
            );
            $now = $this->now();
            if ($session === false) {
                $id = (int) $this->connection->fetchOne(
                    <<<'SQL'
INSERT INTO payment_sessions (
    order_id,
    provider,
    source_identifier,
    session_token_hash,
    checkout_url,
    status,
    created_at,
    updated_at
)
VALUES (
    :order_id,
    'shop_pay',
    :source_identifier,
    :token_hash,
    :checkout_url,
    :status,
    :created_at,
    :updated_at
)
RETURNING id
SQL,
                    [
                        'order_id' => $orderId,
                        'source_identifier' => $result['sourceIdentifier'],
                        'token_hash' => hash('sha256', $result['token']),
                        'checkout_url' => $result['checkoutUrl'],
                        'status' => PaymentSessionStatus::Ready->value,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            } else {
                $id = (int) $session['id'];
                $this->connection->update(
                    'payment_sessions',
                    [
                        'source_identifier' => $result['sourceIdentifier'],
                        'session_token_hash' => hash('sha256', $result['token']),
                        'checkout_url' => $result['checkoutUrl'],
                        'status' => PaymentSessionStatus::Ready->value,
                        'last_error' => null,
                        'updated_at' => $now,
                    ],
                    ['id' => $id],
                );
            }
            $this->connection->update(
                'payment_sessions',
                [
                    'session_token' => $this->codec->encryptString(
                        $result['token'],
                        "payment_sessions.session_token:{$id}",
                    ),
                ],
                ['id' => $id],
            );
        });
    }

    private function reconcileReceipt(array $order, array $session, array $receipt): bool
    {
        return $this->connection->transactional(function () use (
            $order,
            $session,
            $receipt,
        ): bool {
            $lockedOrder = $this->connection->fetchAssociative(
                'SELECT * FROM orders WHERE id = :id FOR UPDATE',
                ['id' => (int) $order['id']],
            );
            $lockedSession = $this->connection->fetchAssociative(
                'SELECT * FROM payment_sessions WHERE id = :id FOR UPDATE',
                ['id' => (int) $session['id']],
            );
            if ($lockedOrder === false || $lockedSession === false) {
                return false;
            }
            if ($lockedOrder['status'] === OrderStatus::Paid->value) {
                return true;
            }
            if ($lockedOrder['status'] !== OrderStatus::PendingPayment->value) {
                return false;
            }

            $attempt = $this->matchingAttempt((int) $session['id'], $receipt['token']);
            if (
                $attempt === null
                || $receipt['sourceIdentifier'] !== $session['source_identifier']
                || $receipt['sourceIdentifier'] !== $this->sourceIdentifier($order)
                || $attempt['amount_cents'] === null
                || $attempt['currency'] === null
                || $receipt['total']['currencyCode'] !== $attempt['currency']
                || $this->amountToCents($receipt['total']['amount'])
                    !== (int) $attempt['amount_cents']
            ) {
                $this->connection->update(
                    'payment_sessions',
                    [
                        'last_error' => 'Receipt does not match the local order.',
                        'updated_at' => $this->now(),
                    ],
                    ['id' => (int) $session['id']],
                );

                return false;
            }

            $now = $this->now();
            $this->connection->update(
                'orders',
                ['status' => OrderStatus::Paid->value, 'updated_at' => $now],
                ['id' => (int) $order['id']],
            );
            $this->connection->update(
                'payment_sessions',
                [
                    'status' => PaymentSessionStatus::Paid->value,
                    'provider_order_id' => $receipt['providerOrderId'],
                    'receipt_token' => $this->codec->encryptString(
                        $receipt['token'],
                        "payment_sessions.receipt_token:{$session['id']}",
                    ),
                    'paid_at' => $now,
                    'last_error' => null,
                    'updated_at' => $now,
                ],
                ['id' => (int) $session['id']],
            );
            $this->connection->update(
                'payment_attempts',
                [
                    'status' => PaymentAttemptStatus::Completed->value,
                    'last_error' => null,
                    'updated_at' => $now,
                ],
                ['id' => (int) $attempt['id']],
            );

            return true;
        });
    }

    private function matchingAttempt(int $sessionId, string $receiptToken): ?array
    {
        $attempts = $this->connection->fetchAllAssociative(
            'SELECT * FROM payment_attempts WHERE payment_session_id = :session_id FOR UPDATE',
            ['session_id' => $sessionId],
        );
        foreach ($attempts as $attempt) {
            if ($attempt['receipt_token'] === null) {
                continue;
            }
            $token = $this->codec->decryptString(
                $attempt['receipt_token'],
                "payment_attempts.receipt_token:{$attempt['id']}",
            );
            if (hash_equals($token, $receiptToken)) {
                return $attempt;
            }
        }

        return null;
    }

    private function session(int $orderId): ?array
    {
        return $this->connection->fetchAssociative(
            'SELECT * FROM payment_sessions WHERE order_id = :order_id',
            ['order_id' => $orderId],
        ) ?: null;
    }

    private function guardPayable(array $order): void
    {
        if (!$this->configuration->enabled()) {
            throw new DomainException('Shop Pay is not available.');
        }
        if ($order['status'] !== OrderStatus::PendingPayment->value) {
            throw new DomainException('This order is not awaiting payment.');
        }
    }

    private function leaseActive(array $attempt): bool
    {
        if ($attempt['status'] !== PaymentAttemptStatus::Pending->value) {
            return false;
        }
        $updatedAt = new \DateTimeImmutable((string) $attempt['updated_at']);

        return $updatedAt > $this->clock->now()->sub(
            new DateInterval('PT'.self::SUBMISSION_LEASE_SECONDS.'S'),
        );
    }

    private function sourceIdentifier(array $order): string
    {
        return 'shop-pay:'.$order['number'];
    }

    private function amountToCents(string $amount): ?int
    {
        if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $amount, $matches)) {
            return null;
        }
        if (strlen(ltrim($matches[1], '0')) > 15) {
            return null;
        }

        return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
