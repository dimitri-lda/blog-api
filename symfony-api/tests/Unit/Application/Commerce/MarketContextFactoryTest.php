<?php

namespace App\Tests\Unit\Application\Commerce;

use App\Application\Commerce\MarketContextFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MarketContextFactoryTest extends TestCase
{
    #[Test]
    public function poland_uses_polish_currency_tax_and_locale(): void
    {
        $context = $this->factory()->make('pl');

        self::assertSame('PL', $context->country);
        self::assertSame('poland', $context->market);
        self::assertSame('PLN', $context->currency);
        self::assertSame('pl', $context->locale);
        self::assertSame(23.0, $context->vatRate);
    }

    #[Test]
    public function unsupported_country_falls_back_and_a_supported_locale_can_override_market_locale(): void
    {
        $context = $this->factory()->make('XX', 'ru');

        self::assertSame('DE', $context->country);
        self::assertSame('eu_global', $context->market);
        self::assertSame('EUR', $context->currency);
        self::assertSame('ru', $context->locale);
        self::assertSame(19.0, $context->vatRate);
    }

    private function factory(): MarketContextFactory
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
