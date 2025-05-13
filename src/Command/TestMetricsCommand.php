<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Command;

use Shopware\PrometheusExporter\Controller\MetricsController;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\Request;

#[AsCommand(
    name: 'prometheus:test-metrics',
    description: 'Test the Prometheus metrics endpoint output',
)]
class TestMetricsCommand extends Command
{
    public function __construct(
        private readonly MetricsController $metricsController
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        // Create a dummy Request with localhost IP
        $request = Request::create('/api/_internal/prometheus', 'GET');
        $request->server->set('REMOTE_ADDR', '127.0.0.1'); // Set localhost IP
        
        $response = $this->metricsController->metrics($request);
        
        $io->section('Prometheus Metrics Output');
        $io->writeln($response->getContent());
        
        $io->success('Metrics endpoint is working correctly.');
        
        return Command::SUCCESS;
    }
}