<?php

namespace App\Presentation\Console;

use App\Application\Catalog\StorefrontCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'storefront:cache-warm',
    description: 'Warm categories, featured products and catalog pages.',
)]
final class WarmStorefrontCacheCommand extends Command
{
    public function __construct(
        private readonly StorefrontCatalog $catalog,
        private readonly array $locales,
        private readonly int $defaultPages,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('pages', null, InputOption::VALUE_REQUIRED)
            ->addOption('locale', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pages = max(1, (int) ($input->getOption('pages') ?: $this->defaultPages));
        $locales = $input->getOption('locale') ?: $this->locales;

        foreach ($locales as $locale) {
            $this->catalog->categories($locale);
            $this->catalog->featured($locale);
            for ($page = 1; $page <= $pages; ++$page) {
                $this->catalog->page(
                    ['q' => null, 'category' => null, 'sort' => null],
                    $page,
                    12,
                    $locale,
                );
            }
        }
        $output->writeln('Storefront cache warmed for '.implode(', ', $locales).'.');

        return Command::SUCCESS;
    }
}
