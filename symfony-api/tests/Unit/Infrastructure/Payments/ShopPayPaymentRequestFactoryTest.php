<?php

namespace App\Tests\Unit\Infrastructure\Payments;

use App\Domain\Orders\ValueObjects\DeliveryMethod;
use App\Infrastructure\Payments\ShopPayConfiguration;
use App\Infrastructure\Payments\ShopPayPaymentRequestFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ShopPayPaymentRequestFactoryTest extends TestCase
{
    #[Test]
    public function storefront_amounts_are_decimal_consistent_and_include_shipping_address(): void
    {
        $request = $this->factory()->storefront($this->order(), 'pm-test');

        self::assertSame('pm-test', $request['paymentMethod']);
        self::assertSame('100.00', $request['subtotal']['amount']);
        self::assertSame('21.46', $request['totalTax']['amount']);
        self::assertSame('12.90', $request['totalShippingPrice']['finalTotal']['amount']);
        self::assertSame('134.36', $request['total']['amount']);
        self::assertSame('SKU-42', $request['lineItems'][0]['sku']);
        self::assertSame('Test', $request['shippingAddress']['firstName']);
        self::assertSame('DE', $request['shippingAddress']['countryCode']);
    }

    #[Test]
    public function frontend_payload_uses_numeric_amounts_and_delivery_quotes_use_frozen_order_values(): void
    {
        $factory = $this->factory();
        $request = $factory->frontend($this->order());
        $standard = $factory->deliveryQuote($this->order(), DeliveryMethod::Standard);
        $express = $factory->deliveryQuote($this->order(), DeliveryMethod::Express);

        self::assertSame(134.36, $request['total']['amount']);
        self::assertSame(0, $standard['gross']);
        self::assertSame(1535, $express['gross']);
    }

    #[Test]
    public function configuration_is_enabled_only_when_every_required_value_is_valid(): void
    {
        $enabled = new ShopPayConfiguration(
            true,
            'shop.test',
            '2026-07',
            '123',
            'client',
            'storefront',
            'admin',
            'secret',
        );
        $invalid = new ShopPayConfiguration(
            true,
            'shop.test',
            '2026-07',
            'invalid',
            'client',
            'storefront',
            'admin',
            'secret',
        );

        self::assertTrue($enabled->enabled());
        self::assertSame(['shopId' => 123, 'clientId' => 'client'], $enabled->publicConfig());
        self::assertFalse($invalid->enabled());
        self::assertNull($invalid->publicConfig());
    }

    private function factory(): ShopPayPaymentRequestFactory
    {
        return new ShopPayPaymentRequestFactory(590, 1290, 5000);
    }

    private function order(): array
    {
        return [
            'number' => 'SP-TEST',
            'email' => 'buyer@example.test',
            'phone' => '123456789',
            'delivery_method' => 'express',
            'subtotal_cents' => 11900,
            'delivery_cents' => 1536,
            'total_cents' => 13436,
            'currency' => 'EUR',
            'locale' => 'en',
            'exchange_rate' => 1.0,
            'vat_rate_basis_points' => 1900,
            'net_subtotal_cents' => 10000,
            'tax_cents' => 1900,
            'delivery_net_cents' => 1290,
            'delivery_tax_cents' => 246,
            'items' => [[
                'product_variant_id' => 1,
                'name' => 'Shoes',
                'variant_name' => '42',
                'sku' => 'SKU-42',
                'net_unit_price_cents' => 10000,
                'unit_price_cents' => 11900,
                'quantity' => 1,
                'line_total_cents' => 11900,
                'tax_cents' => 1900,
            ]],
            'address' => [
                'first_name' => 'Test',
                'last_name' => 'Buyer',
                'line1' => 'Street 1',
                'line2' => null,
                'city' => 'Berlin',
                'postal_code' => '10115',
                'country' => 'DE',
            ],
        ];
    }
}
