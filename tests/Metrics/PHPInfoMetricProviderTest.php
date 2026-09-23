<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Tests\Metrics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;
use Shopware\PrometheusExporter\Metrics\PHPInfoMetricProvider;

/**
 * @internal
 */
#[CoversClass(PHPInfoMetricProvider::class)]
class PHPInfoMetricProviderTest extends TestCase
{
    public function testCollectsPhpVersionInfo(): void
    {
        $registry = new CollectorRegistry(new InMemory(), registerDefaultMetrics: false);

        (new PHPInfoMetricProvider())->collect($registry);

        foreach ($registry->getMetricFamilySamples() as $family) {
            if ($family->getName() === 'php_version_info') {
                $sample = $family->getSamples()[0];
                static::assertSame(['version', 'major', 'minor', 'patch', 'sapi', 'zts', 'debug'], $family->getLabelNames());
                static::assertContains(\PHP_VERSION, $sample->getLabelValues());
                static::assertSame(1.0, (float) $sample->getValue());

                return;
            }
        }

        static::fail('php_version_info metric not collected');
    }
}
