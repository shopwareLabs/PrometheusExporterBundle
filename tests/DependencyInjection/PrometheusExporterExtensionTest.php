<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Tests\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Shopware\PrometheusExporter\DependencyInjection\CompilerPass\ScrapeProviderPass;
use Shopware\PrometheusExporter\DependencyInjection\Configuration;
use Shopware\PrometheusExporter\DependencyInjection\PrometheusExporterExtension;
use Shopware\PrometheusExporter\Metrics\OpenSearchMetricProvider;
use Shopware\PrometheusExporter\Metrics\PHPFPMMetricProvider;
use Shopware\PrometheusExporter\Metrics\PHPInfoMetricProvider;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Compiler\MergeExtensionConfigurationPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * @internal
 */
#[CoversClass(PrometheusExporterExtension::class)]
#[CoversClass(Configuration::class)]
#[UsesClass(ScrapeProviderPass::class)]
class PrometheusExporterExtensionTest extends TestCase
{
    public function testMapsConfigurationToParameters(): void
    {
        $container = new ContainerBuilder();

        (new PrometheusExporterExtension())->load([
            [
                'storage' => ['redis_connection_name' => 'telemetry'],
                'scrape_providers' => ['php_fpm' => true],
            ],
        ], $container);

        static::assertSame('telemetry', $container->getParameter('prometheus_exporter.storage.redis_connection_name'));
        static::assertNull($container->getParameter('prometheus_exporter.storage.dsn'));
        static::assertSame('sw:metrics', $container->getParameter('prometheus_exporter.storage.key_prefix'));
        static::assertSame('buffered', $container->getParameter('prometheus_exporter.transport.write_mode'));
        static::assertSame(['127.0.0.1', '::1'], $container->getParameter('prometheus_exporter.endpoint.allowed_ips'));
        static::assertNull($container->getParameter('prometheus_exporter.endpoint.auth_token'));
        static::assertSame(['php_fpm' => true], $container->getParameter('prometheus_exporter.scrape_providers'));
    }

    public function testScrapeProvidersDefault(): void
    {
        $container = new ContainerBuilder();

        (new PrometheusExporterExtension())->load([
            ['storage' => ['dsn' => 'redis://localhost']],
        ], $container);

        static::assertSame([], $container->getParameter('prometheus_exporter.scrape_providers'));
    }

    public function testStorageMustBeConfigured(): void
    {
        $this->expectExceptionObject(new InvalidConfigurationException(
            'Invalid configuration for path "prometheus_exporter": storage: either "dsn" or "redis_connection_name" must be configured'
        ));

        (new PrometheusExporterExtension())->load([[]], new ContainerBuilder());
    }

    /**
     * Regression test for the toggle bug: Shopware's Bundle::build() loads
     * services.php into the main container while the extension runs against a temporary
     * one, so disabling providers at extension-load time silently did nothing. The toggles
     * must survive that double-load, which is why they are applied in ScrapeProviderPass.
     */
    public function testScrapeProviderTogglesSurviveTheShopwareBundleDoubleLoad(): void
    {
        $container = new ContainerBuilder();

        // Kernel::prepareContainer(): the extension is registered with its file config ...
        $container->registerExtension(new PrometheusExporterExtension());
        $container->loadFromExtension('prometheus_exporter', [
            'storage' => ['redis_connection_name' => 'telemetry'],
            'scrape_providers' => ['php_fpm' => true],
        ]);

        // ... and Shopware's Bundle::build() loads services.php into the main container.
        (new PhpFileLoader($container, new FileLocator(__DIR__ . '/../../src/Resources/config')))
            ->load('services.php');

        // compile() step 1: merge the extension's temporary container back in.
        (new MergeExtensionConfigurationPass())->process($container);
        // compile() step 2 (registered in PrometheusExporterBundle::build()): apply the toggles.
        (new ScrapeProviderPass())->process($container);

        static::assertTrue($container->hasDefinition(PHPFPMMetricProvider::class));
        static::assertFalse($container->hasDefinition(PHPInfoMetricProvider::class));
        static::assertFalse($container->hasDefinition(OpenSearchMetricProvider::class));
    }
}
