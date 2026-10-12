<?php

namespace App\Presentation\Console;

use App\Infrastructure\Crypto\LegacyPaymentReencryptor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'payments:reencrypt-legacy',
    description: 'Validate or re-encrypt legacy Laravel payment fields.',
)]
final class ReencryptLegacyPaymentsCommand extends Command
{
    public function __construct(private readonly LegacyPaymentReencryptor $reencryptor)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('apply', null, InputOption::VALUE_NONE)
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, '', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $result = $this->reencryptor->run(
            $apply,
            (int) $input->getOption('batch-size'),
        );
        $io->definitionList(
            ['Mode' => $apply ? 'apply' : 'dry-run'],
            ['Readable fields' => (string) $result->readable],
            ['Rewritten fields' => (string) $result->rewritten],
            ['Unreadable fields' => (string) $result->unreadable],
        );

        return $result->unreadable === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
