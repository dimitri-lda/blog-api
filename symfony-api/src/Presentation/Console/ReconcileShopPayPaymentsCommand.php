<?php

namespace App\Presentation\Console;

use App\Application\Payments\ShopPayService;
use App\Domain\Payments\PaymentSessionStatus;
use App\Infrastructure\Payments\ShopPayConfiguration;
use App\Infrastructure\Persistence\Doctrine\Orders\DbalOrderReader;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'shop-pay:reconcile',
    description: 'Reconcile processing Shop Pay sessions with Shopify.',
)]
final class ReconcileShopPayPaymentsCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DbalOrderReader $orders,
        private readonly ShopPayService $payments,
        private readonly ShopPayConfiguration $configuration,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->configuration->enabled()) {
            $output->writeln('Shop Pay is not configured; skipping reconciliation.');

            return Command::SUCCESS;
        }

        $failed = false;
        $numbers = $this->connection->fetchFirstColumn(
            <<<'SQL'
SELECT o.number
FROM payment_sessions ps
INNER JOIN orders o ON o.id = ps.order_id
WHERE ps.status = :status
ORDER BY ps.id
SQL,
            ['status' => PaymentSessionStatus::Processing->value],
        );
        foreach ($numbers as $number) {
            try {
                $order = $this->orders->byNumber((string) $number);
                $paid = $order !== null && $this->payments->reconcile($order);
                $output->writeln("{$number}: ".($paid ? 'paid' : 'pending'));
            } catch (Throwable $exception) {
                $failed = true;
                $output->writeln("<error>{$number}: {$exception->getMessage()}</error>");
            }
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
