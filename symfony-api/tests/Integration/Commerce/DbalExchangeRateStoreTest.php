<?php

namespace App\Tests\Integration\Commerce;

use App\Application\Commerce\RateQuote;
use App\Infrastructure\Persistence\Doctrine\Commerce\DbalExchangeRateStore;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class DbalExchangeRateStoreTest extends KernelTestCase
{
    private Connection $connection;

    private DbalExchangeRateStore $store;

    protected function setUp(): void
    {
        if (getenv('RUN_DB_TESTS') !== '1') {
            self::markTestSkipped('Set RUN_DB_TESTS=1 with an isolated PostgreSQL database.');
        }

        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->executeStatement('TRUNCATE TABLE exchange_rates RESTART IDENTITY');
        $this->store = new DbalExchangeRateStore(
            $this->connection,
            new MockClock('2026-01-01 12:00:00 UTC'),
        );
    }

    #[Test]
    public function save_inserts_and_updates_one_quote_per_currency(): void
    {
        $first = new RateQuote(4.12345678, 'first', new DateTimeImmutable('2026-01-01 10:00:00 UTC'));
        $second = new RateQuote(4.87654321, 'second', new DateTimeImmutable('2026-01-01 11:00:00 UTC'));

        $this->store->save('PLN', $first);
        self::assertEquals($first, $this->store->find('PLN'));

        $this->store->save('PLN', $second);
        self::assertEquals($second, $this->store->find('PLN'));
        self::assertSame(1, (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM exchange_rates WHERE currency = :currency',
            ['currency' => 'PLN'],
        ));
    }

    #[Test]
    public function find_returns_null_for_an_unknown_currency(): void
    {
        self::assertNull($this->store->find('BYN'));
    }
}
