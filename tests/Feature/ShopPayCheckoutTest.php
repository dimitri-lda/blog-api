<?php

namespace Tests\Feature;

use App\Domain\Payments\PaymentAttemptStatus;
use App\Models\Category;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentSession;
use App\Models\Product;
use App\Models\ShopifyWebhookDelivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ShopPayCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private const SHOP = 'https://shop.test';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->enableShopPay();
    }

    private function enableShopPay(): void
    {
        config()->set('services.shop_pay', [
            'enabled' => true,
            'shop_domain' => 'shop.test',
            'api_version' => '2026-07',
            'shop_id' => '12345',
            'client_id' => 'client-id-1',
            'storefront_private_token' => 'secret-storefront-token',
            'admin_access_token' => 'secret-admin-token',
            'app_client_secret' => 'secret-app-client',
        ]);
    }

    private function makeOrder(?User $user = null): array
    {
        $category = Category::create(['name' => 'Sport', 'slug' => 'sport-'.Str::random(6)]);
        $product = Product::create([
            'name' => 'Shoes', 'slug' => 'shoes-'.Str::random(6), 'description' => 'Description',
            'category_id' => $category->id, 'price_cents_net' => 10000, 'image_url' => '/shoe.jpg', 'active' => true,
        ]);
        $variant = $product->variants()->create(['name' => '42', 'sku' => 'SKU-'.Str::random(6), 'stock' => 5]);
        $order = Order::create([
            'user_id' => $user?->id,
            'number' => 'SP-'.Str::random(8),
            'email' => 'buyer@example.test', 'phone' => '123456789',
            'status' => 'pending_payment', 'delivery_method' => 'express',
            'subtotal_cents' => 11900, 'delivery_cents' => 1536, 'total_cents' => 13436,
            'currency' => 'EUR', 'market' => 'eu_global', 'locale' => 'en',
            'base_currency' => 'EUR', 'exchange_rate' => 1.0,
            'vat_rate_basis_points' => 1900, 'net_subtotal_cents' => 10000,
            'tax_cents' => 1900, 'delivery_net_cents' => 1290, 'delivery_tax_cents' => 246,
        ]);
        $order->address()->create([
            'first_name' => 'Test', 'last_name' => 'Buyer', 'line1' => 'Test 1',
            'city' => 'Berlin', 'postal_code' => '10115', 'country' => 'DE',
        ]);
        $order->items()->create([
            'product_variant_id' => $variant->id, 'name' => 'Shoes', 'variant_name' => '42',
            'unit_price_cents' => 11900, 'net_unit_price_cents' => 10000,
            'quantity' => 1, 'line_total_cents' => 11900, 'tax_cents' => 1900,
        ]);

        return [$order, $variant];
    }

    private function sessionResponse(Order $order, string $token = 'session-token-1'): array
    {
        return ['data' => ['shopPayPaymentRequestSessionCreate' => [
            'shopPayPaymentRequestSession' => [
                'token' => $token,
                'sourceIdentifier' => 'shop-pay:'.$order->number,
                'checkoutUrl' => self::SHOP.'/checkout/1/spe/'.$token,
            ],
            'userErrors' => [],
        ]]];
    }

    private function submitResponse(string $token = 'receipt-token-1'): array
    {
        return ['data' => ['shopPayPaymentRequestSessionSubmit' => [
            'paymentRequestReceipt' => ['token' => $token, 'processingStatusType' => 'ready'],
            'userErrors' => [],
        ]]];
    }

    private function receiptResponse(
        Order $order,
        string $state = 'COMPLETED',
        ?string $amount = null,
        string $currency = 'EUR',
        string $token = 'receipt-token-1',
    ): array {
        return ['data' => ['shopPayPaymentRequestReceipts' => ['edges' => [['node' => [
            'token' => $token,
            'sourceIdentifier' => 'shop-pay:'.$order->number,
            'processingStatus' => ['state' => $state, 'message' => null, 'errorCode' => null],
            'order' => ['id' => 'gid://shopify/Order/777', 'sourceIdentifier' => 'shop-pay:'.$order->number],
            'paymentRequest' => ['total' => [
                'amount' => $amount ?? number_format($order->total_cents / 100, 2, '.', ''),
                'currencyCode' => $currency,
            ]],
        ]]]]]];
    }

    private function fakeStorefront(Order $order): void
    {
        Http::fake(function ($request) use ($order) {
            if (str_contains($request->url(), '/admin/api/')) {
                return null;
            }

            return str_contains($request->body(), 'shopPayPaymentRequestSessionSubmit')
                ? Http::response($this->submitResponse())
                : Http::response($this->sessionResponse($order));
        });
    }

    private function guest(Order $order): self
    {
        return $this->withSession(['last_order' => $order->number]);
    }

    private function createSessionFor(Order $order): void
    {
        $this->guest($order)->postJson("/orders/{$order->number}/payments/shop-pay/session")->assertOk();
    }

    public function test_disabled_returns_503_and_no_public_config(): void
    {
        config()->set('services.shop_pay.enabled', false);
        [$order] = $this->makeOrder();

        $this->guest($order)->get("/orders/{$order->number}")
            ->assertInertia(fn (Assert $page) => $page->where('shopPay.config', null)->where('shopPay.state', null));
        $this->guest($order)->postJson("/orders/{$order->number}/payments/shop-pay/session")->assertStatus(503);
        $this->guest($order)->postJson("/orders/{$order->number}/payments/shop-pay/reconcile")->assertStatus(503);
        $this->assertDatabaseCount('payment_sessions', 0);
    }

    public function test_order_access_requires_owner_or_checkout_session(): void
    {
        [$order] = $this->makeOrder();
        $this->postJson("/orders/{$order->number}/payments/shop-pay/session")->assertNotFound();
        $this->get("/orders/{$order->number}")->assertNotFound();

        $owner = User::factory()->create();
        $foreigner = User::factory()->create();
        [$foreignOrder] = $this->makeOrder($owner);
        $this->fakeStorefront($foreignOrder);

        $this->actingAs($foreigner)->postJson("/orders/{$order->number}/payments/shop-pay/session")->assertNotFound();
        $this->actingAs($foreigner)
            ->postJson("/orders/{$foreignOrder->number}/payments/shop-pay/session")
            ->assertNotFound();

        $this->actingAs($owner)->postJson("/orders/{$foreignOrder->number}/payments/shop-pay/session")->assertOk();
    }

    public function test_create_session_hits_storefront_endpoint_with_secret_header_and_reuses_ready_session(): void
    {
        [$order] = $this->makeOrder();
        Http::fake(['*' => Http::response($this->sessionResponse($order))]);

        $response = $this->guest($order)->postJson("/orders/{$order->number}/payments/shop-pay/session");
        $response->assertOk()->assertJsonPath('token', 'session-token-1')
            ->assertJsonPath('sourceIdentifier', 'shop-pay:'.$order->number)
            ->assertJsonStructure(['paymentRequest' => ['lineItems', 'total', 'deliveryMethods']]);

        Http::assertSent(fn ($request) => $request->url() === self::SHOP.'/api/2026-07/graphql.json'
            && $request->hasHeader('Shopify-Storefront-Private-Token', 'secret-storefront-token')
            && $request->hasHeader('Shopify-Storefront-Buyer-IP'));
        $this->assertStringNotContainsString('secret-storefront-token', $response->getContent());

        $session = PaymentSession::where('order_id', $order->id)->sole();
        $this->assertSame('ready', $session->status->value);
        $this->assertSame(hash('sha256', 'session-token-1'), $session->session_token_hash);
        $this->assertNotSame('session-token-1', $session->getRawOriginal('session_token'));

        $this->guest($order)->postJson("/orders/{$order->number}/payments/shop-pay/session")
            ->assertOk()->assertJsonPath('token', 'session-token-1');
        Http::assertSentCount(1);
    }

    public function test_storefront_user_errors_return_422(): void
    {
        [$order] = $this->makeOrder();
        Http::fake(['*' => Http::response(['data' => ['shopPayPaymentRequestSessionCreate' => [
            'shopPayPaymentRequestSession' => null,
            'userErrors' => [['field' => ['paymentRequest'], 'message' => 'Total is invalid']],
        ]]])]);

        $this->guest($order)->postJson("/orders/{$order->number}/payments/shop-pay/session")->assertStatus(422);
        $this->assertDatabaseCount('payment_sessions', 0);
    }

    public function test_storefront_transport_failures_return_controlled_422(): void
    {
        [$order] = $this->makeOrder();
        $url = "/orders/{$order->number}/payments/shop-pay/session";

        foreach ([500, 429] as $status) {
            Http::fake(['*' => Http::response(['errors' => [['message' => 'Shopify exploded']]], $status)]);
            $this->guest($order)->postJson($url)
                ->assertStatus(422)
                ->assertJsonPath('message', 'Shop Pay storefront API request failed.');
        }

        Http::fake(fn () => throw new ConnectionException('Connection refused'));
        $this->guest($order)->postJson($url)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Shop Pay storefront API request failed.');

        $this->assertStringNotContainsString('Shopify exploded', $this->guest($order)->postJson($url)->getContent());
        $this->assertDatabaseCount('payment_sessions', 0);
    }

    public function test_quote_allows_same_country_and_rejects_country_change(): void
    {
        [$order] = $this->makeOrder();
        $this->fakeStorefront($order);
        $this->createSessionFor($order);
        $address = [
            'first_name' => 'Test', 'last_name' => 'Buyer', 'line1' => 'New 5',
            'city' => 'Munich', 'postal_code' => '80331', 'country' => 'DE',
        ];

        $response = $this->guest($order)->patchJson("/orders/{$order->number}/payments/shop-pay/quote", [
            'session_token' => 'session-token-1',
            'shipping_address' => $address,
            'delivery_method' => 'standard',
        ]);
        $response->assertOk()->assertJsonStructure(['paymentRequest' => ['total', 'deliveryMethods']]);
        $order->refresh();
        $this->assertSame('standard', $order->delivery_method);
        $this->assertSame(0, $order->delivery_cents);
        $this->assertSame(11900, $order->total_cents);
        $this->assertSame('Munich', $order->address->city);

        $this->guest($order)->patchJson("/orders/{$order->number}/payments/shop-pay/quote", [
            'session_token' => 'session-token-1',
            'shipping_address' => [...$address, 'country' => 'FR'],
            'delivery_method' => 'standard',
        ])->assertStatus(422);
        $this->guest($order)->patchJson("/orders/{$order->number}/payments/shop-pay/quote", [
            'session_token' => 'session-token-1',
            'shipping_address' => $address,
            'delivery_method' => 'pickup',
        ])->assertStatus(422)->assertJsonValidationErrors('delivery_method');
        $this->assertSame('DE', $order->address->fresh()->country);
        $this->assertSame('EUR', $order->fresh()->currency);

        $this->guest($order)->patchJson("/orders/{$order->number}/payments/shop-pay/quote", [
            'session_token' => 'session-token-1',
            'shipping_address' => [...$address, 'first_name' => null, 'postal_code' => null],
            'delivery_method' => 'standard',
        ])->assertOk();
        $order->address->refresh();
        $this->assertSame('Test', $order->address->first_name);
        $this->assertSame('80331', $order->address->postal_code);
    }

    public function test_quote_requires_a_valid_ready_session(): void
    {
        [$order] = $this->makeOrder();
        $this->fakeStorefront($order);
        $this->createSessionFor($order);
        $payload = [
            'shipping_address' => [
                'first_name' => 'Test', 'last_name' => 'Buyer', 'line1' => 'New 5',
                'city' => 'Munich', 'postal_code' => '80331', 'country' => 'DE',
            ],
            'delivery_method' => 'standard',
        ];

        $this->guest($order)->patchJson("/orders/{$order->number}/payments/shop-pay/quote", $payload)
            ->assertStatus(422)->assertJsonValidationErrors('session_token');
        $this->guest($order)->patchJson(
            "/orders/{$order->number}/payments/shop-pay/quote",
            [...$payload, 'session_token' => 'forged-token'],
        )->assertStatus(422);
        $this->assertSame(1536, $order->fresh()->delivery_cents);
    }

    public function test_create_session_reuses_processing_session(): void
    {
        [$order] = $this->makeOrder();
        Http::fake(['*' => Http::response($this->sessionResponse($order))]);
        $this->createSessionFor($order);
        PaymentSession::sole()->forceFill(['status' => 'processing'])->save();

        $this->guest($order)->postJson("/orders/{$order->number}/payments/shop-pay/session")
            ->assertOk()->assertJsonPath('token', 'session-token-1');
        Http::assertSentCount(1);
    }

    public function test_submit_rejects_session_mismatch_and_never_accepts_client_totals(): void
    {
        [$order] = $this->makeOrder();
        $this->fakeStorefront($order);
        $this->createSessionFor($order);

        $this->guest($order)->postJson("/orders/{$order->number}/payments/shop-pay/submit", [
            'session_token' => 'forged-token',
            'payment_method' => 'pm-1',
        ])->assertStatus(422);

        $this->guest($order)->postJson("/orders/{$order->number}/payments/shop-pay/submit", [
            'session_token' => 'session-token-1',
            'payment_method' => 'pm-1',
            'total' => 1,
        ])->assertOk();

        $submit = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->body(), 'shopPayPaymentRequestSessionSubmit'))
            ->values()->last();
        $this->assertSame('134.36', $submit[0]->data()['variables']['paymentRequest']['total']['amount']);
    }

    public function test_submit_persists_idempotent_attempt_and_never_marks_paid(): void
    {
        [$order] = $this->makeOrder();
        $this->fakeStorefront($order);
        $this->createSessionFor($order);

        $url = "/orders/{$order->number}/payments/shop-pay/submit";
        $this->guest($order)->postJson($url, [
            'session_token' => 'session-token-1', 'payment_method' => 'pm-card-1',
        ])->assertOk();
        $this->guest($order)->postJson($url, [
            'session_token' => 'session-token-1', 'payment_method' => 'pm-card-1',
        ])->assertOk();
        $this->guest($order)->postJson($url, [
            'session_token' => 'session-token-1', 'payment_method' => 'pm-card-2',
        ])->assertStatus(422);

        $attempts = PaymentAttempt::orderBy('id')->get();
        $this->assertCount(1, $attempts);
        $this->assertSame(13436, $attempts->first()->amount_cents);
        $this->assertSame('EUR', $attempts->first()->currency);
        $submits = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->body(), 'shopPayPaymentRequestSessionSubmit'))
            ->values();
        $this->assertCount(1, $submits);
        $this->assertSame($attempts->first()->idempotency_key, $submits[0][0]->data()['variables']['idempotencyKey']);
        $this->assertSame('processing', $attempts->first()->status->value);
        $this->assertSame('processing', PaymentSession::sole()->status->value);
        $this->assertSame('pending_payment', $order->fresh()->status->value);
    }

    public function test_failed_submit_marks_attempt_failed_and_releases_session_for_retry(): void
    {
        [$order] = $this->makeOrder();
        Http::fake([self::SHOP.'/api/2026-07/graphql.json' => Http::sequence()
            ->push($this->sessionResponse($order))
            ->push(['errors' => [['message' => 'Shopify exploded']]], 500)
            ->push(['errors' => [['message' => 'Shopify exploded']]], 500)
            ->push($this->submitResponse())]);
        $this->createSessionFor($order);
        $payload = ['session_token' => 'session-token-1', 'payment_method' => 'pm-card-1'];
        $url = "/orders/{$order->number}/payments/shop-pay/submit";

        $this->guest($order)->postJson($url, $payload)->assertStatus(422)
            ->assertJsonPath('message', 'Shop Pay storefront API request failed.');
        $this->assertSame('ready', PaymentSession::sole()->status->value);
        $attempt = PaymentAttempt::sole();
        $this->assertSame('failed', $attempt->status->value);
        $this->assertSame('Shop Pay storefront API request failed.', $attempt->last_error);

        $this->guest($order)->postJson($url, $payload)->assertOk();
        $this->assertSame('processing', PaymentSession::sole()->status->value);
        $this->assertSame('processing', $attempt->fresh()->status->value);
        $this->assertSame('receipt-token-1', $attempt->fresh()->receipt_token);
        $submits = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->body(), 'shopPayPaymentRequestSessionSubmit'))
            ->values();
        $this->assertCount(3, $submits);
        foreach ($submits as $submit) {
            $this->assertSame($attempt->idempotency_key, $submit[0]->data()['variables']['idempotencyKey']);
        }
    }

    public function test_stale_pending_attempt_is_recovered_with_same_idempotency_key(): void
    {
        [$order] = $this->makeOrder();
        $this->fakeStorefront($order);
        $this->createSessionFor($order);
        $url = "/orders/{$order->number}/payments/shop-pay/submit";
        $payload = ['session_token' => 'session-token-1', 'payment_method' => 'pm-card-1'];
        $submitMutation = fn ($pair) => str_contains(
            $pair[0]->body(), 'shopPayPaymentRequestSessionSubmit',
        );

        $this->guest($order)->postJson($url, $payload)->assertOk();
        $attempt = PaymentAttempt::sole();
        $attempt->forceFill([
            'status' => PaymentAttemptStatus::Pending,
            'receipt_token' => null,
        ])->save();

        $this->guest($order)->postJson($url, $payload)->assertStatus(422)
            ->assertJsonPath('message', 'This payment submission is already in progress.');
        $this->assertCount(1, collect(Http::recorded())->filter($submitMutation));

        DB::table('payment_attempts')
            ->where('id', $attempt->id)
            ->update(['updated_at' => now()->subSeconds(60)]);

        $this->guest($order)->postJson($url, $payload)->assertOk();
        $this->assertSame('processing', $attempt->fresh()->status->value);
        $this->assertSame('receipt-token-1', $attempt->fresh()->receipt_token);
        $submits = collect(Http::recorded())->filter($submitMutation)->values();
        $this->assertCount(2, $submits);
        foreach ($submits as $submit) {
            $this->assertSame(
                $attempt->idempotency_key,
                $submit[0]->data()['variables']['idempotencyKey'],
            );
        }
    }

    public function test_quote_is_rejected_after_submit_and_order_economics_stay_frozen(): void
    {
        [$order] = $this->makeOrder();
        Http::fake(['*/admin/api/*' => Http::response($this->receiptResponse($order))]);
        $this->fakeStorefront($order);
        $this->createSessionFor($order);
        $this->guest($order)->postJson("/orders/{$order->number}/payments/shop-pay/submit", [
            'session_token' => 'session-token-1', 'payment_method' => 'pm-1',
        ])->assertOk();

        $this->guest($order)->patchJson("/orders/{$order->number}/payments/shop-pay/quote", [
            'session_token' => 'session-token-1',
            'shipping_address' => [
                'first_name' => 'Changed', 'last_name' => 'Buyer', 'line1' => 'Other 9',
                'city' => 'Hamburg', 'postal_code' => '20095', 'country' => 'DE',
            ],
            'delivery_method' => 'standard',
        ])->assertStatus(422);

        $order->refresh();
        $this->assertSame(13436, $order->total_cents);
        $this->assertSame('express', $order->delivery_method);
        $this->assertSame('Berlin', $order->address->city);
        $this->assertSame('Test 1', $order->address->line1);

        $this->guest($order)->postJson("/orders/{$order->number}/payments/shop-pay/reconcile")
            ->assertOk()->assertJsonPath('paid', true);
        $this->assertSame('paid', $order->fresh()->status->value);
        $this->assertSame('completed', PaymentAttempt::sole()->status->value);
    }

    public function test_webhook_verifies_hmac_and_deduplicates_deliveries(): void
    {
        [$order] = $this->makeOrder();
        Http::fake(['*/admin/api/*' => Http::response($this->receiptResponse($order))]);
        $this->fakeStorefront($order);
        $this->createSessionFor($order);
        $this->guest($order)->postJson("/orders/{$order->number}/payments/shop-pay/submit", [
            'session_token' => 'session-token-1', 'payment_method' => 'pm-1',
        ])->assertOk();
        $payload = json_encode(['source_identifier' => 'shop-pay:'.$order->number]);

        $this->call('POST', '/webhooks/shopify/orders', [], [], [], [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => 'invalid',
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => (string) Str::uuid(),
            'HTTP_X_SHOPIFY_TOPIC' => 'orders/create',
        ], $payload)->assertStatus(401);

        $deliveryId = (string) Str::uuid();
        $headers = [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $payload, 'secret-app-client', true)),
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => $deliveryId,
            'HTTP_X_SHOPIFY_TOPIC' => 'orders/create',
        ];
        $this->call('POST', '/webhooks/shopify/orders', [], [], [], $headers, $payload)->assertOk();
        $this->call('POST', '/webhooks/shopify/orders', [], [], [], $headers, $payload)->assertOk();

        $this->assertSame(1, ShopifyWebhookDelivery::count());
        $this->assertSame(1, collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->url(), '/admin/api/'))->count());
        $this->assertSame('paid', $order->fresh()->status->value);
    }

    public function test_reconcile_marks_paid_only_on_exact_completed_receipt(): void
    {
        [$order] = $this->makeOrder();
        Http::fakeSequence('*/admin/api/*')
            ->push($this->receiptResponse($order, 'PROCESSING'))
            ->push($this->receiptResponse($order, 'COMPLETED', '1.00'))
            ->push($this->receiptResponse($order, 'COMPLETED', null, 'USD'))
            ->push($this->receiptResponse($order));
        $this->fakeStorefront($order);
        $this->createSessionFor($order);
        $this->guest($order)->postJson("/orders/{$order->number}/payments/shop-pay/submit", [
            'session_token' => 'session-token-1', 'payment_method' => 'pm-1',
        ])->assertOk();
        $route = "/orders/{$order->number}/payments/shop-pay/reconcile";

        $this->guest($order)->postJson($route)->assertOk()->assertJsonPath('paid', false);
        $this->assertSame('pending_payment', $order->fresh()->status->value);

        $this->guest($order)->postJson($route)->assertOk()->assertJsonPath('paid', false);
        $this->assertSame('pending_payment', $order->fresh()->status->value);
        $this->assertSame('Receipt does not match the local order.', PaymentSession::sole()->last_error);

        $this->guest($order)->postJson($route)->assertOk()->assertJsonPath('paid', false);
        $this->assertSame('pending_payment', $order->fresh()->status->value);

        $this->guest($order)->postJson($route)->assertOk()->assertJsonPath('paid', true);
        $order->refresh();
        $this->assertSame('paid', $order->status->value);
        $session = PaymentSession::sole();
        $this->assertSame('paid', $session->status->value);
        $this->assertSame('gid://shopify/Order/777', $session->provider_order_id);
        $this->assertNotNull($session->paid_at);
    }

    public function test_reconcile_completes_only_the_matching_attempt(): void
    {
        [$order] = $this->makeOrder();
        Http::fake([
            '*/admin/api/*' => Http::response(
                $this->receiptResponse($order, 'COMPLETED', null, 'EUR', 'receipt-old'),
            ),
            self::SHOP.'/api/2026-07/graphql.json' => Http::sequence()
                ->push($this->sessionResponse($order))
                ->push($this->submitResponse('receipt-old'))
                ->push($this->submitResponse('receipt-new')),
        ]);
        $this->createSessionFor($order);
        $url = "/orders/{$order->number}/payments/shop-pay/submit";
        $this->guest($order)->postJson($url, [
            'session_token' => 'session-token-1', 'payment_method' => 'pm-old',
        ])->assertOk();
        PaymentSession::sole()->forceFill(['status' => 'ready'])->save();
        $this->guest($order)->postJson($url, [
            'session_token' => 'session-token-1', 'payment_method' => 'pm-new',
        ])->assertOk();

        $this->guest($order)->postJson("/orders/{$order->number}/payments/shop-pay/reconcile")
            ->assertOk()->assertJsonPath('paid', true);

        $attempts = PaymentAttempt::orderBy('id')->get();
        $this->assertCount(2, $attempts);
        $this->assertSame('completed', $attempts[0]->status->value);
        $this->assertSame('processing', $attempts[1]->status->value);
        $this->assertSame('paid', PaymentSession::sole()->status->value);
    }

    public function test_admin_receipt_query_quotes_source_identifier(): void
    {
        [$order] = $this->makeOrder();
        Http::fake(['*/admin/api/*' => Http::response($this->receiptResponse($order))]);
        $this->fakeStorefront($order);
        $this->createSessionFor($order);

        $this->guest($order)->postJson("/orders/{$order->number}/payments/shop-pay/reconcile")->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/admin/api/')
            && $request->data()['variables']['query'] === "source_identifier:'shop-pay:{$order->number}'");
    }

    public function test_payment_request_amounts_are_consistent_and_include_shipping_address(): void
    {
        [$order] = $this->makeOrder();
        $this->fakeStorefront($order);
        $this->createSessionFor($order);
        $this->guest($order)->postJson("/orders/{$order->number}/payments/shop-pay/submit", [
            'session_token' => 'session-token-1', 'payment_method' => 'pm-1',
        ])->assertOk();

        $request = collect(Http::recorded())
            ->filter(fn ($pair) => str_contains($pair[0]->body(), 'shopPayPaymentRequestSessionSubmit'))
            ->values()->last()[0]->data()['variables']['paymentRequest'];

        $cents = function (array $money) {
            [$whole, $fraction] = array_pad(explode('.', $money['amount'], 2), 2, '0');

            return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
        };
        $lineSum = collect($request['lineItems'])->sum(fn ($line) => $cents($line['finalLinePrice']));
        $subtotal = $cents($request['subtotal']);
        $this->assertSame($subtotal, $lineSum);
        $this->assertSame(
            $cents($request['total']),
            $subtotal + $cents($request['totalShippingPrice']['finalTotal']) + $cents($request['totalTax']),
        );

        $this->assertSame('Test', $request['shippingAddress']['firstName']);
        $this->assertSame('Buyer', $request['shippingAddress']['lastName']);
        $this->assertSame('Test 1', $request['shippingAddress']['address1']);
        $this->assertSame('Berlin', $request['shippingAddress']['city']);
        $this->assertSame('10115', $request['shippingAddress']['postalCode']);
        $this->assertSame('DE', $request['shippingAddress']['countryCode']);
        $this->assertSame('buyer@example.test', $request['shippingAddress']['email']);
        $this->assertSame('123456789', $request['shippingAddress']['phone']);
    }

    public function test_create_session_rejects_mismatched_source_identifier(): void
    {
        [$order] = $this->makeOrder();
        $response = $this->sessionResponse($order);
        data_set(
            $response,
            'data.shopPayPaymentRequestSessionCreate.shopPayPaymentRequestSession.sourceIdentifier',
            'shop-pay:someone-else',
        );
        Http::fake(['*' => Http::response($response)]);

        $this->guest($order)->postJson("/orders/{$order->number}/payments/shop-pay/session")
            ->assertStatus(422);
        $this->assertDatabaseCount('payment_sessions', 0);
    }

    public function test_invalid_shop_id_disables_integration(): void
    {
        config()->set('services.shop_pay.shop_id', 'not-a-number');
        [$order] = $this->makeOrder();

        $this->guest($order)->get("/orders/{$order->number}")
            ->assertInertia(fn (Assert $page) => $page->where('shopPay.config', null));
        $this->guest($order)->postJson("/orders/{$order->number}/payments/shop-pay/session")
            ->assertStatus(503);
    }

    public function test_webhook_requires_enabled_config_and_secret(): void
    {
        $payload = json_encode(['source_identifier' => 'shop-pay:SP-1']);
        $send = fn (string $secret) => $this->call('POST', '/webhooks/shopify/orders', [], [], [], [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $payload, $secret, true)),
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => (string) Str::uuid(),
            'HTTP_X_SHOPIFY_TOPIC' => 'orders/create',
        ], $payload);

        config()->set('services.shop_pay.enabled', false);
        $send('secret-app-client')->assertStatus(503);

        config()->set('services.shop_pay.enabled', true);
        config()->set('services.shop_pay.app_client_secret', '');
        $send('')->assertStatus(503);

        $this->assertDatabaseCount('shopify_webhook_deliveries', 0);
    }

    public function test_webhook_failed_delivery_is_not_retained_and_can_retry(): void
    {
        [$order] = $this->makeOrder();
        Http::fake(['*/admin/api/*' => Http::sequence()
            ->push(['errors' => [['message' => 'Shopify unavailable']]], 500)
            ->push(['errors' => [['message' => 'Shopify unavailable']]], 500)
            ->push($this->receiptResponse($order))]);
        $this->fakeStorefront($order);
        $this->createSessionFor($order);
        $this->guest($order)->postJson("/orders/{$order->number}/payments/shop-pay/submit", [
            'session_token' => 'session-token-1', 'payment_method' => 'pm-1',
        ])->assertOk();

        $payload = json_encode(['source_identifier' => 'shop-pay:'.$order->number]);
        $deliveryId = (string) Str::uuid();
        $headers = [
            'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $payload, 'secret-app-client', true)),
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => $deliveryId,
            'HTTP_X_SHOPIFY_TOPIC' => 'orders/create',
        ];

        $this->call('POST', '/webhooks/shopify/orders', [], [], [], $headers, $payload)->assertStatus(500);
        $this->assertDatabaseCount('shopify_webhook_deliveries', 0);
        $this->assertSame('pending_payment', $order->fresh()->status->value);

        $this->call('POST', '/webhooks/shopify/orders', [], [], [], $headers, $payload)->assertOk();
        $delivery = ShopifyWebhookDelivery::sole();
        $this->assertSame($deliveryId, $delivery->delivery_id);
        $this->assertNotNull($delivery->processed_at);
        $this->assertSame('paid', $order->fresh()->status->value);
    }
}
