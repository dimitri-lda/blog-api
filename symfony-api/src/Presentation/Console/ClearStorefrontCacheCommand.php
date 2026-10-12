<?php

namespace App\Presentation\Console;

use App\Infrastructure\Cache\StorefrontCache;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'storefront:cache-clear',
    description: 'Rotate the storefront tree, list and card cache generations.',
)]
final class ClearStorefrontCacheCommand extends Command
{
    public function __construct(private readonly StorefrontCache $cache)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->cache->flush();
        $output->writeln('Storefront cache generations rotated.');

        return Command::SUCCESS;
    }
}
