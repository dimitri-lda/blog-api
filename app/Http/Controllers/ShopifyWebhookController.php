<?php

namespace App\Http\Controllers;

use App\Application\Payments\ShopPayService;
use App\Models\PaymentSession;
use App\Models\ShopifyWebhookDelivery;
use App\Services\ShopPay\ShopPayConfiguration;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Throwable;

class ShopifyWebhookController extends Controller
{
    public function __invoke(Request $request, ShopPayService $shopPay, ShopPayConfiguration $config): Response
    {
        $secret = (string) config('services.shop_pay.app_client_secret');
        if (! $config->enabled() || $secret === '') {
            return response('Shop Pay is not configured.', 503);
        }

        $payload = $request->getContent();
        $expected = base64_encode(hash_hmac('sha256', $payload, $secret, true));
        if (! hash_equals($expected, (string) $request->header('X-Shopify-Hmac-Sha256'))) {
            return response('Invalid signature.', 401);
        }

        $deliveryId = (string) $request->header('X-Shopify-Webhook-Id');
        $topic = (string) $request->header('X-Shopify-Topic');
        if (! Str::isUuid($deliveryId) || $topic === '') {
            return response('', 200);
        }
        $delivery = ShopifyWebhookDelivery::firstOrCreate(
            ['delivery_id' => $deliveryId],
            ['topic' => $topic],
        );
        if ($delivery->processed_at !== null) {
            return response('', 200);
        }

        try {
            $data = json_decode($payload, true);
            $source = is_array($data)
                ? ($data['source_identifier'] ?? $data['sourceIdentifier'] ?? null)
                : null;
            if (is_string($source) && str_starts_with($source, 'shop-pay:')) {
                $session = PaymentSession::where('source_identifier', $source)->first();
                if ($session) {
                    $shopPay->reconcile($session->order);
                }
            }
            $delivery->forceFill(['processed_at' => now()])->save();
        } catch (Throwable $exception) {
            if ($delivery->wasRecentlyCreated) {
                $delivery->delete();
            }
            throw $exception;
        }

        return response('', 200);
    }
}
