<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists every registered scrape-time metric provider — including disabled ones, which are
 * removed from the container and therefore invisible to debug:container — with its toggle
 * name, class (identifying who ships it), and enabled state.
 *
 * @internal
 */
#[AsCommand(
    name: 'prometheus:scrape-providers',
    description: 'List the available scrape-time metric providers and their toggle state',
)]
class ListScrapeProvidersCommand extends Command
{
    /**
     * @param array<string, string> $availableProviders provider name => class, collected by ScrapeProviderPass
     * @param array<string, bool> $configuredProviders the prometheus_exporter.scrape_providers toggles
     */
    public function __construct(
        private readonly array $availableProviders,
        private readonly array $configuredProviders,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->availableProviders === []) {
            $io->warning('No scrape-time metric providers are registered.');

            return Command::SUCCESS;
        }

        $rows = [];
        foreach ($this->availableProviders as $name => $class) {
            $rows[] = [$name, $class, ($this->configuredProviders[$name] ?? false) ? 'yes' : 'no'];
        }

        $io->table(['Provider', 'Class', 'Enabled'], $rows);
        $io->text('Providers are enabled by name under "prometheus_exporter.scrape_providers".');

        return Command::SUCCESS;
    }
}
