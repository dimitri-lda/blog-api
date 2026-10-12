<?php

namespace App\Infrastructure\Persistence\Doctrine\Commerce;

use App\Application\Commerce\ExchangeRateStore;
use App\Application\Commerce\RateQuote;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Psr\Clock\ClockInterface;

final readonly class DbalExchangeRateStore implements ExchangeRateStore
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    public function find(string $currency): ?RateQuote
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
SELECT rate, source, quoted_at
FROM exchange_rates
WHERE currency = :currency
ORDER BY quoted_at DESC
LIMIT 1
SQL,
            ['currency' => $currency],
        );
        if ($row === false) {
            return null;
        }

        return new RateQuote(
            (float) $row['rate'],
            (string) $row['source'],
            new DateTimeImmutable((string) $row['quoted_at']),
        );
    }

    public function save(string $currency, RateQuote $quote): void
    {
        $now = $this->clock->now();
        $this->connection->executeStatement(
            <<<'SQL'
INSERT INTO exchange_rates (currency, rate, source, quoted_at, created_at, updated_at)
VALUES (:currency, :rate, :source, :quoted_at, :created_at, :updated_at)
ON CONFLICT (currency) DO UPDATE SET
    rate = EXCLUDED.rate,
    source = EXCLUDED.source,
    quoted_at = EXCLUDED.quoted_at,
    updated_at = EXCLUDED.updated_at
SQL,
            [
                'currency' => $currency,
                'rate' => (string) $quote->rate,
                'source' => $quote->source,
                'quoted_at' => $quote->quotedAt,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'quoted_at' => Types::DATETIME_IMMUTABLE,
                'created_at' => Types::DATETIME_IMMUTABLE,
                'updated_at' => Types::DATETIME_IMMUTABLE,
            ],
        );
    }
}
