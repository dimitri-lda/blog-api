<?php

namespace App\Services\ShopPay;

use DomainException;
use Illuminate\Support\Facades\Http;

final class ShopifyAdminClient
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

    public function __construct(private readonly ShopPayConfiguration $config) {}

    public function receiptBySourceIdentifier(string $sourceIdentifier): ?array
    {
        $response = Http::timeout(10)
            ->retry(2, 100)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'X-Shopify-Access-Token' => $this->config->get('admin_access_token'),
            ])
            ->post($this->endpoint(), [
                'query' => self::RECEIPTS_QUERY,
                'variables' => ['query' => "source_identifier:'".addcslashes($sourceIdentifier, "\\'")."'"],
            ]);

        $body = $response->json();
        if (! $response->successful() || ! is_array($body) || ! empty($body['errors'])) {
            throw new DomainException('Shopify admin API request failed.');
        }
        $node = $body['data']['shopPayPaymentRequestReceipts']['edges'][0]['node'] ?? null;
        if (! is_array($node)) {
            return null;
        }
        $total = $node['paymentRequest']['total'] ?? null;

        return [
            'token' => is_string($node['token'] ?? null) ? $node['token'] : '',
            'sourceIdentifier' => is_string($node['sourceIdentifier'] ?? null) ? $node['sourceIdentifier'] : '',
            'state' => is_string($node['processingStatus']['state'] ?? null) ? $node['processingStatus']['state'] : '',
            'message' => is_string($node['processingStatus']['message'] ?? null)
                ? $node['processingStatus']['message'] : null,
            'errorCode' => is_string($node['processingStatus']['errorCode'] ?? null)
                ? $node['processingStatus']['errorCode'] : null,
            'providerOrderId' => is_string($node['order']['id'] ?? null) ? $node['order']['id'] : null,
            'total' => [
                'amount' => isset($total['amount']) ? (string) $total['amount'] : '',
                'currencyCode' => is_string($total['currencyCode'] ?? null) ? $total['currencyCode'] : '',
            ],
        ];
    }

    private function endpoint(): string
    {
        return sprintf(
            'https://%s/admin/api/%s/graphql.json',
            $this->config->get('shop_domain'),
            $this->config->get('api_version') ?? '2026-07',
        );
    }
}
