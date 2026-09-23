<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Command;

use Prometheus\CollectorRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * @internal
 */
#[AsCommand(
    name: 'prometheus:clear-storage',
    description: 'Wipe all metric series from the Prometheus metric storage',
)]
class ClearStorageCommand extends Command
{
    public function __construct(private readonly CollectorRegistry $registry)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->registry->wipeStorage();

        (new SymfonyStyle($input, $output))->success('Prometheus metric storage wiped.');

        return Command::SUCCESS;
    }
}
