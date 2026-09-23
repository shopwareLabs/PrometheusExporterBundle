<?php declare(strict_types=1);

namespace Shopware\PrometheusExporter\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;

/**
 * Maps the semantic bundle configuration to container parameters.
 *
 * Service definitions are NOT loaded here: Shopware's Bundle::build() already loads
 * Resources/config/services.php into the main container. Anything conditional on the
 * configuration (e.g. the scrape-provider toggles) must happen in a compiler pass.
 *
 * @internal
 */
class PrometheusExporterExtension extends Extension
{
    /**
     * @param array<mixed> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->setParameter('prometheus_exporter.storage.redis_connection_name', $config['storage']['redis_connection_name']);
        $container->setParameter('prometheus_exporter.storage.dsn', $config['storage']['dsn']);
        $container->setParameter('prometheus_exporter.storage.key_prefix', $config['storage']['key_prefix']);
        $container->setParameter('prometheus_exporter.transport.write_mode', $config['transport']['write_mode']);
        $container->setParameter('prometheus_exporter.endpoint.allowed_ips', $config['endpoint']['allowed_ips']);
        $container->setParameter('prometheus_exporter.endpoint.auth_token', $config['endpoint']['auth_token']);
        $container->setParameter('prometheus_exporter.scrape_providers', $config['scrape_providers']);
    }

    public function getAlias(): string
    {
        return 'prometheus_exporter';
    }
}
