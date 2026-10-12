<?php

namespace App\Tests\Unit\Application\Commerce;

use App\Application\Commerce\ExchangeRateProvider;
use App\Application\Commerce\MarketContextFactory;
use App\Application\Commerce\PricingService;
use App\Application\Commerce\RateQuote;
use App\Domain\Orders\Entities\OrderLine;
use App\Domain\Orders\ValueObjects\DeliveryMethod;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PricingServiceTest extends TestCase
{
    #[Test]
    public function belarus_quote_converts_net_eur_and_adds_twenty_percent_vat(): void
    {
        $quote = $this->pricing(['BYN' => 3.0])->quote(
            [new OrderLine(1, 'Shoes', '42', 10_000, 1)],
            $this->markets()->make('BY'),
            DeliveryMethod::Standard,
        );

        self::assertSame('BYN', $quote['currency']);
        self::assertSame(30_000, $quote['net_subtotal_cents']);
        self::assertSame(6_000, $quote['tax_cents']);
        self::assertSame(36_000, $quote['subtotal_cents']);
        self::assertSame(0, $quote['delivery_cents']);
    }

    #[Test]
    public function eu_quote_keeps_eur_and_uses_destination_vat_rate(): void
    {
        $quote = $this->pricing(['EUR' => 1.0])->quote(
            [new OrderLine(1, 'Shoes', '42', 1_000, 1)],
            $this->markets()->make('DE'),
            DeliveryMethod::Standard,
        );

        self::assertSame('EUR', $quote['currency']);
        self::assertSame(1_190, $quote['subtotal_cents']);
        self::assertSame(190, $quote['tax_cents']);
        self::assertSame(702, $quote['delivery_cents']);
    }

    #[Test]
    public function display_price_rounds_each_money_boundary_half_up(): void
    {
        $price = $this->pricing(['PLN' => 4.3335])->displayPrice(
            999,
            $this->markets()->make('PL'),
        );

        self::assertSame(4_329, $price['net_cents']);
        self::assertSame(5_325, $price['gross_cents']);
        self::assertSame(996, $price['tax_cents']);
        self::assertSame('PLN', $price['currency']);
    }

    private function pricing(array $rates): PricingService
    {
        $provider = new class($rates) implements ExchangeRateProvider {
            public function __construct(private readonly array $rates)
            {
            }

            public function rate(string $currency): RateQuote
            {
                return new RateQuote(
                    $this->rates[$currency],
                    'test',
                    new DateTimeImmutable(),
                );
            }
        };

        return new PricingService($provider, 590, 1290, 5000);
    }

    private function markets(): MarketContextFactory
    {
        return new MarketContextFactory(
            'DE',
            [
                'BY' => ['code' => 'belarus', 'currency' => 'BYN', 'locale' => 'ru', 'vat' => 20.0],
                'PL' => ['code' => 'poland', 'currency' => 'PLN', 'locale' => 'pl', 'vat' => 23.0],
            ],
            ['DE' => 19.0, 'PL' => 23.0],
            ['en', 'ru', 'pl'],
        );
    }
}
