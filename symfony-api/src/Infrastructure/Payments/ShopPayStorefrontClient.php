<?php

namespace App\Infrastructure\Payments;

use DomainException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final readonly class ShopPayStorefrontClient
{
    private const CREATE_MUTATION = <<<'GRAPHQL'
mutation shopPayPaymentRequestSessionCreate(
    $sourceIdentifier: String!,
    $paymentRequest: ShopPayPaymentRequestInput!
) {
    shopPayPaymentRequestSessionCreate(
        sourceIdentifier: $sourceIdentifier,
        paymentRequest: $paymentRequest
    ) {
        shopPayPaymentRequestSession {
            token
            sourceIdentifier
            checkoutUrl
        }
        userErrors {
            field
            message
        }
    }
}
GRAPHQL;

    private const SUBMIT_MUTATION = <<<'GRAPHQL'
mutation shopPayPaymentRequestSessionSubmit(
    $token: String!,
    $paymentRequest: ShopPayPaymentRequestInput!,
    $idempotencyKey: String!,
    $orderName: String
) {
    shopPayPaymentRequestSessionSubmit(
        token: $token,
        paymentRequest: $paymentRequest,
        idempotencyKey: $idempotencyKey,
        orderName: $orderName
    ) {
        paymentRequestReceipt {
            token
            processingStatusType
        }
        userErrors {
            field
            message
        }
    }
}
GRAPHQL;

    public function __construct(
        private HttpClientInterface $http,
        private ShopPayConfiguration $configuration,
    ) {
    }

    public function createSession(
        array $paymentRequest,
        string $sourceIdentifier,
        string $buyerIp,
    ): array {
        $payload = $this->call(
            self::CREATE_MUTATION,
            [
                'sourceIdentifier' => $sourceIdentifier,
                'paymentRequest' => $paymentRequest,
            ],
            $buyerIp,
            'shopPayPaymentRequestSessionCreate',
        );
        $session = $payload['shopPayPaymentRequestSession'] ?? null;
        if (
            !is_array($session)
            || !is_string($session['token'] ?? null)
            || !is_string($session['checkoutUrl'] ?? null)
            || !is_string($session['sourceIdentifier'] ?? null)
            || $session['sourceIdentifier'] !== $sourceIdentifier
        ) {
            throw new DomainException('Shop Pay returned an incomplete session.');
        }

        return [
            'token' => $session['token'],
            'checkoutUrl' => $session['checkoutUrl'],
            'sourceIdentifier' => $session['sourceIdentifier'],
        ];
    }

    public function submit(
        string $token,
        array $paymentRequest,
        string $idempotencyKey,
        string $orderName,
        string $buyerIp,
    ): array {
        $payload = $this->call(
            self::SUBMIT_MUTATION,
            [
                'token' => $token,
                'paymentRequest' => $paymentRequest,
                'idempotencyKey' => $idempotencyKey,
                'orderName' => $orderName,
            ],
            $buyerIp,
            'shopPayPaymentRequestSessionSubmit',
            true,
        );
        $receipt = $payload['paymentRequestReceipt'] ?? null;
        if (
            !is_array($receipt)
            || !is_string($receipt['token'] ?? null)
            || !is_string($receipt['processingStatusType'] ?? null)
        ) {
            throw new DomainException('Shop Pay returned an incomplete payment receipt.');
        }

        return [
            'receiptToken' => $receipt['token'],
            'status' => $receipt['processingStatusType'],
        ];
    }

    private function call(
        string $query,
        array $variables,
        string $buyerIp,
        string $operation,
        bool $retry = false,
    ): array {
        $attempts = $retry ? 2 : 1;
        for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
            try {
                $response = $this->http->request('POST', $this->endpoint(), [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'Shopify-Storefront-Private-Token' => $this->configuration
                            ->storefrontPrivateToken(),
                        'Shopify-Storefront-Buyer-IP' => $buyerIp,
                    ],
                    'json' => ['query' => $query, 'variables' => $variables],
                    'timeout' => 10,
                ]);
                $status = $response->getStatusCode();
                $body = $response->toArray(false);
                if ($status >= 500 && $attempt < $attempts) {
                    usleep(100000);
                    continue;
                }
                break;
            } catch (Throwable) {
                if ($attempt === $attempts) {
                    throw new DomainException('Shop Pay storefront API request failed.');
                }
                usleep(100000);
            }
        }

        if ($status < 200 || $status >= 300 || !empty($body['errors'])) {
            throw new DomainException('Shop Pay storefront API rejected the request.');
        }
        $payload = $body['data'][$operation] ?? null;
        if (!is_array($payload)) {
            throw new DomainException('Shop Pay storefront API returned an unexpected response.');
        }
        $userErrors = $payload['userErrors'] ?? [];
        if (!is_array($userErrors)) {
            throw new DomainException('Shop Pay storefront API returned an unexpected response.');
        }
        if ($userErrors !== []) {
            $messages = array_map(
                fn (array $error): string => is_string($error['message'] ?? null)
                    ? $error['message']
                    : 'unknown error',
                array_filter($userErrors, 'is_array'),
            );
            throw new DomainException(
                'Shop Pay could not process the request: '.implode('; ', $messages),
            );
        }

        return $payload;
    }

    private function endpoint(): string
    {
        return sprintf(
            'https://%s/api/%s/graphql.json',
            $this->configuration->shopDomain(),
            $this->configuration->apiVersion(),
        );
    }
}
