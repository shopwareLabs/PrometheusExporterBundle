<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Command;

use Shopware\PrometheusExporter\Controller\MetricsController;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Simulates a scrape against the real controller (endpoint guards included) and prints
 * the response. By default the simulated request carries the configured auth_token and
 * a localhost client IP, i.e. it behaves like a legitimate local scrape; --ip and
 * --token exist to simulate other callers, including deliberately rejected ones.
 *
 * @internal
 */
#[AsCommand(
    name: 'prometheus:test-metrics',
    description: 'Test the Prometheus metrics endpoint output',
)]
class TestMetricsCommand extends Command
{
    public function __construct(
        private readonly MetricsController $metricsController,
        private readonly ?string $authToken,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('ip', null, InputOption::VALUE_REQUIRED, 'Client IP to simulate against the allowed_ips guard', '127.0.0.1')
            ->addOption('token', null, InputOption::VALUE_REQUIRED, 'Bearer token to send; defaults to the configured auth_token');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $server = ['REMOTE_ADDR' => (string) $input->getOption('ip')];
        $token = $input->getOption('token') ?? $this->authToken;

        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . (string) $token;
        }

        $request = Request::create('/api/_internal/prometheus', Request::METHOD_GET, server: $server);

        $response = $this->metricsController->metrics($request);
        $status = $response->getStatusCode();

        if ($status !== Response::HTTP_OK) {
            $guard = match ($status) {
                Response::HTTP_UNAUTHORIZED => 'the auth_token guard',
                Response::HTTP_FORBIDDEN => 'the allowed_ips guard',
                default => 'an unexpected response',
            };

            $io->error(\sprintf(
                'The metrics endpoint rejected the simulated request with status %d (%s). Use --ip and --token to simulate an allowed scraper.',
                $status,
                $guard,
            ));

            return Command::FAILURE;
        }

        $io->section('Prometheus Metrics Output');
        $io->writeln((string) $response->getContent());

        $io->success('Metrics endpoint is working correctly.');

        return Command::SUCCESS;
    }
}
