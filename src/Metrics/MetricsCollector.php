<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Metrics;

use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;
use Psr\Log\LoggerInterface;

/**
 * Merges the stored telemetry metrics with the scrape-time provider samples and renders
 * them in the Prometheus text exposition format.
 *
 * @internal
 */
class MetricsCollector
{
    /**
     * @param iterable<MetricProviderInterface> $metricProviders
     */
    public function __construct(
        private readonly CollectorRegistry $registry,
        private readonly iterable $metricProviders,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function render(): string
    {
        $samples = [...$this->registry->getMetricFamilySamples(), ...$this->collectScrapeTimeSamples()];

        return (new RenderTextFormat())->render($samples);
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
}
