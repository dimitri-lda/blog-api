<?php

namespace App\Presentation\Http\Api\V1;

use App\Application\Payments\ShopPayService;
use App\Infrastructure\Payments\ShopPayConfiguration;
use App\Infrastructure\Persistence\Doctrine\Orders\DbalOrderReader;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;
use Throwable;

final readonly class ShopifyWebhookController
{
    public function __construct(
        private Connection $connection,
        private ShopPayConfiguration $configuration,
        private ShopPayService $payments,
        private DbalOrderReader $orders,
    ) {
    }

    #[Route('/api/v1/webhooks/shopify/orders', name: 'api.v1.webhooks.shopify.orders', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->configuration->enabled()) {
            return new Response('Shop Pay is not configured.', 503);
        }

        $payload = $request->getContent();
        $expected = base64_encode(hash_hmac(
            'sha256',
            $payload,
            $this->configuration->appClientSecret(),
            true,
        ));
        if (!hash_equals($expected, (string) $request->headers->get('X-Shopify-Hmac-Sha256'))) {
            return new Response('Invalid signature.', 401);
        }

        $deliveryId = (string) $request->headers->get('X-Shopify-Webhook-Id');
        $topic = (string) $request->headers->get('X-Shopify-Topic');
        if (!Uuid::isValid($deliveryId) || $topic === '') {
            return new Response('', 200);
        }
        $id = $this->connection->fetchOne(
            <<<'SQL'
INSERT INTO shopify_webhook_deliveries (
    delivery_id,
    topic,
    created_at,
    updated_at
)
VALUES (
    :delivery_id,
    :topic,
    :created_at,
    :updated_at
)
ON CONFLICT (delivery_id) DO NOTHING
RETURNING id
SQL,
            [
                'delivery_id' => $deliveryId,
                'topic' => $topic,
                'created_at' => $this->now(),
                'updated_at' => $this->now(),
            ],
        );
        if ($id === false) {
            return new Response('', 200);
        }

        try {
            $data = json_decode($payload, true);
            $source = is_array($data)
                ? ($data['source_identifier'] ?? $data['sourceIdentifier'] ?? null)
                : null;
            if (is_string($source) && str_starts_with($source, 'shop-pay:')) {
                $number = substr($source, strlen('shop-pay:'));
                $order = $this->orders->byNumber($number);
                if ($order !== null) {
                    $this->payments->reconcile($order);
                }
            }
            $this->connection->update(
                'shopify_webhook_deliveries',
                ['processed_at' => $this->now(), 'updated_at' => $this->now()],
                ['id' => (int) $id],
            );
        } catch (Throwable $exception) {
            $this->connection->delete('shopify_webhook_deliveries', ['id' => (int) $id]);
            throw $exception;
        }

        return new Response('', 200);
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
