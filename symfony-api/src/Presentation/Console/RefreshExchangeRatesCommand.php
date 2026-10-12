<?php

namespace App\Presentation\Console;

use App\Infrastructure\Commerce\ExchangeRateService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'commerce:refresh-exchange-rates',
    description: 'Refresh EUR conversion rates used for checkout.',
)]
final class RefreshExchangeRatesCommand extends Command
{
    public function __construct(private readonly ExchangeRateService $rates)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $failed = false;
        foreach (['PLN', 'BYN'] as $currency) {
            try {
                $quote = $this->rates->refresh($currency);
                $output->writeln("{$currency}: {$quote->rate} ({$quote->source})");
            } catch (Throwable $exception) {
                $failed = true;
                $output->writeln("<error>{$currency}: {$exception->getMessage()}</error>");
            }
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
