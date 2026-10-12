<?php

namespace App\Presentation\Console;

use App\Infrastructure\Payments\ShopPayConfiguration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

#[AsCommand(
    name: 'shop-pay:subscribe-webhooks',
    description: 'Create the Shopify orders/create webhook subscription.',
)]
final class SubscribeShopPayWebhookCommand extends Command
{
    private const MUTATION = <<<'GRAPHQL'
mutation webhookSubscriptionCreate(
    $topic: WebhookSubscriptionTopic!,
    $webhookSubscription: WebhookSubscriptionInput!
) {
    webhookSubscriptionCreate(
        topic: $topic,
        webhookSubscription: $webhookSubscription
    ) {
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

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly ShopPayConfiguration $configuration,
        private readonly string $callbackUrl,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->configuration->enabled()) {
            $output->writeln('<error>Shop Pay is not configured.</error>');

            return Command::FAILURE;
        }

        try {
            $response = $this->http->request('POST', sprintf(
                'https://%s/admin/api/%s/graphql.json',
                $this->configuration->shopDomain(),
                $this->configuration->apiVersion(),
            ), [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Shopify-Access-Token' => $this->configuration->adminAccessToken(),
                ],
                'json' => [
                    'query' => self::MUTATION,
                    'variables' => [
                        'topic' => 'ORDERS_CREATE',
                        'webhookSubscription' => [
                            'callbackUrl' => $this->callbackUrl,
                            'format' => 'JSON',
                        ],
                    ],
                ],
                'timeout' => 10,
            ]);
            $body = $response->toArray(false);
        } catch (Throwable) {
            $output->writeln('<error>Webhook subscription failed.</error>');

            return Command::FAILURE;
        }

        $payload = $body['data']['webhookSubscriptionCreate'] ?? null;
        $errors = is_array($payload) ? ($payload['userErrors'] ?? []) : [];
        $id = is_array($payload) ? ($payload['webhookSubscription']['id'] ?? null) : null;
        if ($response->getStatusCode() >= 300 || !empty($body['errors']) || $errors !== [] || !is_string($id)) {
            $output->writeln('<error>Webhook subscription failed.</error>');

            return Command::FAILURE;
        }
        $output->writeln("Subscribed to ORDERS_CREATE: {$id}");

        return Command::SUCCESS;
    }
}
