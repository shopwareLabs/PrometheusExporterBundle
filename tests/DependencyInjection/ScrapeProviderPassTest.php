<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\Tests\DependencyInjection;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\PrometheusExporter\Command\ListScrapeProvidersCommand;
use Shopware\PrometheusExporter\DependencyInjection\CompilerPass\ScrapeProviderPass;
use Shopware\PrometheusExporter\Metrics\OpenSearchMetricProvider;
use Shopware\PrometheusExporter\Metrics\PHPFPMMetricProvider;
use Shopware\PrometheusExporter\Metrics\PHPInfoMetricProvider;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\AbstractArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

/**
 * @internal
 */
#[CoversClass(ScrapeProviderPass::class)]
class ScrapeProviderPassTest extends TestCase
{
    public function testKeepsEnabledAndRemovesDisabledOrOmittedProviders(): void
    {
        $container = $this->container(['php_fpm' => true, 'opcache' => false]);
        $this->registerProvider($container, 'provider.php_fpm', 'php_fpm');
        $this->registerProvider($container, 'provider.opcache', 'opcache', PHPInfoMetricProvider::class);
        $this->registerProvider($container, 'provider.opensearch', 'opensearch', OpenSearchMetricProvider::class);

        (new ScrapeProviderPass())->process($container);

        static::assertTrue($container->hasDefinition('provider.php_fpm'));
        static::assertFalse($container->hasDefinition('provider.opcache'), 'explicitly disabled provider must be removed');
        static::assertFalse($container->hasDefinition('provider.opensearch'), 'omitted provider must stay opt-in');
    }

    public function testInjectsTheAvailableProviderMapIntoTheListCommand(): void
    {
        $container = $this->container(['php_fpm' => true]);
        $this->registerProvider($container, 'provider.php_fpm', 'php_fpm');
        $this->registerProvider($container, 'provider.opensearch', 'opensearch', OpenSearchMetricProvider::class);
        $container->register(ListScrapeProvidersCommand::class)
            ->setArguments([new AbstractArgument('injected by the pass'), []]);

        (new ScrapeProviderPass())->process($container);

        static::assertSame(
            [
                'php_fpm' => PHPFPMMetricProvider::class,
                'opensearch' => OpenSearchMetricProvider::class,
            ],
            $container->getDefinition(ListScrapeProvidersCommand::class)->getArgument(0),
            'the map must include providers that were removed as disabled',
        );
    }

    public function testAllProvidersAreRemovedWhenTheBundleIsNotConfigured(): void
    {
        $container = new ContainerBuilder();
        $this->registerProvider($container, 'provider.php_fpm', 'php_fpm');

        (new ScrapeProviderPass())->process($container);

        static::assertFalse($container->hasDefinition('provider.php_fpm'));
    }

    public function testUnknownProviderNameFailsTheBuild(): void
    {
        $container = $this->container(['apcu' => false]);
        $this->registerProvider($container, 'provider.php_fpm', 'php_fpm');
        $this->registerProvider($container, 'provider.opensearch', 'opensearch', OpenSearchMetricProvider::class);

        $this->expectExceptionObject(new InvalidConfigurationException(
            'Unknown scrape provider(s) "apcu" under prometheus_exporter.scrape_providers.'
            . ' Known providers: "php_fpm" (Shopware\PrometheusExporter\Metrics\PHPFPMMetricProvider),'
            . ' "opensearch" (Shopware\PrometheusExporter\Metrics\OpenSearchMetricProvider).'
        ));

        (new ScrapeProviderPass())->process($container);
    }

    public function testTagWithoutProviderNameFailsTheBuild(): void
    {
        $container = $this->container([]);
        $container->register('provider.unnamed', PHPFPMMetricProvider::class)
            ->addTag(ScrapeProviderPass::TAG);

        $this->expectExceptionObject(new InvalidArgumentException(
            'The "shopware.prometheus.metrics" tag on service "provider.unnamed" requires a "provider" attribute carrying the scrape provider name.'
        ));

        (new ScrapeProviderPass())->process($container);
    }

    public function testDuplicateProviderNameFailsTheBuild(): void
    {
        $container = $this->container([]);
        $this->registerProvider($container, 'provider.first', 'php_fpm');
        $this->registerProvider($container, 'provider.second', 'php_fpm', OpenSearchMetricProvider::class);

        $this->expectExceptionObject(new InvalidArgumentException(
            'Scrape provider name "php_fpm" is claimed by both "provider.first" and "provider.second"; provider names must be unique.'
        ));

        (new ScrapeProviderPass())->process($container);
    }

    public function testBareNamesAreReservedForBundleProviders(): void
    {
        $container = $this->container([]);
        $this->registerProvider($container, 'provider.foreign', 'acme', \stdClass::class);

        $this->expectExceptionObject(new InvalidArgumentException(
            'Scrape provider name "acme" on service "provider.foreign" (stdClass) is not allowed:'
            . ' bare names are reserved for built-in providers; use a vendor-prefixed name such as "<vendor>.acme".'
        ));

        (new ScrapeProviderPass())->process($container);
    }

    public function testVendorPrefixedForeignProviderToggles(): void
    {
        $container = $this->container(['acme.search' => true, 'acme.queue' => false]);
        $this->registerProvider($container, 'provider.acme_search', 'acme.search', \stdClass::class);
        $this->registerProvider($container, 'provider.acme_queue', 'acme.queue', \stdClass::class);

        (new ScrapeProviderPass())->process($container);

        static::assertTrue($container->hasDefinition('provider.acme_search'));
        static::assertFalse($container->hasDefinition('provider.acme_queue'));
    }

    /**
     * @param array<string, bool> $scrapeProviders
     */
    private function container(array $scrapeProviders): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('prometheus_exporter.scrape_providers', $scrapeProviders);

        return $container;
    }

    /**
     * @param class-string $class
     */
    private function registerProvider(
        ContainerBuilder $container,
        string $serviceId,
        string $name,
        string $class = PHPFPMMetricProvider::class,
    ): void {
        $container->register($serviceId, $class)
            ->addTag(ScrapeProviderPass::TAG, ['provider' => $name]);
    }
}
