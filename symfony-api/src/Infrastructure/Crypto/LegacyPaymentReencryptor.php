<?php

namespace App\Infrastructure\Crypto;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use DomainException;
use Throwable;

final readonly class LegacyPaymentReencryptor
{
    private const FIELDS = [
        'payment_sessions' => ['session_token', 'receipt_token'],
        'payment_attempts' => ['payload', 'receipt_token'],
    ];

    public function __construct(
        private Connection $connection,
        private PaymentPayloadCodec $codec,
    ) {
    }

    public function run(bool $apply, int $batchSize): ReencryptionResult
    {
        if ($batchSize < 1 || $batchSize > 1000) {
            throw new DomainException('Batch size must be between 1 and 1000.');
        }

        $result = new ReencryptionResult();
        foreach (self::FIELDS as $table => $fields) {
            $this->processTable($table, $fields, $apply, $batchSize, $result);
        }

        return $result;
    }

    private function processTable(
        string $table,
        array $fields,
        bool $apply,
        int $batchSize,
        ReencryptionResult $result,
    ): void {
        $lastId = 0;
        do {
            $rows = $this->rows($table, $fields, $lastId, $batchSize);
            if ($rows === []) {
                break;
            }
            $lastId = (int) end($rows)['id'];
            if ($apply) {
                $this->connection->transactional(
                    fn () => $this->processRows($table, $fields, $rows, true, $result),
                );
            } else {
                $this->processRows($table, $fields, $rows, false, $result);
            }
        } while (count($rows) === $batchSize);
    }

    private function rows(string $table, array $fields, int $lastId, int $batchSize): array
    {
        $legacy = array_map(
            fn (string $field): string => "({$field} IS NOT NULL AND {$field} NOT LIKE 'v1:%')",
            $fields,
        );

        return $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT id, %s FROM %s WHERE id > :last_id AND (%s) ORDER BY id LIMIT :batch_size',
                implode(', ', $fields),
                $table,
                implode(' OR ', $legacy),
            ),
            ['last_id' => $lastId, 'batch_size' => $batchSize],
            ['last_id' => ParameterType::INTEGER, 'batch_size' => ParameterType::INTEGER],
        );
    }

    private function processRows(
        string $table,
        array $fields,
        array $rows,
        bool $apply,
        ReencryptionResult $result,
    ): void {
        foreach ($rows as $row) {
            $updates = [];
            foreach ($fields as $field) {
                $value = $row[$field];
                if ($value === null || str_starts_with((string) $value, 'v1:')) {
                    continue;
                }
                $context = "{$table}.{$field}:{$row['id']}";
                try {
                    $plaintext = $this->codec->decryptString((string) $value, $context);
                } catch (Throwable $exception) {
                    ++$result->unreadable;
                    if ($apply) {
                        throw new DomainException(
                            "Unable to decrypt {$table}.{$field} for row {$row['id']}.",
                            previous: $exception,
                        );
                    }

                    continue;
                }
                ++$result->readable;
                if ($apply) {
                    $updates[$field] = $this->codec->encryptString($plaintext, $context);
                    ++$result->rewritten;
                }
            }
            if ($updates !== []) {
                $this->connection->update($table, $updates, ['id' => (int) $row['id']]);
            }
        }
    }
}
