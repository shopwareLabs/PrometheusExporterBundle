<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Tests\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Shopware\PrometheusExporter\Metrics\MetricProviderInterface;
use Shopware\PrometheusExporter\Metrics\MetricsCollector;

/**
 * @internal
 */
#[CoversClass(MetricsCollector::class)]
class MetricsCollectorTest extends TestCase
{
    private CollectorRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new CollectorRegistry(new InMemory(), registerDefaultMetrics: false);
    }

    public function testRendersStoredAndScrapeTimeMetricsInOnePass(): void
    {
        $this->registry->getOrRegisterCounter('', 'stored_metric', 'stored')->incBy(3);

        $provider = new class implements MetricProviderInterface {
            public function collect(CollectorRegistry $registry): void
            {
                $registry->getOrRegisterGauge('', 'local_metric', 'scrape-time')->set(1.0);
            }
        };

        $output = $this->createCollector(providers: [$provider])->render();

        static::assertStringContainsString('stored_metric 3', $output);
        static::assertStringContainsString('local_metric 1', $output);
    }

    public function testScrapeTimeSamplesAreNotWrittenToTheStorage(): void
    {
        $provider = new class implements MetricProviderInterface {
            public function collect(CollectorRegistry $registry): void
            {
                $registry->getOrRegisterGauge('', 'local_metric', 'scrape-time')->set(1.0);
            }
        };

        $this->createCollector(providers: [$provider])->render();

        static::assertSame([], $this->registry->getMetricFamilySamples());
    }

    public function testNamespacePrefixesProviderMetricsButNotStoredOnes(): void
    {
        $this->registry->getOrRegisterCounter('', 'stored_metric', 'stored')->incBy(3);

        $provider = new class implements MetricProviderInterface {
            public function collect(CollectorRegistry $registry): void
            {
                $registry->getOrRegisterGauge('', 'local_metric', 'scrape-time', ['shard'])->set(1.0, ['a']);
                $registry->getOrRegisterHistogram('', 'local_duration', 'scrape-time', [], [1.0, 5.0])->observe(2.5);
            }
        };

        $output = $this->createCollector(providers: [$provider], namespace: 'shopware')->render();

        static::assertStringContainsString('stored_metric 3', $output, 'stored metrics are namespaced by the transport, not here');
        static::assertStringContainsString('shopware_local_metric{shard="a"} 1', $output);
        static::assertStringContainsString('shopware_local_duration_bucket{le="5"} 1', $output);
        static::assertStringContainsString('shopware_local_duration_count 1', $output);
        static::assertStringContainsString('shopware_local_duration_sum 2.5', $output);
        static::assertStringNotContainsString("\nlocal_metric", $output);
    }

    public function testFailingProviderDoesNotBreakTheScrape(): void
    {
        $failing = new class implements MetricProviderInterface {
            public function collect(CollectorRegistry $registry): void
            {
                throw new \RuntimeException('boom');
            }
        };
        $working = new class implements MetricProviderInterface {
            public function collect(CollectorRegistry $registry): void
            {
                $registry->getOrRegisterGauge('', 'local_metric', 'scrape-time')->set(1.0);
            }
        };

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $output = $this->createCollector(providers: [$failing, $working], logger: $logger)->render();

        static::assertStringContainsString('local_metric 1', $output);
    }

    /**
     * @param list<MetricProviderInterface> $providers
     */
    private function createCollector(array $providers = [], ?LoggerInterface $logger = null, string $namespace = ''): MetricsCollector
    {
        return new MetricsCollector($this->registry, $providers, $logger ?? new NullLogger(), $namespace);
    }
}
