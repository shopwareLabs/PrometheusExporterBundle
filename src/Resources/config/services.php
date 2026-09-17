<?php declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Prometheus\CollectorRegistry;
use Shopware\Core\Framework\Adapter\Cache\RedisConnectionFactory;
use Shopware\Core\Framework\Adapter\Redis\RedisConnectionProvider;
use Shopware\PrometheusExporter\Command\ClearStorageCommand;
use Shopware\PrometheusExporter\Command\TestMetricsCommand;
use Shopware\PrometheusExporter\Controller\MetricsController;
use Shopware\PrometheusExporter\Metrics\MetricsCollector;
use Shopware\PrometheusExporter\Metrics\OpenSearchMetricProvider;
use Shopware\PrometheusExporter\Metrics\PHPFPMMetricProvider;
use Shopware\PrometheusExporter\Metrics\PHPInfoMetricProvider;
use Shopware\PrometheusExporter\Storage\RegistryFactory;
use Shopware\PrometheusExporter\Telemetry\PrometheusTransportFactory;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Storage
    $services->set(RegistryFactory::class)
        ->args([
            service(RedisConnectionProvider::class),
            service(RedisConnectionFactory::class),
            param('prometheus_exporter.storage.redis_connection_name'),
            param('prometheus_exporter.storage.dsn'),
            param('prometheus_exporter.storage.key_prefix'),
        ]);

    $services->set('prometheus_exporter.collector_registry', CollectorRegistry::class)
        ->lazy()
        ->factory([service(RegistryFactory::class), 'create']);

    // Telemetry transport
    $services->set(PrometheusTransportFactory::class)
        ->args([
            service('prometheus_exporter.collector_registry'),
            param('prometheus_exporter.transport.write_mode'),
        ])
        ->tag('shopware.metric_transport_factory');

    // Metrics collection & rendering
    $services->set(MetricsCollector::class)
        ->args([
            service('prometheus_exporter.collector_registry'),
            tagged_iterator('shopware.prometheus.metrics'),
            service('logger'),
        ]);

    // Controller
    $services->set(MetricsController::class)
        ->public()
        ->args([
            service(MetricsCollector::class),
            param('prometheus_exporter.endpoint.allowed_ips'),
            param('prometheus_exporter.endpoint.auth_token'),
            service('logger'),
        ])
        ->tag('controller.service_arguments');

    // Scrape-time metric providers, opt-in per "provider" name via
    // prometheus_exporter.scrape_providers (applied in ScrapeProviderPass)
    $services->set(PHPInfoMetricProvider::class)
        ->tag('shopware.prometheus.metrics', ['provider' => 'opcache']);

    $services->set(PHPFPMMetricProvider::class)
        ->tag('shopware.prometheus.metrics', ['provider' => 'php_fpm']);

    $services->set(OpenSearchMetricProvider::class)
        ->args([service('service_container')])
        ->tag('shopware.prometheus.metrics', ['provider' => 'opensearch']);

    // Commands
    $services->set(ClearStorageCommand::class)
        ->args([service('prometheus_exporter.collector_registry')])
        ->tag('console.command');

    $services->set(TestMetricsCommand::class)
        ->args([service(MetricsController::class)])
        ->tag('console.command');
};
