<?php

namespace App\Console\Commands;

use App\Services\ShopPay\ShopPayConfiguration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

final class ShopPaySubscribeWebhooks extends Command
{
    protected $signature = 'shop-pay:subscribe-webhooks';

    protected $description = 'Create the Shopify orders/create webhook subscription used for Shop Pay reconciliation.';

    private const MUTATION = <<<'GRAPHQL'
        mutation webhookSubscriptionCreate(
            $topic: WebhookSubscriptionTopic!,
            $webhookSubscription: WebhookSubscriptionInput!
        ) {
            webhookSubscriptionCreate(topic: $topic, webhookSubscription: $webhookSubscription) {
                webhookSubscription {
                    id
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GRAPHQL;

    public function handle(ShopPayConfiguration $config): int
    {
        if (! $config->enabled()) {
            $this->error('Shop Pay is not configured.');

            return self::FAILURE;
        }
        $response = Http::timeout(10)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'X-Shopify-Access-Token' => $config->get('admin_access_token'),
            ])
            ->post(sprintf(
                'https://%s/admin/api/%s/graphql.json',
                $config->get('shop_domain'),
                $config->get('api_version') ?? '2026-07',
            ), [
                'query' => self::MUTATION,
                'variables' => [
                    'topic' => 'ORDERS_CREATE',
                    'webhookSubscription' => [
                        'callbackUrl' => route('webhooks.shopify.orders'),
                        'format' => 'JSON',
                    ],
                ],
            ]);
        $errors = $response->json('data.webhookSubscriptionCreate.userErrors') ?? [];
        if (! $response->successful() || $response->json('errors') || $errors !== []) {
            $this->error('Webhook subscription failed.');
            foreach ($errors as $error) {
                if (is_array($error) && is_string($error['message'] ?? null)) {
                    $this->error('- '.$error['message']);
                }
            }

            return self::FAILURE;
        }
        $this->info('Subscribed to ORDERS_CREATE: '
            .$response->json('data.webhookSubscriptionCreate.webhookSubscription.id'));

        return self::SUCCESS;
    }
}
