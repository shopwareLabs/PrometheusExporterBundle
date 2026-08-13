<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\DependencyInjection;

use Shopware\PrometheusExporter\Metrics\OpenSearchMetricProvider;
use Shopware\PrometheusExporter\Metrics\PHPFPMMetricProvider;
use Shopware\PrometheusExporter\Metrics\PHPInfoMetricProvider;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * @internal
 */
class PrometheusExporterExtension extends Extension
{
    private const SCRAPE_PROVIDERS = [
        'opcache' => PHPInfoMetricProvider::class,
        'php_fpm' => PHPFPMMetricProvider::class,
        'opensearch' => OpenSearchMetricProvider::class,
    ];

    /**
     * @param array<mixed> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        if ($config['storage']['dsn'] === null && $config['storage']['redis_connection_name'] === null) {
            throw new InvalidConfigurationException(
                'prometheus_exporter.storage: either "dsn" or "redis_connection_name" must be configured'
            );
        }

        $container->setParameter('prometheus_exporter.storage.redis_connection_name', $config['storage']['redis_connection_name']);
        $container->setParameter('prometheus_exporter.storage.dsn', $config['storage']['dsn']);
        $container->setParameter('prometheus_exporter.storage.key_prefix', $config['storage']['key_prefix']);
        $container->setParameter('prometheus_exporter.transport.write_mode', $config['transport']['write_mode']);
        $container->setParameter('prometheus_exporter.endpoint.allowed_ips', $config['endpoint']['allowed_ips']);
        $container->setParameter('prometheus_exporter.endpoint.auth_token', $config['endpoint']['auth_token']);

        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.php');

        foreach (self::SCRAPE_PROVIDERS as $configKey => $providerClass) {
            if (!$config['scrape_providers'][$configKey]) {
                $container->removeDefinition($providerClass);
            }
        }
    }

    public function getAlias(): string
    {
        return 'prometheus_exporter';
    }
}
