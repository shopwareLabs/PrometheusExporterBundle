<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Metrics;

use Prometheus\CollectorRegistry;
use Prometheus\MetricFamilySamples;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;
use Psr\Log\LoggerInterface;

/**
 * Merges the stored telemetry metrics with the scrape-time provider samples and renders
 * them in the Prometheus text exposition format.
 *
 * Provider metrics are namespaced centrally here (per prometheus_exporter.scrape_metrics_namespace,
 * resolved in ScrapeProviderPass), so providers always register bare subsystem names.
 *
 * @internal
 */
class MetricsCollector
{
    /**
     * @param iterable<MetricProviderInterface> $metricProviders
     * @param string $namespace already sanitized; '' renders provider metrics unprefixed
     */
    public function __construct(
        private readonly CollectorRegistry $registry,
        private readonly iterable $metricProviders,
        private readonly LoggerInterface $logger,
        private readonly string $namespace = '',
    ) {
    }

    public function render(): string
    {
        $samples = [...$this->registry->getMetricFamilySamples(), ...$this->collectScrapeTimeSamples()];

        return (new RenderTextFormat())->render($samples);
    }

    /**
     * @return list<MetricFamilySamples>
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

        return \array_map(
            $this->applyNamespace(...),
            \array_values($localRegistry->getMetricFamilySamples()),
        );
    }

    private function applyNamespace(MetricFamilySamples $family): MetricFamilySamples
    {
        if ($this->namespace === '') {
            return $family;
        }

        // sample names carry type suffixes (_bucket/_count/_sum), so prefixing each sample
        // name keeps histograms intact
        return new MetricFamilySamples([
            'name' => $this->namespace . '_' . $family->getName(),
            'type' => $family->getType(),
            'help' => $family->getHelp(),
            'labelNames' => $family->getLabelNames(),
            'samples' => \array_map(fn ($sample) => [
                'name' => $this->namespace . '_' . $sample->getName(),
                'labelNames' => $sample->getLabelNames(),
                'labelValues' => $sample->getLabelValues(),
                'value' => $sample->getValue(),
            ], $family->getSamples()),
        ]);
    }
}
