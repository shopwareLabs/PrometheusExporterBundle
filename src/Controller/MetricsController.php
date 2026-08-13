<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Controller;

use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;
use Psr\Log\LoggerInterface;
use Shopware\PrometheusExporter\Metrics\MetricProviderInterface;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * @internal
 */
#[Route(defaults: ['_routeScope' => ['api']])]
class MetricsController
{
    /**
     * @param iterable<MetricProviderInterface> $metricProviders
     * @param array<string> $allowedIps
     */
    public function __construct(
        private readonly CollectorRegistry $registry,
        private readonly iterable $metricProviders,
        private readonly array $allowedIps,
        private readonly ?string $authToken,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/api/_internal/prometheus', name: 'prometheus.metrics', methods: ['GET'], defaults: ['auth_required' => false])]
    public function metrics(Request $request): Response
    {
        // every configured check must pass; both unset means an open endpoint (warned below)
        if ($this->authToken !== null && !$this->isTokenValid($request)) {
            return new Response(null, Response::HTTP_UNAUTHORIZED);
        }

        if ($this->allowedIps !== [] && !$this->isIpAllowed($request)) {
            return new Response(null, Response::HTTP_FORBIDDEN);
        }

        if ($this->authToken === null && $this->allowedIps === []) {
            $this->logger->warning('The Prometheus metrics endpoint is reachable without auth_token or allowed_ips restriction.');
        }

        $samples = [...$this->registry->getMetricFamilySamples(), ...$this->collectScrapeTimeSamples()];

        return new Response(
            (new RenderTextFormat())->render($samples),
            Response::HTTP_OK,
            ['Content-Type' => RenderTextFormat::MIME_TYPE],
        );
    }

    /**
     * @return list<\Prometheus\MetricFamilySamples>
     */
    private function collectScrapeTimeSamples(): array
    {
        $localRegistry = new CollectorRegistry(new InMemory(), registerDefaultMetrics: false);

        foreach ($this->metricProviders as $provider) {
            try {
                $provider->collect($localRegistry);
            } catch (\Throwable $e) {
                $this->logger->warning('Prometheus scrape-time metric provider failed.', [
                    'provider' => $provider::class,
                    'exception' => $e,
                ]);
            }
        }

        return \array_values($localRegistry->getMetricFamilySamples());
    }

    private function isTokenValid(Request $request): bool
    {
        $header = (string) $request->headers->get('Authorization', '');
        if (!\str_starts_with($header, 'Bearer ')) {
            return false;
        }

        return \hash_equals((string) $this->authToken, \substr($header, \strlen('Bearer ')));
    }

    private function isIpAllowed(Request $request): bool
    {
        $clientIp = $request->getClientIp();

        return $clientIp !== null && IpUtils::checkIp($clientIp, $this->allowedIps);
    }
}
