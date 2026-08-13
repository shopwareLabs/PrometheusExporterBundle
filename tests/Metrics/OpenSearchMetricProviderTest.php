<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Tests\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;
use Psr\Container\ContainerInterface;
use Shopware\PrometheusExporter\Metrics\OpenSearchMetricProvider;

/**
 * @internal
 */
#[CoversClass(OpenSearchMetricProvider::class)]
class OpenSearchMetricProviderTest extends TestCase
{
    public function testCollectsNothingWithoutSearchClient(): void
    {
        $container = self::createStub(ContainerInterface::class);
        $container->method('has')->willReturn(false);

        $registry = new CollectorRegistry(new InMemory(), registerDefaultMetrics: false);

        (new OpenSearchMetricProvider($container))->collect($registry);

        static::assertSame([], $registry->getMetricFamilySamples());
    }
}
