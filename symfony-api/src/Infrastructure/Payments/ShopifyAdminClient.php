<?php

namespace App\Infrastructure\Payments;

use DomainException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

final readonly class ShopifyAdminClient
{
    private const RECEIPTS_QUERY = <<<'GRAPHQL'
query shopPayPaymentRequestReceipts($query: String!) {
    shopPayPaymentRequestReceipts(first: 1, query: $query) {
        edges {
            node {
                token
                sourceIdentifier
                processingStatus {
                    state
                    message
                    errorCode
                }
                order {
                    id
                    sourceIdentifier
                }
                paymentRequest {
                    total {
                        amount
                        currencyCode
                    }
                }
            }
        }
    }
}
GRAPHQL;

    public function __construct(
        private HttpClientInterface $http,
        private ShopPayConfiguration $configuration,
    ) {
    }

    public function receiptBySourceIdentifier(string $sourceIdentifier): ?array
    {
        for ($attempt = 1; $attempt <= 2; ++$attempt) {
            try {
                $response = $this->http->request('POST', $this->endpoint(), [
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'X-Shopify-Access-Token' => $this->configuration->adminAccessToken(),
                    ],
                    'json' => [
                        'query' => self::RECEIPTS_QUERY,
                        'variables' => [
                            'query' => "source_identifier:'"
                                .addcslashes($sourceIdentifier, "\\'")."'",
                        ],
                    ],
                    'timeout' => 10,
                ]);
                $status = $response->getStatusCode();
                $body = $response->toArray(false);
                if ($status >= 500 && $attempt < 2) {
                    usleep(100000);
                    continue;
                }
                break;
            } catch (Throwable) {
                if ($attempt === 2) {
                    throw new DomainException('Shopify admin API request failed.');
                }
                usleep(100000);
            }
        }
        if ($status < 200 || $status >= 300 || !empty($body['errors'])) {
            throw new DomainException('Shopify admin API request failed.');
        }

        $node = $body['data']['shopPayPaymentRequestReceipts']['edges'][0]['node'] ?? null;
        if (!is_array($node)) {
            return null;
        }
        $total = $node['paymentRequest']['total'] ?? null;

        return [
            'token' => is_string($node['token'] ?? null) ? $node['token'] : '',
            'sourceIdentifier' => is_string($node['sourceIdentifier'] ?? null)
                ? $node['sourceIdentifier']
                : '',
            'state' => is_string($node['processingStatus']['state'] ?? null)
                ? $node['processingStatus']['state']
                : '',
            'message' => is_string($node['processingStatus']['message'] ?? null)
                ? $node['processingStatus']['message']
                : null,
            'errorCode' => is_string($node['processingStatus']['errorCode'] ?? null)
                ? $node['processingStatus']['errorCode']
                : null,
            'providerOrderId' => is_string($node['order']['id'] ?? null)
                ? $node['order']['id']
                : null,
            'total' => [
                'amount' => isset($total['amount']) ? (string) $total['amount'] : '',
                'currencyCode' => is_string($total['currencyCode'] ?? null)
                    ? $total['currencyCode']
                    : '',
            ],
        ];
    }

    private function endpoint(): string
    {
        return sprintf(
            'https://%s/admin/api/%s/graphql.json',
            $this->configuration->shopDomain(),
            $this->configuration->apiVersion(),
        );
    }
}
